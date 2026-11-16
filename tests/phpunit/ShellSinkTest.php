<?php

use EE\Model\Option;
use EE\Site\Tests\SiteTestCase;
use EE\Site\Type\Site_Self_Signed;
use EE\Tests\ShellProbe;
use function EE\Site\Utils\cleanup_db;
use function EE\Site\Utils\cleanup_db_user;
use function EE\Site\Utils\configure_postfix;
use function EE\Site\Utils\create_etc_hosts_entry;
use function EE\Site\Utils\set_postfix_files;

/**
 * Commands built from site names and site data (hosts entry, postfix, DB cleanup, self-signed certs, cache purge).
 */
class ShellSinkTest extends SiteTestCase {

	public function test_hosts_entry_appends_a_tab_separated_line() {
		create_etc_hosts_entry( 'hosts.test' );

		$commands = $this->runner->commands();
		$this->assertCount( 1, $commands );
		$this->assertStringStartsWith( '/bin/bash -c ', $commands[0] );
		// Run it against a copy of /etc/hosts to see what it appends.
		$dir = $this->sandbox();
		file_put_contents( "$dir/hosts", "127.0.0.1\tlocalhost\n" );
		ShellProbe::run( str_replace( '/etc/hosts', "$dir/hosts", $commands[0] ), $dir );
		$this->assertStringEqualsFile( "$dir/hosts", "127.0.0.1\tlocalhost\n127.0.0.1\thosts.test\n" );
		$this->assertStringContainsString( 'Host entry successfully added.', $this->stdout() );
	}

	public function test_hosts_entry_is_not_duplicated() {
		// The container's /etc/hosts always maps localhost.
		create_etc_hosts_entry( 'localhost' );

		$this->assertSame( [], $this->runner->commands() );
		$this->assertStringContainsString( 'Host entry already exists.', $this->stdout() );
	}

	public function test_hosts_entry_failure_is_a_warning() {
		$this->runner->on( '/etc\/hosts/', '', 1 );
		create_etc_hosts_entry( 'hosts.test' );

		$this->assertStringContainsString( 'Failed to add hosts.test in host entry, Please do it manually!', $this->stderr() );
	}

	/**
	 * SEC-1: the site name goes unquoted into `/bin/bash -c 'echo "127.0.0.1<TAB><site>" >> /etc/hosts'`.
	 *
	 * @group known-bug
	 * @group SEC-1
	 */
	public function test_hosts_entry_is_shell_safe() {
		$this->assert_shell_safe(
			function ( $site ) {
				create_etc_hosts_entry( $site );
				// The command runs /bin/bash by absolute path; keep its append inside the probe's sandbox.
				foreach ( $this->runner->calls as &$call ) {
					$call['command'] = str_replace( '/etc/hosts', 'hosts', $call['command'] );
				}
			}
		);
	}

	public function test_postfix_files_generate_a_cert_for_smtp_dot_site() {
		$dir = $this->sandbox();
		set_postfix_files( 'mail.test', $dir );

		$this->assertDirectoryExists( "$dir/postfix/ssl" );
		$openssl = $this->runner->matching( '/^openssl req /' );
		$this->assertCount( 1, $openssl );
		$this->assertSame(
			[ 'req', '-new', '-x509', '-nodes', '-days', '365', '-subj', '/CN=smtp.mail.test', '-out', "$dir/postfix/ssl/server.crt", '-keyout', "$dir/postfix/ssl/server.key" ],
			$this->shell_argv( $openssl[0], 'openssl' )
		);
	}

	public function test_postfix_files_throw_when_openssl_fails() {
		$this->runner->on( '/^openssl /', '', 1 );
		$this->expectExceptionMessage( 'Unable to generate ssl key for postfix' );
		set_postfix_files( 'mail.test', $this->sandbox() );
	}

	/**
	 * SEC-2: the condition is inverted (`! openssl && chmod`), so after a successful openssl the key is never chmod 0600.
	 *
	 * @group known-bug
	 * @group SEC-2
	 */
	public function test_postfix_key_is_made_private() {
		$dir = $this->sandbox();
		set_postfix_files( 'mail.test', $dir );

		$chmod = $this->runner->matching( '/^chmod /' );
		$this->assertCount( 1, $chmod, implode( "\n", $this->runner->commands() ) );
		$this->assertSame( [ '0600', "$dir/postfix/ssl/server.key" ], $this->shell_argv( $chmod[0], 'chmod' ) );
	}

	/**
	 * SEC-2: the site name goes unquoted into `-subj "/CN=smtp.<site>"`.
	 *
	 * @group known-bug
	 * @group SEC-2
	 */
	public function test_postfix_files_are_shell_safe() {
		$dir = $this->sandbox();
		$this->assert_shell_safe(
			function ( $site ) use ( $dir ) {
				set_postfix_files( $site, $dir );
			}
		);
	}

	/**
	 * SC-20 (new): a CN over 64 characters makes openssl fail, so a site name over 59 characters can't get its postfix cert.
	 *
	 * @group known-bug
	 * @group SC-20
	 */
	public function test_postfix_cert_subject_fits_the_cn_limit() {
		$site = str_repeat( 'a', 55 ) . '.test';
		set_postfix_files( $site, $this->sandbox() );

		$argv    = $this->shell_argv( $this->runner->matching( '/^openssl req /' )[0], 'openssl' );
		$subject = $argv[ array_search( '-subj', $argv, true ) + 1 ];
		$this->assertLessThanOrEqual( 64, strlen( substr( $subject, 4 ) ), $subject );
	}

	public function test_configure_postfix_sets_sender_relay_networks_and_hostname() {
		$root = $this->sandbox();
		$this->set_config( [ 'custom-compose' => null ] );
		$this->runner->on( '/echo \$REPLY_EMAIL/', "\n" );
		$this->runner->on( '/echo \$RELAY_HOST/', "smtp.relay.test:587\r\n" );
		$this->runner->on( '/^docker inspect /', "10.0.7.0/24\n" );

		configure_postfix( 'mail.test', $root );

		$compose  = 'docker-compose -f docker-compose.yml ';
		$commands = $this->runner->commands();
		$this->assertStringContainsString( 'from no-reply@mail.test', $commands[1] );
		$this->assertStringStartsWith( "$compose exec php sh -c ", $commands[1] );
		$this->assertMatchesRegularExpression( "/ exec postfix postconf -e '?relayhost = smtp\.relay\.test:587'?$/", $commands[3] );
		$this->assertMatchesRegularExpression( "/^docker inspect -f '\{\{ with \(index \.IPAM\.Config 0\) \}\}\{\{ \.Subnet \}\}\{\{ end \}\}' '?mail\.test'?$/", $commands[5] );
		$this->assertMatchesRegularExpression( "/ exec postfix postconf -e '?mynetworks = 10\.0\.7\.0\/24 127\.0\.0\.0\/8'?$/", $commands[6] );
		$this->assertMatchesRegularExpression( "/ exec postfix postconf -e '?myhostname = mail\.test'?$/", $commands[7] );
		$this->assertSame( "$compose restart postfix", end( $commands ) );
	}

	public function test_configure_postfix_keeps_a_configured_sender() {
		$this->set_config( [ 'custom-compose' => null ] );
		$this->runner->on( '/echo \$REPLY_EMAIL/', "ops@example.com\n" );
		configure_postfix( 'mail.test', $this->sandbox() );

		$this->assertStringContainsString( 'from ops@example.com', $this->runner->commands()[1] );
	}

	/**
	 * SEC-1: the site name reaches `docker inspect`, `myhostname = <site>` and the msmtprc sender unescaped.
	 *
	 * @group known-bug
	 * @group SEC-1
	 */
	public function test_configure_postfix_is_shell_safe() {
		$this->set_config( [ 'custom-compose' => null ] );
		$root = $this->sandbox();
		$this->assert_shell_safe(
			function ( $site ) use ( $root ) {
				configure_postfix( $site, $root );
			}
		);
	}

	public function test_cleanup_db_drops_the_database_on_the_global_db() {
		$sql = $this->capture_global_db_sql();
		cleanup_db( 'global-db', 'site_db' );

		$this->assertSame( [ 'DROP DATABASE `site_db`;' ], $sql->getArrayCopy() );
		$this->assert_global_db_sql_commands();
	}

	public function test_cleanup_db_ignores_a_remote_host() {
		cleanup_db( 'db.example.com', 'site_db' );
		cleanup_db_user( 'db.example.com', 'site_user' );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_cleanup_db_user_drops_the_user() {
		$sql = $this->capture_global_db_sql();
		cleanup_db_user( 'global-db', 'site_user' );

		$this->assertSame( [ "DROP USER 'site_user'@'%';" ], $sql->getArrayCopy() );
		$this->assert_global_db_sql_commands();
	}

	/**
	 * SEC-1 (fixed in v3.8.1): the DB name and user (derived from the site name by the site types) never reach a shell; the SQL goes in a file.
	 *
	 * @group SEC-1
	 */
	public function test_db_cleanup_scripts_are_shell_safe() {
		$dir    = $this->sandbox();
		$copied = [];
		$this->runner->on(
			'/^docker cp /',
			function ( $command ) use ( &$copied ) {
				preg_match( "/^docker cp '?([^' ]+)'? \\S+:(\\S+)$/", $command, $m );
				$copied[ basename( $m[2] ) ] = file_get_contents( $m[1] );

				return [ '', 0, '' ];
			}
		);
		$failures = [];
		foreach ( ShellProbe::payloads( 'eeprobemarker' ) as $name => $payload ) {
			foreach ( [ 'cleanup_db', 'cleanup_db_user' ] as $function ) {
				$copied              = [];
				$this->runner->calls = [];
				call_user_func( 'EE\Site\Utils\\' . $function, 'global-db', $payload );
				$scripts = $this->runner->commands();
				// A copied file the container runs with `sh` is shell code too; one fed to mysql on stdin is not.
				foreach ( $copied as $file => $content ) {
					if ( $this->runner->matching( '/ sh ' . preg_quote( $file, '/' ) . '$/' ) ) {
						$scripts[] = $content;
					}
				}
				foreach ( $scripts as $script ) {
					ShellProbe::run( $script, $dir );
					if ( file_exists( "$dir/eeprobemarker" ) ) {
						$failures[] = "[$name] $function";
						unlink( "$dir/eeprobemarker" );
					}
				}
			}
		}
		$this->assertSame( [], $failures, 'Payloads reached the shell: ' . implode( ', ', $failures ) );
	}

	public function test_self_signed_cert_uses_the_existing_root_ca() {
		$conf_dir = $this->seed_root_ca();
		$seen     = [];
		$this->runner->on(
			'/^openssl req -new -sha256 /',
			function () use ( $conf_dir, &$seen ) {
				$seen['conf'] = file_get_contents( "$conf_dir/self.test.conf" );
				$seen['v3']   = file_get_contents( "$conf_dir/self.test.v3.ext" );

				return [ '', 0 ];
			}
		);

		( new Site_Self_Signed() )->create_certificate( 'self.test' );

		$commands = $this->runner->commands();
		$this->assertCount( 2, $commands );
		$this->assertSame(
			[ 'req', '-new', '-sha256', '-nodes', '-passout', 'pass:secretpw', '-out', "$conf_dir/self.test.csr", '-newkey', 'rsa:2048', '-keyout', "$conf_dir/self.test.key", '-config', "$conf_dir/self.test.conf" ],
			$this->shell_argv( $commands[0], 'openssl' )
		);
		$this->assertSame(
			[ 'x509', '-req', '-in', "$conf_dir/self.test.csr", '-CA', "$conf_dir/rootCA.pem", '-CAkey', "$conf_dir/rootCA.key", '-CAcreateserial', '-CAserial', "$conf_dir/rootCA.srl", '-passin', 'pass:secretpw', '-out', "$conf_dir/self.test.crt", '-days', '36500', '-sha256', '-extfile', "$conf_dir/self.test.v3.ext" ],
			$this->shell_argv( $commands[1], 'openssl' )
		);
		$this->assertStringContainsString( "\nCN=self.test\n", $seen['conf'] );
		$this->assertStringContainsString( "DNS.1=self.test\nDNS.2=*.self.test\n", $seen['v3'] );
		$this->assertStringEqualsFile( EE_SERVICE_DIR . '/nginx-proxy/certs/self.test.crt', 'crt' );
		$this->assertStringEqualsFile( EE_SERVICE_DIR . '/nginx-proxy/certs/self.test.key', 'key' );
		foreach ( [ 'key', 'csr', 'crt', 'conf', 'v3.ext' ] as $ext ) {
			$this->assertFileDoesNotExist( "$conf_dir/self.test.$ext" );
		}
	}

	/**
	 * SEC-1 (fixed by #495): the self-signed openssl commands quote the site name in their paths.
	 *
	 * @group SEC-1
	 */
	public function test_self_signed_cert_is_shell_safe() {
		$conf_dir = $this->seed_root_ca();
		$this->assert_shell_safe(
			function ( $site ) use ( $conf_dir ) {
				touch( "$conf_dir/$site.key" );
				touch( "$conf_dir/$site.crt" );
				( new Site_Self_Signed() )->create_certificate( $site );
			}
		);
	}

	public function test_redis_cleanup_purges_only_redis_backed_sites() {
		$this->create_site( 'redis.test', [ 'cache_host' => 'global-redis' ] );
		$this->create_site( 'plain.test' );

		cleanup_redis_entries( 'plain.test' );
		$this->assertSame( [], $this->runner->commands() );

		cleanup_redis_entries( 'redis.test' );
		$this->assertSame( [ 'docker exec -it services_global-redis_1 redis-cli --eval purge_all_cache.lua 0 , "redis.test*"' ], $this->runner->commands() );
	}

	public function test_sharing_cleanup_stops_ngrok_for_the_shared_site_only() {
		Option::set( 'publish_site', 'shared.test' );
		Option::set( 'publish_url', 'https://x.ngrok.io' );

		cleanup_sharing( 'other.test' );
		$this->assertSame( [], $this->runner->commands() );

		cleanup_sharing( 'shared.test' );
		$this->assertSame( [ 'killall ngrok' ], $this->runner->commands() );
		$this->assertSame( '', Option::get( 'publish_site' ) );
		$this->assertSame( '', Option::get( 'publish_url' ) );
	}

	/** run_global_db_sql() deletes its SQL file after `docker cp`, so read it while the copy runs. */
	private function capture_global_db_sql() {
		$sql = new \ArrayObject();
		$this->runner->on(
			'/^docker cp /',
			function ( $command ) use ( $sql ) {
				preg_match( "/^docker cp '([^']+)' /", $command, $m );
				$sql[] = file_get_contents( $m[1] );

				return [ '', 0, '' ];
			}
		);

		return $sql;
	}

	/** The SQL file is copied in, fed to mysql on stdin and removed on both sides. */
	private function assert_global_db_sql_commands() {
		$commands = $this->runner->commands();
		$this->assertCount( 2, $commands );
		$this->assertSame( 1, preg_match( "#^docker cp '([^']+)' services_global-db_1:(/tmp/ee-db-[^ ]+\\.sql)$#", $commands[0], $m ), $commands[0] );
		$this->assertFileDoesNotExist( $m[1] );
		$script = sprintf( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -N -B < %1$s; rc=$?; rm -f %1$s; exit $rc', $m[2] );
		$this->assertSame( 'docker exec services_global-db_1 sh -c ' . escapeshellarg( $script ), $commands[1] );
	}

	/** Make Site_Self_Signed skip root CA generation, which would install the CA into the system trust store. */
	private function seed_root_ca() {
		$conf_dir = $this->track( EE_SERVICE_DIR . '/nginx-proxy' ) . '/self-signed-certs';
		mkdir( $conf_dir, 0700, true );
		file_put_contents( "$conf_dir/rootCA.pem", 'root' );
		Option::set( 'self-signed-secret', 'secretpw' );
		file_put_contents( "$conf_dir/self.test.key", 'key' );
		file_put_contents( "$conf_dir/self.test.crt", 'crt' );

		return $conf_dir;
	}
}
