<?php

use EE\Model\Site;
use EE\Site\Tests\SiteTestCase;
use Symfony\Component\Yaml\Yaml;
use function EE\Utils\mustache_render;

/**
 * Generated site files: docker-compose.yml, the nginx-proxy cache confs, main.conf, .env and php.ini edits.
 */
class TemplateTest extends SiteTestCase {

	protected function setUp(): void {
		parent::setUp();
		$this->track( EE_SERVICE_DIR . '/nginx-proxy' );
		$this->set_config( [ 'custom-compose' => null ] );
	}

	/** Render the site's docker-compose.yml through HTML::dump_docker_compose_yml() and parse it. */
	private function compose( array $site_data, array $filters = [] ) {
		$root = $this->track( WEBROOT . $site_data['site_url'] );
		mkdir( $root, 0700, true );
		$command = $this->html_command( $site_data + [ 'site_fs_path' => $root, 'site_type' => 'html', 'site_ssl' => '', 'alias_domains' => $site_data['site_url'] ] );
		$command->dump_docker_compose_yml( $filters );

		return Yaml::parse( file_get_contents( "$root/docker-compose.yml" ) );
	}

	public function test_compose_for_a_plain_html_site() {
		$this->runner->on( '/^docker volume ls /', "plaintest_htdocs\n" );
		$yaml  = $this->compose( [ 'site_url' => 'plain.test' ] );
		$nginx = $yaml['services']['nginx'];

		$versions = json_decode( file_get_contents( EE_ROOT . '/img-versions.json' ), true );
		$this->assertSame( 'easyengine/nginx:' . $versions['easyengine/nginx'], $nginx['image'] );
		$this->assertSame( 'always', $nginx['restart'] );
		$this->assertSame( [ 'VIRTUAL_HOST=${VIRTUAL_HOST}', 'VIRTUAL_PATH=/', 'HSTS=off' ], $nginx['environment'] );
		$this->assertSame( [ 'htdocs:/var/www', 'config_nginx:/usr/local/openresty/nginx/conf', 'log_nginx:/var/log/nginx' ], $nginx['volumes'] );
		$this->assertSame( [ 'io.easyengine.site=${VIRTUAL_HOST}' ], $nginx['labels'] );
		$this->assertSame( [ 'global-frontend-network', 'plain.test' ], array_keys( $nginx['networks'] ) );
		$this->assertArrayNotHasKey( 'sysctls', $nginx );
		$this->assertSame(
			[
				'htdocs'       => [ 'external' => true, 'name' => 'plaintest_htdocs' ],
				'config_nginx' => [ 'external' => true, 'name' => 'plaintest_config_nginx' ],
				'log_nginx'    => [ 'external' => true, 'name' => 'plaintest_log_nginx' ],
			],
			$yaml['volumes']
		);
		$this->assertSame(
			[
				'plain.test'              => [ 'name' => 'plain.test', 'labels' => [ 'org.label-schema.vendor=EasyEngine', 'io.easyengine.site=${VIRTUAL_HOST}' ] ],
				'global-frontend-network' => [ 'external' => true, 'name' => 'ee-global-frontend-network' ],
			],
			$yaml['networks']
		);
		$this->assertSame( [], $this->runner->matching( '/docker volume create/' ), 'Existing volumes are reused.' );
	}

	public function test_compose_with_aliases_and_no_https() {
		$this->runner->on( '/^docker volume ls /', "x\n" );
		$yaml = $this->compose( [ 'site_url' => 'alias.test', 'alias_domains' => 'alias.test,b.test,*.alias.test' ], [ 'nohttps' => true ] );

		$this->assertSame( [ 'VIRTUAL_HOST=${VIRTUAL_HOST},b.test,*.alias.test', 'VIRTUAL_PATH=/', 'HSTS=off', 'CERT_NAME=${VIRTUAL_HOST}', 'HTTPS_METHOD=nohttps' ], $yaml['services']['nginx']['environment'] );
	}

	public function test_compose_adds_the_ssl_policy_and_sysctls() {
		$this->runner->on( '/^docker volume ls /', "x\n" );
		$this->write_ee_config( [ 'ssl-policy' => 'Mozilla-Intermediate' ] );
		$this->set_config( [ 'sysctl' => 'true' ] );
		$nginx = $this->compose( [ 'site_url' => 'policy.test' ] )['services']['nginx'];

		$this->assertContains( 'SSL_POLICY=Mozilla-Intermediate', $nginx['environment'] );
		$this->assertCount( 9, $nginx['sysctls'] );
		$this->assertContains( 'net.ipv4.ip_local_port_range=2000 65535', $nginx['sysctls'] );
	}

	public function test_compose_creates_the_site_volumes_once() {
		$this->runner->on( '/^docker volume inspect /', '', 1 );
		$this->compose( [ 'site_url' => 'vol.test' ] );

		$created = array_map(
			function ( $command ) {
				preg_match( '/\s\'?([a-z_]+)\'?$/', trim( $command ), $m );

				return $m[1];
			},
			$this->runner->matching( '/^docker volume create /' )
		);
		$this->assertSame( [ 'voltest_htdocs', 'voltest_config_nginx', 'voltest_log_nginx' ], $created );
	}

	public function test_proxy_cache_on_writes_the_proxy_and_location_confs() {
		$site = $this->create_site( 'pc.test', [ 'alias_domains' => 'pc.test,shop.test,*.pc.test', 'app_sub_type' => 'subdom' ] );
		$this->update_proxy_cache( $site, [ 'proxy-cache' => 'on', 'proxy-cache-max-size' => '1G', 'proxy-cache-max-time' => '30s' ] );

		$proxy = EE_SERVICE_DIR . '/nginx-proxy';
		$this->assertStringEqualsFile( "$proxy/conf.d/pc.test.conf", "proxy_cache_path /var/cache/nginx/pc.test levels=1:2 keys_zone=pc-test:10m max_size=1g;\n" );
		$location = file_get_contents( "$proxy/vhost.d/pc.test_location" );
		$this->assertStringContainsString( "proxy_cache pc-test;\nproxy_cache_valid 200 301 302 404 30s;\n", $location );
		$this->assertStringContainsString( 'add_header X-Powered-By "EasyEngine v' . EE_VERSION . '";', $location );
		$this->assertStringEqualsFile( "$proxy/vhost.d/shop.test_location", $location );
		$this->assertStringEqualsFile( "$proxy/vhost.d/*.pc.test_location", $location );
		$this->assertSame( 'on', Site::find( 'pc.test' )->proxy_cache );
		$this->assertSame( [ 'docker restart services_global-nginx-proxy_1' ], $this->runner->matching( '/^docker restart/' ) );
	}

	public function test_proxy_cache_replaces_invalid_sizes_with_defaults() {
		$site = $this->create_site( 'bad.test' );
		$this->update_proxy_cache( $site, [ 'proxy-cache' => 'on', 'proxy-cache-max-size' => '5x', 'proxy-cache-max-time' => '10h', 'proxy-cache-key-zone-size' => '1g' ] );

		$this->assertStringContainsString( 'Wrong value `10h` supplied to param: `proxy-cache-max-time`, replacing it with default:1s', $this->stderr() );
		$this->assertStringContainsString( 'Wrong value `5x` supplied to param: `proxy-cache-max-size`, replacing it with default:256m', $this->stderr() );
		$this->assertStringContainsString( 'Wrong value `1g` supplied to param: `proxy-cache-key-zone-size`, replacing it with default:10m', $this->stderr() );
		$this->assertStringEqualsFile( EE_SERVICE_DIR . '/nginx-proxy/conf.d/bad.test.conf', "proxy_cache_path /var/cache/nginx/bad.test levels=1:2 keys_zone=bad-test:10m max_size=256m;\n" );
	}

	public function test_proxy_cache_confs_are_removed_when_nginx_rejects_them() {
		$site = $this->create_site( 'reject.test' );
		$this->runner->on( '/sh -c "nginx -t"/', '', 1 );
		$this->assert_ee_error(
			function () use ( $site ) {
				$this->update_proxy_cache( $site, [ 'proxy-cache' => 'on' ] );
			},
			'The previous proxy config was restored'
		);

		$this->assertFileDoesNotExist( EE_SERVICE_DIR . '/nginx-proxy/conf.d/reject.test.conf' );
		$this->assertFileDoesNotExist( EE_SERVICE_DIR . '/nginx-proxy/vhost.d/reject.test_location' );
		$this->assertSame( [], $this->runner->matching( '/^docker restart/' ) );
	}

	public function test_proxy_cache_off_removes_the_confs_and_purges_the_cache() {
		$site = $this->create_site( 'off.test', [ 'proxy_cache' => 'on', 'alias_domains' => 'off.test,a.off.test' ] );
		foreach ( [ 'conf.d/off.test.conf', 'vhost.d/off.test_location', 'vhost.d/a.off.test_location' ] as $file ) {
			$path = EE_SERVICE_DIR . "/nginx-proxy/$file";
			@mkdir( dirname( $path ), 0700, true );
			touch( $path );
		}

		$this->update_proxy_cache( $site, [ 'proxy-cache' => 'off' ] );

		$this->assertSame( [], glob( EE_SERVICE_DIR . '/nginx-proxy/{conf,vhost}.d/*off.test*', GLOB_BRACE ) );
		$this->assertSame( [ "docker exec services_global-nginx-proxy_1 bash -c 'rm -rf /var/cache/nginx/off.test'" ], $this->runner->matching( '/rm -rf/' ) );
		$this->assertSame( 'off', Site::find( 'off.test' )->proxy_cache );
	}

	public function test_proxy_cache_refuses_the_current_state() {
		$site = $this->create_site( 'same.test', [ 'proxy_cache' => 'on' ] );
		$this->assert_ee_error(
			function () use ( $site ) {
				$this->update_proxy_cache( $site, [ 'proxy-cache' => 'on' ] );
			},
			'Site same.test already has proxy cache: on'
		);
	}

	/**
	 * SC-18: `--proxy-cache` isn't validated, so any value other than `on` disables the cache and restarts the proxy.
	 *
	 * @group known-bug
	 * @group SC-18
	 */
	public function test_update_rejects_an_invalid_proxy_cache_value() {
		$this->create_site( 'maybe.test', [ 'proxy_cache' => 'on' ] );

		try {
			$this->html_command()->update( [ 'maybe.test' ], [ 'proxy-cache' => 'maybe' ] );
		} catch ( EE\ExitException $e ) {
			// Rejected: right.
		}
		$this->assertSame( 'on', Site::find( 'maybe.test' )->proxy_cache );
		$this->assertSame( [], $this->runner->matching( '/^docker restart/' ) );
	}

	public function test_main_conf_env_and_index_templates() {
		$main = mustache_render( SITE_TEMPLATE_ROOT . '/config/nginx/main.conf.mustache', [ 'server_name' => 'a.test b.test *.a.test', 'document_root' => '/var/www/htdocs/public' ] );
		$this->assertStringContainsString( "\troot /var/www/htdocs/public;\n", $main );
		$this->assertStringContainsString( "\tserver_name a.test b.test *.a.test;\n", $main );
		$this->assertStringNotContainsString( '{{', $main );

		$env = mustache_render( SITE_TEMPLATE_ROOT . '/config/.env.mustache', [ 'virtual_host' => 'a.test', 'user_id' => 1000, 'group_id' => 33 ] );
		$this->assertSame( "VIRTUAL_HOST=a.test\nVIRTUAL_HOST_EMAIL=example@a.test\nUSER_ID=1000\nGROUP_ID=33\n", $env );

		$index = mustache_render( SITE_TEMPLATE_ROOT . '/index.html.mustache', [ 'version' => 'v4.12.0', 'site_src_root' => '/opt/easyengine/sites/a.test/app/htdocs' ] );
		$this->assertStringContainsString( 'EasyEngine v4.12.0 is working perfectly.', $index );
		$this->assertStringContainsString( 'copy your site files to /opt/easyengine/sites/a.test/app/htdocs.', $index );
	}

	public function test_sendmail_path_switches_between_msmtp_and_sendmail() {
		$root = $this->sandbox();
		mkdir( "$root/config/php/php/conf.d", 0700, true );
		$ini = "$root/config/php/php/conf.d/custom.ini";
		file_put_contents( $ini, "upload_max_filesize = 100M\nsendmail_path = /usr/sbin/sendmail -t -i\nmemory_limit = 128M\n" );
		$command = $this->html_command( [ 'site_fs_path' => $root ] );

		self::call_private( $command, 'sendmail_path_update', true );
		$this->assertStringEqualsFile( $ini, "upload_max_filesize = 100M\nsendmail_path = /usr/bin/msmtp -t\nmemory_limit = 128M\n" );

		self::call_private( $command, 'sendmail_path_update', false );
		$this->assertStringEqualsFile( $ini, "upload_max_filesize = 100M\nsendmail_path = /usr/sbin/sendmail -t -i -f no-reply@example.com\nmemory_limit = 128M\n" );
	}

	private function update_proxy_cache( Site $site, array $assoc_args ) {
		self::call_private( $this->html_command( $site ), 'update_proxy_cache', [ $site->site_url ], $assoc_args );
	}
}
