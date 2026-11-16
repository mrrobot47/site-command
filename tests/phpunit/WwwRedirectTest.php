<?php

use EE\Site\Tests\HttpProxyRecorder;
use EE\Site\Tests\SiteTestCase;
use function EE\Site\Utils\add_site_redirects;
use function EE\Site\Utils\get_preferred_ssl_challenge;
use function EE\Site\Utils\get_ssl_policy;

/**
 * www/non-www handling: the LE domain set, the nginx-proxy redirect conf and the www reachability check.
 */
class WwwRedirectTest extends SiteTestCase {

	protected $reset_db = false;

	/** @var HttpProxyRecorder|null */
	private $proxy;

	protected function tearDown(): void {
		if ( $this->proxy ) {
			$this->proxy->stop();
			$this->proxy = null;
		}
		parent::tearDown();
	}

	/**
	 * @dataProvider cert_domain_cases
	 */
	public function test_get_cert_domains( $site_url, $wildcard, $www_or_non_www, $expected ) {
		$this->assertSame( $expected, $this->cert_domains( $site_url, $wildcard, $www_or_non_www ) );
	}

	public function cert_domain_cases() {
		return [
			'plain'           => [ 'a.test', false, false, [ 'a.test' ] ],
			'wildcard'        => [ 'a.test', true, true, [ 'a.test', '*.a.test' ] ],
			'wildcard as "1"' => [ 'a.test', '1', false, [ 'a.test', '*.a.test' ] ],
			'add www'         => [ 'a.test', false, true, [ 'a.test', 'www.a.test' ] ],
			'drop www'        => [ 'www.a.test', false, true, [ 'www.a.test', 'a.test' ] ],
		];
	}

	public function test_get_cert_domains_adds_www_for_the_dns_challenge() {
		$this->write_ee_config( [ 'preferred_ssl_challenge' => 'dns' ] );
		$this->assertSame( [ 'a.test', 'www.a.test' ], $this->cert_domains( 'a.test', false, false ) );
	}

	/**
	 * SC-1/PHPT-15/WPT-9: `ltrim( $site_url, 'www.' )` strips a character set, so `www.web.test` loses the `w` of `web`.
	 *
	 * @group known-bug
	 * @group SC-1
	 * @group PHPT-15
	 * @group WPT-9
	 * @dataProvider www_names_starting_with_w
	 */
	public function test_get_cert_domains_strips_only_the_www_prefix( $site_url, $non_www ) {
		$this->assertSame( [ $site_url, $non_www ], $this->cert_domains( $site_url, false, true ) );
	}

	public function www_names_starting_with_w() {
		return [
			'web'        => [ 'www.web.test', 'web.test' ],
			'w3schools'  => [ 'www.w3schools.test', 'w3schools.test' ],
			'single w'   => [ 'www.w.x.test', 'w.x.test' ],
			'wiki'       => [ 'www.wiki.test', 'wiki.test' ],
		];
	}

	public function test_get_preferred_ssl_challenge() {
		$this->assertSame( '', get_preferred_ssl_challenge( [ 'a.test', 'b.test' ] ) );
		$this->assertSame( 'dns', get_preferred_ssl_challenge( [ 'a.test', '*.a.test' ] ) );

		$this->write_ee_config( [ 'preferred_ssl_challenge' => 'http' ] );
		$this->assertSame( 'http', get_preferred_ssl_challenge( [ 'a.test' ] ) );
		$this->assertSame( 'dns', get_preferred_ssl_challenge( [ '*.a.test' ] ), 'A wildcard needs DNS-01 whatever the config says.' );
	}

	public function test_get_ssl_policy_falls_back_to_mozilla_modern() {
		$this->assertSame( 'Mozilla-Modern', get_ssl_policy() );
		$this->write_ee_config( [ 'ssl-policy' => 'AWS-TLS-1-2-2017-01' ] );
		$this->assertSame( 'AWS-TLS-1-2-2017-01', get_ssl_policy() );
		$this->write_ee_config( [ 'ssl-policy' => 'mozilla-modern; include /etc/passwd' ] );
		$this->assertSame( 'Mozilla-Modern', get_ssl_policy() );
	}

	public function test_redirect_conf_without_ssl_sends_www_to_the_site() {
		$conf = $this->redirect_conf( 'plain.test', false, false );

		$this->assertSame(
			"server {\n\tlisten  80;\n\tserver_name  www.plain.test;\n\treturn  301 http://plain.test\$request_uri;\n}\n",
			$conf
		);
	}

	public function test_redirect_conf_for_a_www_site_sends_the_bare_domain_to_it() {
		$this->assertStringContainsString( "server_name  site.test;\n\treturn  301 http://www.site.test\$request_uri;", $this->redirect_conf( 'www.site.test', false, false ) );
	}

	public function test_redirect_conf_with_ssl_adds_the_443_server_with_the_policy() {
		$this->write_certs( 'secure.test' );
		$conf = $this->redirect_conf( 'secure.test', true, false );

		$this->assertStringContainsString( 'return  301 https://secure.test$request_uri;', $conf );
		$this->assertSame( 2, substr_count( $conf, 'server_name  www.secure.test;' ) );
		$this->assertStringContainsString( "listen  443;\n\tssl_protocols TLSv1.2 TLSv1.3;", $conf );
		$this->assertStringContainsString( 'ssl_certificate /etc/nginx/certs/secure.test.crt;', $conf );
		$this->assertStringContainsString( 'ssl_certificate_key /etc/nginx/certs/secure.test.key;', $conf );
		$this->assertSame( 1, substr_count( $conf, 'ssl_protocols' ), 'Exactly one policy block is rendered.' );
	}

	public function test_redirect_conf_uses_the_configured_ssl_policy() {
		$this->write_ee_config( [ 'ssl-policy' => 'Mozilla-Old' ] );
		$this->write_certs( 'old.test' );
		$conf = $this->redirect_conf( 'old.test', true, false );

		$this->assertStringContainsString( 'ssl_protocols SSLv3 TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;', $conf );
		$this->assertSame( 1, substr_count( $conf, 'ssl_protocols' ) );
	}

	public function test_redirect_conf_of_an_inheriting_site_uses_the_parent_cert() {
		$this->write_certs( 'parent.test' );
		$conf = $this->redirect_conf( 'blog.parent.test', true, true );

		$this->assertStringContainsString( 'ssl_certificate /etc/nginx/certs/parent.test.crt;', $conf );
		$this->assertStringContainsString( 'server_name  www.blog.parent.test;', $conf );
	}

	public function test_redirect_conf_is_skipped_when_the_cert_is_missing() {
		$this->track( EE_SERVICE_DIR . '/nginx-proxy' );
		add_site_redirects( 'nocert.test', true, false );

		$this->assertFileDoesNotExist( EE_SERVICE_DIR . '/nginx-proxy/conf.d/nocert.test-redirect.conf' );
		$this->assertStringContainsString( 'SSL cert/key missing for nocert.test, skipping redirect config.', $this->stdout() );
	}

	/**
	 * SC-1: the redirect conf of `www.web.test` gets `server_name eb.test`.
	 *
	 * @group known-bug
	 * @group SC-1
	 * @group PHPT-15
	 * @group WPT-9
	 */
	public function test_redirect_conf_strips_only_the_www_prefix() {
		$this->assertStringContainsString( 'server_name  web.test;', $this->redirect_conf( 'www.web.test', false, false ) );
	}

	public function test_www_check_succeeds_when_the_other_name_serves_the_site() {
		$root = $this->site_root( 'reach.test' );
		$this->proxy = new HttpProxyRecorder( $this->sandbox(), "$root/app/htdocs", [ 'www.reach.test' ] );

		$this->assertTrue( $this->www_check( 'reach.test', $root, '/var/www/htdocs' ) );
		$this->assertSame( [ 'www.reach.test' ], $this->proxy->hosts() );
		$this->assertFileDoesNotExist( "$root/app/htdocs/check.html" );
	}

	public function test_www_check_fails_when_the_other_name_does_not_serve_the_site() {
		$root = $this->site_root( 'unpointed.test' );
		$this->proxy = new HttpProxyRecorder( $this->sandbox(), "$root/app/htdocs", [] );

		$this->assertFalse( $this->www_check( 'unpointed.test', $root, '/var/www/htdocs' ) );
		$this->assertSame( [ 'www.unpointed.test' ], $this->proxy->hosts() );
		$this->assertFileDoesNotExist( "$root/app/htdocs/check.html" );
	}

	public function test_www_check_of_a_www_site_uses_the_public_dir_and_the_bare_name() {
		$root = $this->site_root( 'www.pub.test', '/public' );
		$this->proxy = new HttpProxyRecorder( $this->sandbox(), "$root/app/htdocs/public", [ 'pub.test' ] );

		$this->assertTrue( $this->www_check( 'www.pub.test', $root, '/var/www/htdocs/public' ) );
		$this->assertSame( [ 'pub.test' ], $this->proxy->hosts() );
	}

	/**
	 * SC-1: the www check of `www.web.test` curls `eb.test`.
	 *
	 * @group known-bug
	 * @group SC-1
	 * @group PHPT-15
	 * @group WPT-9
	 */
	public function test_www_check_strips_only_the_www_prefix() {
		$root = $this->site_root( 'www.web.test' );
		$this->proxy = new HttpProxyRecorder( $this->sandbox(), "$root/app/htdocs", [ 'web.test' ] );

		$this->www_check( 'www.web.test', $root, '/var/www/htdocs' );
		$this->assertSame( [ 'web.test' ], $this->proxy->hosts() );
	}

	private function cert_domains( $site_url, $wildcard, $www_or_non_www ) {
		return self::call_private( $this->html_command(), 'get_cert_domains', $site_url, $wildcard, $www_or_non_www );
	}

	private function www_check( $site_url, $site_path, $container_path ) {
		return self::call_private( $this->html_command(), 'check_www_or_non_www_domain', $site_url, $site_path, $container_path );
	}

	private function redirect_conf( $site_url, $ssl, $inherit ) {
		$this->track( EE_SERVICE_DIR . '/nginx-proxy' );
		add_site_redirects( $site_url, $ssl, $inherit );
		$file = EE_SERVICE_DIR . "/nginx-proxy/conf.d/$site_url-redirect.conf";
		$this->assertFileExists( $file );

		return file_get_contents( $file );
	}

	private function write_certs( $name ) {
		$dir = $this->track( EE_SERVICE_DIR . '/nginx-proxy' ) . '/certs';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		touch( "$dir/$name.crt" );
		touch( "$dir/$name.key" );
	}

	private function site_root( $site_url, $public_dir = '' ) {
		$root = $this->track( WEBROOT . $site_url );
		mkdir( "$root/app/htdocs$public_dir", 0700, true );

		return $root;
	}
}
