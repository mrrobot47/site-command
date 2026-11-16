<?php

use EE\Site\Cloner\Site;
use EE\Site\Tests\SiteTestCase;
use function EE\Site\Cloner\Utils\copy_site_certs;
use function EE\Site\Cloner\Utils\copy_site_db;
use function EE\Site\Cloner\Utils\copy_site_files;
use function EE\Site\Cloner\Utils\get_transfer_details;
use function EE\Site\Cloner\Utils\rsync_command;

/**
 * `ee site clone`/`sync` helpers: location parsing, remote output handling and the commands sent to each host (src/clone/).
 */
class ClonerTest extends SiteTestCase {

	protected $reset_db = false;

	public function test_from_location_parses_remote_and_local_sites() {
		$remote = Site::from_location( ' deploy@prod.example.com:blog.test ' );
		$this->assertSame( [ 'blog.test', 'prod.example.com', 'deploy', 'deploy@prod.example.com' ], [ $remote->name, $remote->host, $remote->user, $remote->ssh_string ] );

		$local = Site::from_location( 'blog.test' );
		$this->assertSame( [ 'blog.test', 'localhost', '', '' ], [ $local->name, $local->host, $local->user, $local->ssh_string ] );

		$this->assertSame( '', Site::from_location( '.' )->name );
	}

	/**
	 * @dataProvider malformed_locations
	 */
	public function test_from_location_rejects_malformed_remotes( $location ) {
		$this->assert_ee_error(
			function () use ( $location ) {
				Site::from_location( $location );
			},
			'Invalid format for remote site. Please use [user@ssh-hostname:] sitename'
		);
	}

	public function malformed_locations() {
		return [ 'no user' => [ 'host:site.test' ], 'no site' => [ 'root@host' ], 'empty site' => [ 'root@host:' ] ];
	}

	/**
	 * CLONE-7: `user@host:.` keeps `.` as the site name (the local form treats it as "no name").
	 *
	 * @group known-bug
	 * @group CLONE-7
	 */
	public function test_from_location_treats_a_remote_dot_as_no_site_name() {
		$this->assertSame( '', Site::from_location( 'root@host:.' )->name );
	}

	public function test_commands_run_over_ssh_only_for_remote_sites() {
		$remote = Site::from_location( 'root@h.example:a.test' );
		$remote->site_details = [ 'site_fs_path' => '/opt/easyengine/sites/a.test' ];
		$remote->execute( 'ee cli version' );
		Site::from_location( 'a.test' )->execute( 'ee cli version' );

		$this->assertSame( [ 'ssh -t root@h.example "ee cli version"', 'ee cli version' ], $this->runner->commands() );
		$this->assertSame( 'root@h.example:/opt/easyengine/sites/a.test/app/htdocs/', $remote->get_site_root_dir() );
		$this->assertSame( '/tmp/x', Site::from_location( 'a.test' )->get_rsync_path( '/tmp/x' ) );
	}

	public function test_transfer_details_fill_in_the_missing_site_name() {
		list( $source, $destination ) = get_transfer_details( 'root@h:a.test', '.' );
		$this->assertSame( [ 'a.test', 'a.test', 'h', 'localhost' ], [ $source->name, $destination->name, $source->host, $destination->host ] );

		list( $source, $destination ) = get_transfer_details( 'a.test', 'b.test' );
		$this->assertSame( [ 'a.test', 'b.test' ], [ $source->name, $destination->name ] );
	}

	/**
	 * @dataProvider invalid_transfers
	 */
	public function test_transfer_details_reject_impossible_transfers( $source, $destination, $message ) {
		$this->assert_ee_error(
			function () use ( $source, $destination ) {
				get_transfer_details( $source, $destination );
			},
			$message
		);
	}

	public function invalid_transfers() {
		return [
			'no names'      => [ '.', '.', 'No sitename found in source and destination site.' ],
			'both remote'   => [ 'a@h1:x.test', 'b@h2:x.test', 'Both source and destination sites cannot be remote.' ],
			'same local'    => [ 'x.test', 'x.test', "Cannot copy 'x.test' on 'localhost' to 'x.test' on 'localhost'" ],
			'implied local' => [ 'x.test', '.', "Cannot copy 'x.test' on 'localhost'" ],
		];
	}

	public function test_site_exists_reads_the_json_site_list() {
		$site = Site::from_location( 'root@h:b.test' );
		$this->runner->on( '/ee site list --format=json --no-color/', '[{"site":"a.test","status":"enabled"},{"site":"b.test","status":"enabled"}]' );
		$this->assertTrue( $site->site_exists() );

		$this->runner->on( '/ee site list --format=json --no-color/', '[{"site":"a.test","status":"enabled"}]' );
		$this->assertFalse( $site->site_exists() );
	}

	public function test_site_exists_refuses_a_disabled_source() {
		$this->runner->on( '/ee site list /', '[{"site":"b.test","status":"disabled"}]' );
		$this->expectExceptionMessage( 'The site that you want to clone has been disabled.' );
		Site::from_location( 'root@h:b.test' )->site_exists();
	}

	public function test_site_exists_handles_an_empty_remote_and_errors() {
		$site = Site::from_location( 'root@h:b.test' );
		// Over `ssh -t` the remote's STDERR arrives in STDOUT, colour codes included.
		$this->runner->on( '/ee site list /', "\033[31;1mError:\033[0m No sites found!\r\n", 1 );
		$this->assertFalse( $site->site_exists() );

		$this->runner->on( '/ee site list /', 'ee: command not found', 127 );
		$this->expectExceptionMessage( 'Unable to get site list on root@h' );
		$site->site_exists();
	}

	/**
	 * SC-17: locally `ee site list` writes "No sites found!" to STDERR, but site_exists() only looks at STDOUT.
	 *
	 * @group known-bug
	 * @group SC-17
	 */
	public function test_site_exists_is_false_on_a_local_host_without_sites() {
		$this->runner->on( '/^ee site list /', '', 1, 'Error: No sites found!' );
		try {
			$this->assertFalse( Site::from_location( 'b.test' )->site_exists() );
		} catch ( \Exception $e ) {
			$this->fail( $e->getMessage() );
		}
	}

	/**
	 * CLONE-1: stdout that isn't JSON (a shell-rc line, a PHP notice) makes site_exists() report "absent" instead of failing.
	 *
	 * @group known-bug
	 * @group CLONE-1
	 */
	public function test_site_exists_fails_on_a_non_json_site_list() {
		$this->runner->on( '/ee site list /', "Welcome to prod!\n[{\"site\":\"b.test\",\"status\":\"enabled\"}]" );

		try {
			Site::from_location( 'root@h:b.test' )->site_exists();
			$this->fail( 'site_exists() returned instead of failing.' );
		} catch ( \Exception $e ) {
			$this->assertStringContainsString( 'Unable to get site list', $e->getMessage() );
		}
	}

	/**
	 * CLONE-1: with a non-JSON list the create step fails and its rollback runs `ee site delete --yes` on the destination's existing site.
	 *
	 * @group known-bug
	 * @group CLONE-1
	 */
	public function test_clone_never_deletes_a_destination_site_it_could_not_list() {
		$this->runner->on( '/ee site list /', "motd line\n[{\"site\":\"b.test\",\"status\":\"enabled\"}]" );
		$destination = Site::from_location( 'root@h:b.test' );

		// Production PHP prints the foreach() warning and carries on.
		set_error_handler( function () {
			return true;
		}, E_WARNING );
		try {
			$destination->create_site( $this->source_site( 'html' ), [] );
		} catch ( \Exception $e ) {
			// Failing is right; deleting is not.
		} finally {
			restore_error_handler();
		}

		$this->assertSame( [], $this->runner->matching( '/ee site delete/' ) );
	}

	public function test_create_site_runs_create_then_admin_tools_and_mailhog() {
		$destination = Site::from_location( 'root@h:b.test' );
		$this->answer_site_list_after_create( 'b.test' );

		$destination->create_site( $this->source_site( 'html', [ 'admin_tools' => 1, 'mailhog_enabled' => 1 ] ), [] );

		$this->assertSame(
			[
				'ssh -t root@h "ee site create --skip-tty b.test --type=html"',
				'ssh -t root@h "ee admin-tools enable b.test"',
				'ssh -t root@h "ee mailhog enable --skip-tty b.test"',
			],
			$this->runner->matching( '/ee (site create|admin-tools|mailhog)/' )
		);
		$this->assertSame( 'b.test', $destination->site_details['site_url'] );
		$this->assertSame( [], $this->runner->matching( '/ee site delete/' ) );
	}

	public function test_create_site_refuses_an_existing_destination() {
		$this->runner->on( '/ee site list /', '[{"site":"b.test","status":"enabled"}]' );
		$this->expectExceptionMessage( "Site  'b.test' already exists on 'h'" );
		Site::from_location( 'root@h:b.test' )->create_site( $this->source_site( 'html' ), [] );
	}

	/**
	 * @dataProvider create_commands
	 */
	public function test_site_create_command_mirrors_the_source( $type, $details, $assoc_args, $expected ) {
		$destination = Site::from_location( 'b.test' );
		$command     = self::call_private( $destination, 'get_site_create_command', $this->source_site( $type, $details ), $assoc_args );

		$this->assertSame( $expected, $command );
	}

	public function create_commands() {
		$base = 'ee site create --skip-tty b.test';

		return [
			'html'              => [ 'html', [], [], "$base --type=html" ],
			'public dir'        => [ 'html', [ 'site_container_fs_path' => '/var/www/htdocs/public' ], [], "$base --type=html --public-dir=public" ],
			'php with db'       => [ 'php', [ 'db_host' => 'global-db', 'php_version' => '8.2' ], [], "$base --type=php --with-db --php=8.2" ],
			'wp full'           => [
				'wp',
				[ 'cache_nginx_browser' => 1, 'cache_host' => 'redis', 'db_host' => 'global-db', 'php_version' => '8.3', 'proxy_cache' => 'on', 'app_sub_type' => 'subdom', 'app_admin_username' => 'admin', 'app_admin_email' => 'a@b.test', 'app_admin_password' => 'pw' ],
				[],
				"$base --type=wp --cache --with-local-redis --php=8.3 --proxy-cache=on --mu=subdom --admin-user=admin --admin-email=a@b.test --admin-pass=pw --allow-insecure-pass",
			],
			'le ssl copied'     => [ 'html', [ 'site_ssl' => 'le', 'site_ssl_wildcard' => 1 ], [], "$base --type=html --ssl=le --wildcard" ],
			'ssl flag wins'     => [ 'html', [ 'site_ssl' => 'le' ], [ 'ssl' => 'self' ], "$base --type=html --ssl=self" ],
			'ssl off'           => [ 'html', [ 'site_ssl' => 'le' ], [ 'ssl' => 'off' ], "$base --type=html" ],
			'custom other name' => [ 'html', [ 'site_ssl' => 'custom' ], [], "$base --type=html" ],
		];
	}

	/**
	 * CLONE-3: values from the source's `ee site info` go unquoted into `ee site create`, which runs through a shell.
	 *
	 * @group known-bug
	 * @group CLONE-3
	 * @dataProvider source_fields
	 */
	public function test_site_create_command_is_shell_safe( $field, $remote ) {
		$this->assert_shell_safe(
			function ( $payload ) use ( $field, $remote ) {
				$this->runner->reset();
				$this->answer_site_list_after_create( 'b.test' );
				$details = [ $field => $payload ];
				if ( 'site_container_fs_path' === $field ) {
					$details[ $field ] = '/var/www/htdocs/' . $payload;
				}
				Site::from_location( $remote ? 'root@h:b.test' : 'b.test' )->create_site( $this->source_site( 'wp', $details ), [] );
			},
			'/ee site create/'
		);
	}

	public function source_fields() {
		return [
			'admin password'        => [ 'app_admin_password', false ],
			'admin email'           => [ 'app_admin_email', false ],
			'admin user'            => [ 'app_admin_username', false ],
			'php version'           => [ 'php_version', false ],
			'multisite type'        => [ 'app_sub_type', false ],
			'public dir'            => [ 'site_container_fs_path', false ],
			'admin password remote' => [ 'app_admin_password', true ],
		];
	}

	public function test_parent_site_check_accepts_a_wildcard_parent() {
		$site = Site::from_location( 'root@h:blog.parent.test' );
		$this->runner->on( '/ee site list /', '[{"site":"other.test","status":"enabled"},{"site":"parent.test","status":"enabled"}]' );
		$this->runner->on( '/ee site info parent\.test --format=json/', '{"site_ssl":"le","site_ssl_wildcard":"1"}' );

		$site->validate_parent_site_present_on_host( 'blog.parent.test' );
		$this->assertCount( 1, $this->runner->matching( '/ee site info parent\.test/' ) );
	}

	/**
	 * @dataProvider missing_parents
	 */
	public function test_parent_site_check_fails_without_a_wildcard_parent( $list, $info ) {
		$this->runner->on( '/ee site list /', $list );
		$this->runner->on( '/ee site info /', $info );
		$this->expectExceptionMessage( 'Parent site of blog.parent.test not found on destination host h' );
		Site::from_location( 'root@h:blog.parent.test' )->validate_parent_site_present_on_host( 'blog.parent.test' );
	}

	public function missing_parents() {
		return [
			'no parent'      => [ '[{"site":"blog.parent.test","status":"enabled"}]', '{}' ],
			'suffix only'    => [ '[{"site":"arent.test","status":"enabled"}]', '{"site_ssl":"le","site_ssl_wildcard":"1"}' ],
			'not wildcard'   => [ '[{"site":"parent.test","status":"enabled"}]', '{"site_ssl":"le","site_ssl_wildcard":"0"}' ],
			'no ssl'         => [ '[{"site":"parent.test","status":"enabled"}]', '{"site_ssl":"","site_ssl_wildcard":"1"}' ],
		];
	}

	/**
	 * CLONE-2: `ee site info --format=json` returns `site_ssl_wildcard` as the integer 1, which never `=== '1'`.
	 *
	 * @group known-bug
	 * @group CLONE-2
	 */
	public function test_parent_site_check_accepts_the_integer_wildcard_flag() {
		$this->runner->on( '/ee site list /', '[{"site":"parent.test","status":"enabled"}]' );
		$this->runner->on( '/ee site info parent\.test /', '{"site_ssl":"le","site_ssl_wildcard":1}' );

		try {
			Site::from_location( 'root@h:blog.parent.test' )->validate_parent_site_present_on_host( 'blog.parent.test' );
		} catch ( \Exception $e ) {
			$this->fail( $e->getMessage() );
		}
		$this->assertCount( 1, $this->runner->matching( '/ee site info parent\.test/' ) );
	}

	public function test_rsync_command_shape() {
		$this->assertSame( 'rsync -azh --delete-after --ignore-errors --x -e "ssh -t" src/ dst/', rsync_command( 'src/', 'dst/', [ '--x' ] ) );
	}

	/**
	 * CLONE-6: `--ignore-errors` with `--delete-after` deletes the destination's copy of files rsync failed to read.
	 *
	 * @group known-bug
	 * @group CLONE-6
	 */
	public function test_rsync_does_not_delete_after_read_errors() {
		$this->assertStringNotContainsString( '--ignore-errors', rsync_command( 'src/', 'dst/' ) );
	}

	/**
	 * @dataProvider sync_types
	 */
	public function test_copy_site_files_builds_the_rsync_scope( $sync_type, $container_path, $expected ) {
		$source      = Site::from_location( 'root@h:a.test' );
		$destination = Site::from_location( 'a.test' );
		$source->site_details      = [ 'site_fs_path' => '/src/a.test', 'site_container_fs_path' => $container_path ];
		$destination->site_details = [ 'site_fs_path' => '/dst/a.test', 'site_container_fs_path' => $container_path ];

		copy_site_files( $source, $destination, $sync_type );

		$this->assertSame( [ 'rsync -azh --delete-after --ignore-errors ' . $expected ], $this->runner->commands() );
	}

	public function sync_types() {
		return [
			'everything'   => [ [ 'files' => true, 'uploads' => true ], '/var/www/htdocs', "--exclude '/wp-config.php' -e \"ssh -t\" root@h:/src/a.test/app/htdocs/ /dst/a.test/app/htdocs/" ],
			'files only'   => [ [ 'files' => true, 'uploads' => false ], '/var/www/htdocs', "--exclude '/wp-config.php' --exclude '/wp-content/uploads' --exclude '/shared/wp-content/uploads' -e \"ssh -t\" root@h:/src/a.test/app/htdocs/ /dst/a.test/app/htdocs/" ],
			'uploads only' => [ [ 'files' => false, 'uploads' => true ], '/var/www/htdocs', "--exclude '/wp-config.php' -e \"ssh -t\" root@h:/src/a.test/app/htdocs/wp-content/uploads/ /dst/a.test/app/htdocs/wp-content/uploads/" ],
			'public dir'   => [ [ 'files' => true, 'uploads' => true ], '/var/www/htdocs/current/public', "--exclude '/wp-config.php' --exclude '/current/public/wp-config.php' --exclude '/current/wp-config.php' -e \"ssh -t\" root@h:/src/a.test/app/htdocs/ /dst/a.test/app/htdocs/" ],
		];
	}

	public function test_copy_site_files_throws_when_rsync_fails() {
		$this->runner->on( '/^rsync /', '', 23 );
		$source = Site::from_location( 'a.test' );
		$source->site_details = [ 'site_fs_path' => '/src', 'site_container_fs_path' => '/var/www/htdocs' ];
		$this->expectExceptionMessage( 'Unable to sync files.' );
		copy_site_files( $source, clone $source, [ 'files' => true, 'uploads' => true ] );
	}

	/**
	 * CLONE-4: the cert source uses `{key,crt}` brace expansion, which dash (`/bin/sh`) doesn't do.
	 *
	 * @group known-bug
	 * @group CLONE-4
	 */
	public function test_cert_copy_does_not_rely_on_brace_expansion() {
		$this->runner->on( '/wc -l/', "2\n" );
		copy_site_certs( Site::from_location( 'root@h:a.test' ), Site::from_location( 'a.test' ) );

		$this->assertSame( [], $this->runner->matching( '/\{key,crt\}/' ) );
	}

	/**
	 * CLONE-4: over `ssh -t` the count arrives as "2\r\n", so a correct copy is reported as failed.
	 *
	 * @group known-bug
	 * @group CLONE-4
	 */
	public function test_cert_copy_accepts_a_pty_line_ending() {
		$this->runner->on( '/wc -l/', "2\r\n" );
		try {
			copy_site_certs( Site::from_location( 'a.test' ), Site::from_location( 'root@h:a.test' ) );
		} catch ( \Exception $e ) {
			$this->fail( $e->getMessage() );
		}
		$this->assertSame( [], $this->runner->matching( '/^ssh -t root@h "rm /' ), 'No rollback.' );
	}

	public function test_wp_db_copy_exports_copies_imports_and_rewrites_urls() {
		list( $source, $destination ) = $this->wp_pair( [ 'site_ssl' => '' ], [ 'site_ssl' => 'le' ] );

		copy_site_db( $source, $destination );

		$commands = $this->runner->commands();
		$this->assertCount( 8, $commands, implode( "\n", $commands ) );
		$this->assertMatchesRegularExpression( "#^ssh -t root@h \"ee shell --skip-tty a\.test --command='wp db export --skip-ssl \.\./a\.test-\w{18}\.sql'\"$#", $commands[0] );
		preg_match( '#(a\.test-\w{18}\.sql)#', $commands[0], $m );
		$file = $m[1];
		$this->assertSame( "rsync -azh --delete-after --ignore-errors  -e \"ssh -t\" root@h:/src/a.test/app/$file /dst/b.test/app/$file", $commands[1] );
		$this->assertSame( "ee shell --skip-tty b.test --command='mysql --skip-ssl -u\"u\" -p\"p\" -h\"global-db\" \"b_db\" < ../$file'", $commands[2] );
		$this->assertSame( "ee shell --skip-tty b.test --command='wp search-replace http://a.test http://b.test --network --all-tables'", $commands[3] );
		$this->assertSame( "ee shell --skip-tty b.test --command='wp search-replace https://a.test https://b.test --network --all-tables'", $commands[4] );
		$this->assertSame( "ee shell --skip-tty b.test --command='wp search-replace http://b.test https://b.test --network --all-tables'", $commands[5] );
		$this->assertSame( [ "ssh -t root@h \"rm /src/a.test/app/$file\"", "rm /dst/b.test/app/$file" ], array_slice( $commands, 6 ) );
	}

	public function test_php_site_with_db_is_not_copied() {
		list( $source, $destination ) = $this->wp_pair();
		$source->site_details['site_type'] = 'php';

		copy_site_db( $source, $destination );
		$this->assertSame( [], $this->runner->commands() );
		$this->assertStringContainsString( 'Please sync the database manually', $this->stdout() );
	}

	/**
	 * SC-12: both step results are ignored, so the import (and the success message) still run after a failed export.
	 *
	 * @group known-bug
	 * @group SC-12
	 */
	public function test_failed_db_export_stops_the_import() {
		list( $source, $destination ) = $this->wp_pair();
		$this->runner->on( '/wp db export/', '', 1 );

		try {
			copy_site_db( $source, $destination );
		} catch ( \Exception $e ) {
			// Failing loudly is fine.
		}
		$this->assertSame( [], $this->runner->matching( '/mysql --skip-ssl/' ) );
	}

	private function source_site( $type, array $details = [] ) {
		$source               = Site::from_location( 'a.test' );
		$source->site_details = $details + [
			'site_url'               => 'a.test',
			'site_type'              => $type,
			'site_container_fs_path' => '/var/www/htdocs',
			'site_ssl'               => '',
			'site_ssl_wildcard'      => 0,
			'admin_tools'            => 0,
			'mailhog_enabled'        => 0,
			'proxy_cache'            => 'off',
			'php_version'            => '8.2',
		];

		return $source;
	}

	/** The destination's site list is empty until `ee site create` has run. */
	private function answer_site_list_after_create( $name ) {
		$created = false;
		$this->runner->on(
			'/ee site create /',
			function () use ( &$created ) {
				$created = true;

				return [ '', 0 ];
			}
		);
		$this->runner->on(
			'/ee site list /',
			function () use ( &$created, $name ) {
				return [ $created ? json_encode( [ [ 'site' => $name, 'status' => 'enabled' ] ] ) : '[]', 0 ];
			}
		);
		$this->runner->on( '/ee site info /', json_encode( [ 'site_url' => $name, 'site_type' => 'html' ] ) );
	}

	private function wp_pair( array $source = [], array $destination = [] ) {
		$src               = Site::from_location( 'root@h:a.test' );
		$src->site_details = $source + [ 'site_url' => 'a.test', 'site_type' => 'wp', 'db_host' => 'global-db', 'site_fs_path' => '/src/a.test', 'site_ssl' => '' ];
		$dst               = Site::from_location( 'b.test' );
		$dst->site_details = $destination + [ 'site_url' => 'b.test', 'site_type' => 'wp', 'db_host' => 'global-db', 'db_user' => 'u', 'db_password' => 'p', 'db_name' => 'b_db', 'site_fs_path' => '/dst/b.test', 'site_ssl' => '' ];

		return [ $src, $dst ];
	}
}
