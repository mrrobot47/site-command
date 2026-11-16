<?php

use EE\Model\Site;
use EE\Site\Tests\SiteTestCase;
use function EE\Site\Utils\create_site_root;
use function EE\Site\Utils\reload_global_nginx_proxy;

/**
 * `ee site list/disable/restart/reload/clean/update --ssl` on an html site, with the fake runner standing in for docker.
 */
class SiteLifecycleTest extends SiteTestCase {

	const COMPOSE = 'docker-compose -f docker-compose.yml ';

	private $error_log;

	protected function setUp(): void {
		parent::setUp();
		$this->track( EE_SERVICE_DIR . '/nginx-proxy' );
		$this->set_config( [ 'custom-compose' => null ] );
		// run_compose_command() passes the container's stderr to error_log(), which would print blank lines into the run.
		$this->error_log = ini_set( 'error_log', $this->sandbox() . '/php-errors.log' );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', (string) $this->error_log );
		parent::tearDown();
	}

	public function test_list_prints_site_names_filtered_by_state() {
		$this->create_site( 'on.test' );
		$this->create_site( 'off.test', [ 'site_enabled' => 0 ] );
		$command = $this->html_command();

		$command->_list( [], [ 'format' => 'text' ] );
		$this->assertSame( "on.test\noff.test\n", $this->stdout() );

		self::logger()->stdout = '';
		$command->_list( [], [ 'format' => 'text', 'disabled' => true ] );
		$this->assertSame( "off.test\n", $this->stdout() );

		self::logger()->stdout = '';
		$command->_list( [], [ 'format' => 'text', 'enabled' => true, 'disabled' => true ] );
		$this->assertSame( "on.test\noff.test\n", $this->stdout(), 'Both filters mean all sites.' );
	}

	public function test_list_errors_without_sites() {
		$this->assert_ee_error(
			function () {
				$this->html_command()->_list( [], [ 'format' => 'text', 'enabled' => true ] );
			},
			'No sites found!'
		);
	}

	public function test_disable_stops_the_site_and_drops_its_redirect() {
		$this->create_site( 'down.test' );
		$redirect = EE_SERVICE_DIR . '/nginx-proxy/conf.d/down.test-redirect.conf';
		mkdir( dirname( $redirect ), 0700, true );
		touch( $redirect );

		$this->html_command()->disable( [ 'down.test' ], [] );

		$this->assertFileDoesNotExist( $redirect );
		$this->assertSame( [ self::COMPOSE . ' down' ], $this->runner->matching( '/^docker-compose/' ) );
		$this->assertCount( 1, $this->runner->matching( '/nginx -s reload/' ) );
		$this->assertSame( '0', (string) Site::find( 'down.test' )->site_enabled );
		$this->assertStringContainsString( 'Site down.test disabled.', $this->stdout() );
	}

	public function test_disable_keeps_the_site_enabled_when_compose_down_fails() {
		$this->create_site( 'stuck.test' );
		$this->runner->on( '/ down$/', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->html_command()->disable( [ 'stuck.test' ], [] );
			},
			'There was error in disabling stuck.test. Please check logs.'
		);
		$this->assertSame( '1', (string) Site::find( 'stuck.test' )->site_enabled );
	}

	public function test_restart_checks_nginx_first_and_skips_mail_services() {
		$this->create_site( 'rs.test' );
		$this->runner->on( '/config --services$/', "nginx\npostfix\nphp\nmailhog\n" );

		$this->html_command()->restart( [ 'rs.test' ], [] );

		$this->assertSame(
			[ self::COMPOSE . ' exec nginx nginx -t', self::COMPOSE . ' restart nginx', self::COMPOSE . ' restart php' ],
			$this->runner->matching( '/ (exec|restart) /' )
		);
	}

	public function test_restart_stops_when_the_nginx_config_is_broken() {
		$this->create_site( 'broken.test' );
		$this->runner->on( '/exec nginx nginx -t$/', '', 1 );

		$this->assert_ee_error(
			function () {
				$this->html_command()->restart( [ 'broken.test' ], [ 'nginx' => true ] );
			},
			'Nginx test failed'
		);
		$this->assertSame( [], $this->runner->matching( '/ restart nginx$/' ) );
	}

	public function test_reload_runs_the_nginx_test_and_reload_in_the_container() {
		$this->create_site( 'rl.test' );
		$this->html_command()->reload( [ 'rl.test' ], [] );

		$this->assertSame( [ self::COMPOSE . " exec nginx sh -c 'nginx -t && nginx -s reload'" ], $this->runner->commands() );
		$this->assertStringContainsString( 'Reloading nginx', $this->stdout() );
	}

	/**
	 * SC-6: run_compose_command() discards the exit code, so `ee site reload` succeeds when `nginx -t` fails.
	 *
	 * @group known-bug
	 * @group SC-6
	 */
	public function test_reload_fails_when_nginx_rejects_the_config() {
		$this->create_site( 'rl.test' );
		$this->runner->on( '/nginx -t && nginx -s reload/', '', 1, 'nginx: [emerg] unexpected "}"' );

		$this->assert_ee_error(
			function () {
				$this->html_command()->reload( [ 'rl.test' ], [] );
			}
		);
	}

	public function test_clean_purges_object_and_page_cache_keys() {
		$this->create_site( 'cache.test', [ 'site_type' => 'wp', 'cache_mysql_query' => 1, 'cache_nginx_fullpage' => 1 ] );

		$this->html_command()->clean( [ 'cache.test' ], [] );

		$this->assertSame(
			[
				'docker exec -it services_global-redis_1 redis-cli --eval purge_all_cache.lua 0 , "cache.test_obj*"',
				'docker exec -it services_global-redis_1 redis-cli --eval purge_all_cache.lua 0 , "cache.test_page*"',
			],
			$this->runner->commands()
		);
		$this->assertStringContainsString( 'Page cache cleared for cache.test', $this->stdout() );
		$this->assertStringContainsString( 'Object cache cleared for cache.test', $this->stdout() );
	}

	public function test_clean_reports_caches_that_are_not_enabled() {
		$this->create_site( 'nocache.test' );
		$this->assert_ee_error(
			function () {
				$this->html_command()->clean( [ 'nocache.test' ], [ 'proxy' => true ] );
			},
			'Proxy cache is not enabled on site.'
		);
		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * SC-7: without flags, clean always adds object and page cache, which an html site can't have, so it always exits 1.
	 *
	 * @group known-bug
	 * @group SC-7
	 */
	public function test_clean_without_flags_purges_only_what_the_site_has() {
		$this->create_site( 'proxied.test', [ 'proxy_cache' => 'on' ] );

		try {
			$this->html_command()->clean( [ 'proxied.test' ], [] );
		} catch ( EE\ExitException $e ) {
			$this->fail( trim( $this->stderr() ) );
		}
		$this->assertStringContainsString( 'Proxy cache cleared for proxied.test', $this->stdout() );
	}

	/**
	 * @dataProvider rejected_ssl_updates
	 */
	public function test_update_ssl_rejects_unsupported_transitions( $site, $assoc_args, $message ) {
		$this->create_site( 'ssl.test', $site );
		$this->assert_ee_error(
			function () use ( $assoc_args ) {
				self::call_private( $this->html_command( Site::find( 'ssl.test' ) ), 'update_ssl', $assoc_args );
			},
			$message
		);
		$this->assertSame( [], $this->runner->commands() );
	}

	public function rejected_ssl_updates() {
		return [
			'already off'          => [ [], [ 'ssl' => 'off' ], 'SSL is already disabled on ssl.test' ],
			'already on'           => [ [ 'site_ssl' => 'le' ], [ 'ssl' => 'le' ], 'SSL is already enabled on ssl.test' ],
			'to wildcard'          => [ [ 'site_ssl' => 'le' ], [ 'ssl' => 'le', 'wildcard' => true ], 'Update from normal SSL to wildcard SSL is not supported yet.' ],
			'off with --wildcard'  => [ [ 'site_ssl' => 'le', 'site_ssl_wildcard' => 1 ], [ 'ssl' => 'off', 'wildcard' => true ], 'You cannot use --wildcard flag with --ssl=off' ],
			'from wildcard le'     => [ [ 'site_ssl' => 'le', 'site_ssl_wildcard' => 1, 'alias_domains' => 'ssl.test,*.ssl.test' ], [ 'ssl' => 'le' ], 'Update from wildcard SSL to normal SSL is not supported yet.' ],
		];
	}

	public function test_update_ssl_off_disables_https() {
		$root = $this->create_site( 'le.test', [ 'site_ssl' => 'le' ] )->site_fs_path;
		$this->runner->on( '/^docker volume ls /', "letest_htdocs\n" );

		self::call_private( $this->html_command( Site::find( 'le.test' ) ), 'update_ssl', [ 'ssl' => 'off' ] );

		$this->assertStringContainsString( '- HTTPS_METHOD=nohttps', file_get_contents( "$root/docker-compose.yml" ) );
		$this->assertSame( [ self::COMPOSE . ' up -d nginx' ], $this->runner->matching( '/^docker-compose/' ) );
		$this->assertEmpty( Site::find( 'le.test' )->site_ssl );
		$this->assertStringContainsString( 'Disabled SSL for le.test', $this->stdout() );
	}

	/**
	 * SC-3 (fixed in v3.8.1): a `--ssl=self` site is stored with `site_ssl_wildcard=1`, and `--ssl=off` must not be refused as a wildcard change.
	 *
	 * @group SC-3
	 * @group PHPT-10
	 */
	public function test_update_ssl_off_works_for_a_self_signed_site() {
		$this->create_site( 'self.test', [ 'site_ssl' => 'self', 'site_ssl_wildcard' => 1 ] );
		$this->runner->on( '/^docker volume ls /', "selftest_htdocs\n" );

		try {
			self::call_private( $this->html_command( Site::find( 'self.test' ) ), 'update_ssl', [ 'ssl' => 'off' ] );
		} catch ( EE\ExitException $e ) {
			$this->fail( trim( $this->stderr() ) );
		}
		$this->assertEmpty( Site::find( 'self.test' )->site_ssl );
	}

	public function test_proxy_reload_regenerates_tests_then_reloads() {
		$this->assertNotFalse( reload_global_nginx_proxy() );

		$this->assertSame(
			[
				'docker exec services_global-nginx-proxy_1 sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf"',
				'docker exec services_global-nginx-proxy_1 sh -c "nginx -t"',
				'docker exec services_global-nginx-proxy_1 sh -c "/usr/sbin/nginx -s reload"',
			],
			$this->runner->commands()
		);
	}

	public function test_proxy_reload_is_skipped_when_the_config_test_fails() {
		$this->runner->on( '/nginx -t/', '', 1, 'nginx: [emerg] host not found' );

		$this->assertFalse( reload_global_nginx_proxy() );
		$this->assertSame( [], $this->runner->matching( '/nginx -s reload/' ) );
		$this->assertStringContainsString( "nginx config test failed, skipping reload of services_global-nginx-proxy_1:\nnginx: [emerg] host not found", $this->stderr() );
	}

	public function test_site_root_is_created_for_the_invoking_user() {
		$root = $this->track( WEBROOT . 'root.test' );
		$this->runner->on( '/^whoami$/', get_current_user() . "\n" );

		create_site_root( $root, 'root.test' );
		$this->assertDirectoryExists( $root );
		$this->assertSame( [ 'whoami' ], $this->runner->commands() );

		$this->assert_ee_error(
			function () use ( $root ) {
				create_site_root( $root, 'root.test' );
			},
			'Webroot directory for site root.test already exists.'
		);
	}

	public function test_info_json_prints_the_stored_row() {
		$this->create_site( 'json.test', [ 'site_ssl' => 'le', 'alias_domains' => 'json.test,b.test' ] );
		$this->html_command()->info( [ 'json.test' ], [ 'format' => 'json' ] );

		$info = json_decode( trim( $this->stdout() ), true );
		$this->assertSame( [ 'json.test', 'le', 'json.test,b.test' ], [ $info['site_url'], $info['site_ssl'], $info['alias_domains'] ] );
	}

	/**
	 * @dataProvider parent_sites
	 */
	public function test_inherit_needs_a_parent_with_a_wildcard_cert( $parent, $message ) {
		if ( null !== $parent ) {
			$this->create_site( 'parent.test', $parent );
		}
		ob_start();
		try {
			self::call_private( $this->html_command(), 'check_parent_site_certs', 'blog.parent.test' );
			$this->assertNull( $message, 'Accepted the parent.' );
		} catch ( \Exception $e ) {
			$this->assertStringStartsWith( (string) $message, $e->getMessage() );
		} finally {
			ob_end_clean();
		}
	}

	public function parent_sites() {
		return [
			'no parent'     => [ null, 'Unable to find existing site: parent.test' ],
			'no ssl'        => [ [], 'Cannot inherit from parent.test as site does not have SSL cert' ],
			'not wildcard'  => [ [ 'site_ssl' => 'le' ], 'Cannot inherit from parent.test as site does not have wildcard SSL cert' ],
			'wildcard'      => [ [ 'site_ssl' => 'le', 'site_ssl_wildcard' => 1 ], null ],
		];
	}

	/**
	 * SC-5: the "does not have SSL cert" message is built with var_dump(), which dumps the parent model to STDOUT.
	 *
	 * @group known-bug
	 * @group SC-5
	 * @group PHPT-16
	 * @group WPT-7
	 */
	public function test_inherit_error_does_not_dump_the_parent_site() {
		$this->create_site( 'parent.test' );
		ob_start();
		try {
			self::call_private( $this->html_command(), 'check_parent_site_certs', 'blog.parent.test' );
		} catch ( \Exception $e ) {
			// Expected.
		} finally {
			$output = ob_get_clean();
		}
		$this->assertSame( '', $output );
	}

	/**
	 * SC-4: `update --ssl=inherit` never calls check_parent_site_certs(), so a site "inherits" from a parent that doesn't exist.
	 *
	 * @group known-bug
	 * @group SC-4
	 */
	public function test_update_ssl_inherit_requires_the_parent_cert() {
		$root = $this->create_site( 'blog.parent.test' )->site_fs_path;
		mkdir( "$root/app/htdocs", 0700, true );
		$this->runner->on( '/^docker volume ls /', "blogparenttest_htdocs\n" );
		// The www check curls www.<site>; a closed local proxy keeps it off the network.
		$proxy = getenv( 'http_proxy' );
		putenv( 'http_proxy=http://127.0.0.1:9' );

		try {
			self::call_private( $this->html_command( Site::find( 'blog.parent.test' ) ), 'update_ssl', [ 'ssl' => 'inherit' ] );
		} catch ( EE\ExitException $e ) {
			$this->assertStringContainsString( 'parent.test', $this->stderr() );
		} finally {
			putenv( false === $proxy ? 'http_proxy' : "http_proxy=$proxy" );
		}
		$this->assertEmpty( Site::find( 'blog.parent.test' )->site_ssl, trim( $this->stdout() ) );
	}

	/**
	 * SC-13: `update --php` on an html site creates `.backup/config/php-` and then fails on the missing config/php.
	 *
	 * @group known-bug
	 * @group SC-13
	 */
	public function test_update_php_refuses_an_html_site_up_front() {
		$root = $this->create_site( 'static.test' )->site_fs_path;

		$this->assert_ee_error(
			function () {
				self::call_private( $this->html_command( Site::find( 'static.test' ) ), 'update_php', [ 'static.test' ], [ 'php' => '8.3' ] );
			}
		);
		$this->assertDirectoryDoesNotExist( "$root/.backup" );
		$this->assertStringNotContainsString( 'The origin directory specified', $this->stderr() );
	}
}
