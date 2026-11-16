<?php
// wait_for_global_db() sleeps a second per check; the fake DB answers at once.
namespace EE\Site\Utils {
	if ( ! function_exists( __NAMESPACE__ . '\sleep' ) ) {
		function sleep( $seconds ) {
			return 0;
		}
	}
}

namespace EE\Site\Tests {

	use EE\Model\Option;
	use function EE\Site\Utils\create_user_in_db;
	use function EE\Site\Utils\cut_db_name_for_grant;
	use function EE\Site\Utils\db_name_fits_exact_grant;
	use function EE\Site\Utils\get_default_db_name;
	use function EE\Site\Utils\get_grant_name_length;
	use function EE\Site\Utils\sql_quote_grant_database;

	/**
	 * Database-level grants on the global DB: the grant name for new sites, and the container migration that restricts
	 * the grants of existing sites (RestrictGlobalDbGrants).
	 */
	class GlobalDbGrantTest extends SiteTestCase {

		const MIGRATION = 'migrations/container/20260930120000_site-command_restrict_global_db_grants.php';

		/** @var string[] SQL files copied into the global DB container, in order. */
		private $sql = [];

		/**
		 * @var array<string,array> db_user => users (count), hosts ('%' count), grants (name => is the EE grant), matches (old name => databases).
		 */
		private $db = [];

		/** @var string[] Users whose fix batch fails. */
		private $failing = [];

		/** @var bool Whether the grant read fails. */
		private $read_fails = false;

		private $path_backup;

		protected function setUp(): void {
			parent::setUp();
			// EE_DOCKER::container_status() runs `which docker` through exec(), outside the fake runner.
			$bin = $this->sandbox();
			file_put_contents( "$bin/docker", "#!/bin/sh\nexit 0\n" );
			chmod( "$bin/docker", 0755 );
			$this->path_backup = getenv( 'PATH' );
			putenv( "PATH=$bin:" . $this->path_backup );

			Option::set( 'version', '4.13.1' );
			$this->runner->on( "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' services_global-db_1$/", "true\n" );
			$this->runner->on(
				"/^docker cp '[^']+' services_global-db_1:/",
				function ( $command ) {
					preg_match( "/^docker cp '([^']+)'/", $command, $m );
					$this->sql[] = file_get_contents( $m[1] );

					return [ '', 0 ];
				}
			);
			$this->runner->on(
				'/^docker exec services_global-db_1 sh -c /',
				function () {
					return $this->answer( end( $this->sql ) );
				}
			);
		}

		protected function tearDown(): void {
			putenv( 'PATH=' . $this->path_backup );
			parent::tearDown();
		}

		/** @return array [ stdout, exit code, stderr ] of the fake global DB for $sql. */
		private function answer( $sql ) {
			if ( 0 === strpos( $sql, 'SELECT COLUMN_NAME FROM information_schema.COLUMNS' ) ) {
				return [ "Select_priv\nDrop_priv\nGrant_priv\nDelete_history_priv\n", 0 ];
			}
			if ( 0 === strpos( $sql, "SELECT 'user'" ) ) {
				if ( $this->read_fails ) {
					return [ '', 1, 'ERROR 2002 (HY000): simulated' ];
				}
				$out = '';
				foreach ( explode( "\n", trim( $sql ) ) as $line ) {
					preg_match( "/^SELECT '(\w+)', (\d+), /", $line, $m );
					if ( 'match' === $m[1] ) {
						preg_match( "/HEX\\('([^']*)'\\)/", $line, $n );
						foreach ( $this->db as $state ) {
							foreach ( isset( $state['matches'][ $n[1] ] ) ? $state['matches'][ $n[1] ] : [] as $match ) {
								$out .= sprintf( "match\t%d\t%s\t%s\n", $m[2], bin2hex( $n[1] ), bin2hex( $match ) );
							}
						}
						continue;
					}
					preg_match( "/User = '([^']*)'/", $line, $u );
					$state = isset( $this->db[ $u[1] ] ) ? $this->db[ $u[1] ] : [ 'users' => 0, 'hosts' => 0, 'grants' => [] ];
					if ( 'user' === $m[1] ) {
						$out .= sprintf( "user\t%d\t%d\t%d\n", $m[2], $state['users'], $state['hosts'] );
					} else {
						foreach ( $state['grants'] as $name => $ee ) {
							$out .= sprintf( "grant\t%d\t%s\t%d\n", $m[2], strtoupper( bin2hex( $name ) ), $ee ? 1 : 0 );
						}
					}
				}

				return [ $out, 0 ];
			}
			foreach ( $this->failing as $user ) {
				if ( false !== strpos( $sql, "'$user'@'%'" ) ) {
					return [ '', 1, 'ERROR 1045 (28000): simulated' ];
				}
			}

			return [ '', 0 ];
		}

		/** A user that exists for host '%' with these grant rows (name => whether it is the ALL PRIVILEGES grant EasyEngine creates). */
		private function user( $name, array $grants, array $matches = [] ) {
			$this->db[ $name ] = [
				'users'   => 1,
				'hosts'   => 1,
				'grants'  => $grants,
				'matches' => $matches,
			];
		}

		private function migration() {
			$file = dirname( __DIR__, 2 ) . '/' . self::MIGRATION;
			if ( ! file_exists( $file ) ) {
				$this->fail( 'The restrict-global-db-grants migration does not exist (SEC-5).' );
			}
			require_once $file;

			return new \EE\Migration\RestrictGlobalDbGrants();
		}

		private function global_site( $url, $db_name, $db_user ) {
			$this->insert_site( [ 'site_url' => $url, 'db_host' => GLOBAL_DB, 'db_name' => $db_name, 'db_user' => $db_user ] );
		}

		/** @return string[] The SQL batches that change grants. */
		private function fixes() {
			return array_values( preg_grep( '/^GRANT /', $this->sql ) );
		}

		/** @return string[] The grant read batches. */
		private function reads() {
			return array_values( preg_grep( "/^SELECT 'user'/", $this->sql ) );
		}

		public function test_create_user_in_db_grants_on_the_escaped_name() {
			$this->assertNotFalse( create_user_in_db( GLOBAL_DB, 'my_site_com', 'u_a', 'pw' ) );
			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `my\\_site\\_com`.* TO 'u_a'@'%'; FLUSH PRIVILEGES;" ], $this->fixes() );
			$this->assertStringNotContainsString( 'Warning', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_grant_name_escapes_the_pattern_characters() {
			$this->assertSame( '`my\\_site\\_com`', sql_quote_grant_database( 'my_site_com' ) );
			$this->assertSame( '`pct\\%db`', sql_quote_grant_database( 'pct%db' ) );
			$this->assertSame( '`back\\\\slash`', sql_quote_grant_database( 'back\\slash' ) );
			$this->assertSame( '`plain`', sql_quote_grant_database( 'plain' ) );
			$this->assertSame( '`a``b\\_c`', sql_quote_grant_database( 'a`b_c' ) );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_grant_name_stays_within_64_characters() {
			$name = 'a_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5';
			$this->assertSame( 63, strlen( $name ) );
			$this->assertSame( 94, get_grant_name_length( $name ) );
			$this->assertFalse( db_name_fits_exact_grant( $name ) );
			// One spare character: the first `_` is escaped, each later one stays `_` (which still matches it).
			$this->assertSame( '`a\\_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5`', sql_quote_grant_database( $name ) );

			$full = str_repeat( 'x', 60 ) . '_%\\y';
			$this->assertSame( '`' . str_repeat( 'x', 60 ) . '___y`', sql_quote_grant_database( $full ) );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_grant_name_counts_characters_not_bytes() {
			$name = str_repeat( "\u{00fc}_", 21 ) . "\u{00fc}"; // 43 characters, 86 bytes.
			$this->assertSame( 64, get_grant_name_length( $name ) );
			$this->assertTrue( db_name_fits_exact_grant( $name ) );
			$this->assertSame( '`' . str_replace( '_', '\\_', $name ) . '`', sql_quote_grant_database( $name ) );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_default_db_name_fits_an_exact_grant() {
			$site    = 'a-b-c-d-e-f-g-h-i-j-k-l-m-n-o-p-q-r-s-t-u-v-w-x-y-z-0-1-2-3-4-5.example.com';
			$default = get_default_db_name( $site );
			$this->assertSame( 'a_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v', $default );
			$this->assertSame( 64, get_grant_name_length( $default ) );
			$this->assertSame( 'my_site_com', get_default_db_name( 'my-site.com' ) );
			$this->assertSame( str_repeat( 'x', 64 ), get_default_db_name( str_repeat( 'x', 70 ) ) );
			// A suffixed name respects the same limit.
			$this->assertSame( 'a_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_2', cut_db_name_for_grant( $default, '_2' ) );
			$this->assertTrue( db_name_fits_exact_grant( cut_db_name_for_grant( $default, '_2' ) ) );
			// A raw IDN name is also kept within 64 bytes, cut on a character boundary.
			$idn = str_repeat( "\u{00fc}", 30 );
			$this->assertSame( $idn . '_tes', get_default_db_name( $idn . '.test' ) );
			$this->assertSame( $idn . '_t_2', cut_db_name_for_grant( $idn . '_test', '_2' ) );
			$this->assertSame( str_repeat( "\u{20ac}", 21 ), cut_db_name_for_grant( str_repeat( "\u{20ac}", 30 ) ) ); // 3-byte characters: 63 bytes.
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_create_user_in_db_warns_when_an_explicit_name_is_too_long_for_an_exact_grant() {
			$name = 'n_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5';
			$this->assertNotFalse( create_user_in_db( GLOBAL_DB, $name, 'u_n', 'pw' ) );
			$this->assertStringContainsString( "Database name `$name` is too long for its grant to cover only this database", $this->stderr() );
			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `n\\_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5`.* TO 'u_n'@'%'; FLUSH PRIVILEGES;" ], $this->fixes() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_restricts_only_global_db_sites_with_a_pattern_name() {
			$this->global_site( 'my-site.com', 'my_site_com', 'u_a' );
			$this->global_site( 'plain.test', 'plaindb', 'u_plain' );
			$this->insert_site( [ 'site_url' => 'local.test', 'db_host' => 'db', 'db_name' => 'local_test', 'db_user' => 'u_local' ] );
			$this->insert_site( [ 'site_url' => 'remote.test', 'db_host' => 'db.example.com', 'db_name' => 'remote_test', 'db_user' => 'u_remote' ] );
			$this->insert_site( [ 'site_url' => 'html.test' ] );
			$this->global_site( 'gone.test', 'gone_test', 'u_gone' );
			$this->global_site( 'exact.test', 'exact_test', 'u_exact' );
			$this->user( 'u_a', [ 'my_site_com' => true ] );
			$this->user( 'u_exact', [ 'exact\\_test' => true ] );

			$this->migration()->up();

			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `my\\_site\\_com`.* TO 'u_a'@'%';\nREVOKE ALL PRIVILEGES ON `my_site_com`.* FROM 'u_a'@'%';\nFLUSH PRIVILEGES;" ], $this->fixes() );
			$reads = $this->reads();
			$this->assertCount( 1, $reads, 'one read for all sites' );
			$this->assertSame( 3, preg_match_all( "/^SELECT 'user'/m", $reads[0] ), 'my-site.com, gone.test and exact.test only' );
			$this->assertStringContainsString( "AND Db IN ('my\\\\_site\\\\_com', 'my_site_com')", $reads[0] );
			// The EE grant check is built from the columns the server reports.
			// Columns added after MariaDB 10.2 (here Delete_history_priv) may be 'N' on an upgraded datadir, so they are not checked.
			$this->assertStringContainsString( "IF(`Select_priv` = 'Y' AND `Drop_priv` = 'Y' AND `Grant_priv` = 'N', 1, 0)", $reads[0] );
			$this->assertStringNotContainsString( 'Delete_history_priv', $reads[0] );
			$this->assertStringContainsString( "WHERE BINARY SCHEMA_NAME LIKE 'my_site_com' AND SCHEMA_NAME <> 'my_site_com'", $reads[0] );
			$this->assertStringContainsString( 'Restricted the database grant of 1 site(s) to their own database.', $this->stdout() );
			$this->assertStringNotContainsString( 'Warning', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_revokes_the_cut_grant_of_a_long_name_and_warns_that_it_is_not_exact() {
			$name = 'l_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5';
			$cut  = substr( addcslashes( $name, '_' ), 0, 64 );
			$this->global_site( 'long.test', $name, 'u_long' );
			$this->user( 'u_long', [ $cut => true ] );

			$this->migration()->up();

			$this->assertStringContainsString( "'" . str_replace( '\\', '\\\\', $cut ) . "'", $this->reads()[0] );
			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `l\\_b_c_d_e_f_g_h_i_j_k_l_m_n_o_p_q_r_s_t_u_v_w_x_y_z_0_1_2_3_4_5`.* TO 'u_long'@'%';\nREVOKE ALL PRIVILEGES ON `$cut`.* FROM 'u_long'@'%';\nFLUSH PRIVILEGES;" ], $this->fixes() );
			$this->assertStringContainsString( 'The database grant of site long.test may still match other database names of the same length', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_warns_about_a_name_without_room_to_restrict() {
			$name = str_repeat( 'x', 63 ) . '_';
			$this->global_site( 'room.test', $name, 'u_room' );
			$this->user( 'u_room', [ $name => true ] );

			$this->migration()->up();

			$this->assertSame( [], $this->fixes() );
			$this->assertStringContainsString( 'The database grant of site room.test may still match other database names of the same length', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_leaves_a_customised_grant_alone() {
			$this->global_site( 'cust.test', 'c_one', 'u_c' );
			$this->user( 'u_c', [ 'c_one' => false ] );
			$this->global_site( 'cur.test', 'c_two', 'u_d' );
			$this->user(
				'u_d',
				[
					'c_two'   => true,
					'c\\_two' => false,
				]
			);

			$this->migration()->up();

			$this->assertSame( [], $this->fixes() );
			$this->assertStringContainsString( 'Did not restrict the database grant of site cust.test: the grants of database user u_c on `c_one` differ from the ALL PRIVILEGES grant EasyEngine creates.', $this->stderr() );
			$this->assertStringContainsString( "REVOKE GRANT OPTION ON `c_one`.* FROM 'u_c'@'%'", $this->stderr() );
			$this->assertStringContainsString( 'site cur.test: the grants of database user u_d on `c\\_two` differ', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_keeps_a_grant_that_another_site_of_the_same_user_relies_on() {
			$this->global_site( 'sh-a.test', 'sh_a', 'u_sh' );
			$this->global_site( 'shxa.test', 'shxa', 'u_sh' );
			$this->user( 'u_sh', [ 'sh_a' => true ], [ 'sh_a' => [ 'shxa', 'shya' ] ] );

			$this->migration()->up();

			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `sh\\_a`.* TO 'u_sh'@'%';\nFLUSH PRIVILEGES;" ], $this->fixes() );
			$this->assertStringContainsString( 'Kept the database grant of site sh-a.test on `sh_a`: it also covers the database of site(s) shxa.test, which use the same database user u_sh.', $this->stderr() );
			$this->assertStringNotContainsString( 'Restricted', $this->stdout() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_revokes_when_the_matches_belong_to_no_site_of_the_user() {
			$this->global_site( 's2-a.test', 's2_a', 'u_s2' );
			$this->global_site( 'other.test', 's2xa', 'u_other' );
			$this->user( 'u_s2', [ 's2_a' => true ], [ 's2_a' => [ 's2xa' ] ] );

			$this->migration()->up();

			$this->assertSame( [ "GRANT ALL PRIVILEGES ON `s2\\_a`.* TO 'u_s2'@'%';\nREVOKE ALL PRIVILEGES ON `s2_a`.* FROM 'u_s2'@'%';\nFLUSH PRIVILEGES;" ], $this->fixes() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_warns_about_a_user_that_exists_only_for_other_hosts() {
			$this->global_site( 'host.test', 'h_only', 'u_host' );
			$this->db['u_host'] = [
				'users'  => 1,
				'hosts'  => 0,
				'grants' => [],
			];

			$this->migration()->up();

			$this->assertSame( [], $this->fixes() );
			$this->assertStringContainsString( "site host.test: its database user u_host exists only for hosts other than '%'", $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_warns_with_the_sql_and_continues_when_a_site_fails() {
			$this->insert_site( [ 'site_url' => 'fail.test', 'db_host' => GLOBAL_DB, 'db_name' => 'fail_test', 'db_user' => 'u_fail', 'db_password' => 'SecretPw1' ] );
			$this->insert_site( [ 'site_url' => 'ok.test', 'db_host' => GLOBAL_DB, 'db_name' => 'ok_test', 'db_user' => 'u_ok', 'db_password' => 'SecretPw2' ] );
			$this->user( 'u_fail', [ 'fail_test' => true ] );
			$this->user( 'u_ok', [ 'ok_test' => true ] );
			$this->failing = [ 'u_fail' ];

			$this->migration()->up();

			$this->assertCount( 2, $this->fixes() );
			$this->assertStringContainsString( 'Could not restrict the database grant of site fail.test to its own database.', $this->stderr() );
			$this->assertStringContainsString( "REVOKE ALL PRIVILEGES ON `fail_test`.* FROM 'u_fail'@'%';", $this->stderr() );
			$this->assertStringNotContainsString( 'ok.test', $this->stderr() );
			$this->assertStringNotContainsString( 'SecretPw', $this->stderr() . $this->stdout() );
			$this->assertStringContainsString( 'Restricted the database grant of 1 site(s)', $this->stdout() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_warns_once_when_the_grants_cannot_be_read() {
			$this->global_site( 'my-site.com', 'my_site_com', 'u_a' );
			$this->global_site( 'b.test', 'b_test', 'u_b' );
			$this->read_fails = true;

			$this->migration()->up();

			$this->assertSame( [], $this->fixes() );
			$this->assertSame( 1, substr_count( $this->stderr(), 'Warning:' ) );
			$this->assertStringContainsString( 'site(s) my-site.com, b.test to their own database: their grants could not be read.', $this->stderr() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_only_warns_when_the_global_db_is_not_running() {
			$this->global_site( 'my-site.com', 'my_site_com', 'u_a' );
			$this->global_site( 'room.test', str_repeat( 'x', 63 ) . '_', 'u_room' );
			$this->global_site( 'sh-a.test', 'sh_a', 'u_sh' );
			$this->global_site( 'shxa.test', 'shxa', 'u_sh' );
			$this->runner->on( "/^docker inspect -f '\\{\\{\\.State\\.Running\\}\\}' services_global-db_1$/", "false\n" );

			$this->migration()->up();

			$this->assertSame( [], $this->sql );
			$this->assertSame( [], $this->runner->matching( '/^docker (start|restart|exec)|docker-compose/' ) );
			$this->assertSame( 1, substr_count( $this->stderr(), 'Warning:' ) );
			$this->assertStringContainsString( 'site(s) my-site.com, room.test, sh-a.test to their own database: the global database is not running.', $this->stderr() );
			$this->assertStringContainsString( "GRANT ALL PRIVILEGES ON `my\\_site\\_com`.* TO 'u_a'@'%';\nREVOKE ALL PRIVILEGES ON `my_site_com`.* FROM 'u_a'@'%';\n", $this->stderr() );
			$this->assertStringContainsString( "docker exec -it services_global-db_1 sh -c 'mysql -uroot -p\"\$MYSQL_ROOT_PASSWORD\"'", $this->stderr() );
			$this->assertStringContainsString( 'only for a user whose grant on the old name is still the ALL PRIVILEGES grant EasyEngine created', $this->stderr() );
			$this->assertStringContainsString( "shared with another site, so keep their old grant until they have a grant of their own on each site's database:\nGRANT ALL PRIVILEGES ON `sh\\_a`.* TO 'u_sh'@'%';\n", $this->stderr() );
			$this->assertStringNotContainsString( "FROM 'u_sh'@'%'", $this->stderr() );
			$this->assertStringNotContainsString( "'u_room'", $this->stderr(), 'no SQL for a name that cannot be restricted' );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_does_nothing_on_a_fresh_install() {
			Option::where( 'key', 'version' )[0]->delete();
			$this->global_site( 'my-site.com', 'my_site_com', 'u_a' );

			$this->migration()->up();

			$this->assertSame( [], $this->runner->commands() );
		}

		/**
		 * @group known-bug
		 * @group SEC-5
		 */
		public function test_migration_down_keeps_the_grant() {
			$this->migration()->down();

			$this->assertSame( [], $this->runner->commands() );
		}
	}
}
