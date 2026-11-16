<?php

use EE\Site\Tests\SiteTestCase;
use EE\Site\Type\Site_Backup_Restore;

/**
 * Backup ids, remote listing and retention, rclone tuning and the pre-checks (src/helper/Site_Backup_Restore.php).
 */
class BackupRestoreTest extends SiteTestCase {

	protected $reset_db = false;

	const OLDEST = '1790000000_2026-09-21-14-13-20';

	/** @return string[] Backup ids, newest first, one day apart. */
	private static function ids( $count ) {
		$ids = [];
		for ( $i = $count - 1; $i >= 0; $i-- ) {
			$time  = 1790000000 + $i * 86400;
			$ids[] = $time . '_' . gmdate( 'Y-m-d-H-i-s', $time );
		}

		return $ids;
	}

	private function backup( $site_url = 'bk.test', array $site = [] ) {
		$backup            = new Site_Backup_Restore();
		$backup->site_data = $site + [ 'site_url' => $site_url, 'site_type' => 'html' ];

		return $backup;
	}

	/** Answer `rclone lsf --dirs-only` the way rclone lists directories. */
	private function remote_has( array $ids, $extra = '' ) {
		$this->runner->on( '/^rclone lsf --dirs-only /', implode( '', array_map( function ( $id ) {
			return "$id/\n";
		}, array_reverse( $ids ) ) ) . $extra );
	}

	public function test_list_remote_backups_returns_backup_dirs_newest_first() {
		$ids = self::ids( 3 );
		$this->remote_has( $ids, "not-a-backup/\n2026-09-21/\n" );

		$this->assertSame( $ids, self::call_private( $this->backup(), 'list_remote_backups', true ) );
		$this->assertSame( [ "rclone lsf --dirs-only 'easyengine:easyengine/bk.test'" ], $this->runner->commands() );
	}

	public function test_list_remote_backups_uses_the_configured_rclone_path() {
		$this->write_ee_config( [ 'rclone-path' => 'remote:bucket/host/' ] );
		self::call_private( $this->backup(), 'list_remote_backups', true );

		$this->assertSame( [ "rclone lsf --dirs-only 'remote:bucket/host/bk.test'" ], $this->runner->commands() );
	}

	public function test_list_remote_backups_prints_the_ids() {
		$ids = self::ids( 2 );
		$this->remote_has( $ids );
		self::call_private( $this->backup(), 'list_remote_backups' );

		$this->assertSame( $ids[0] . "\n" . $ids[1] . "\n", $this->stdout() );
	}

	public function test_list_remote_backups_reports_rclone_errors() {
		$this->runner->on( '/^rclone lsf /', '', 3, 'directory not found' );

		$this->assertSame( [], self::call_private( $this->backup(), 'list_remote_backups', true ) );
		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'list_remote_backups' );
			},
			'Error listing remote backups: directory not found'
		);
	}

	/**
	 * SC-11: `explode( PHP_EOL, trim( '' ) )` is `['']`, so the empty check never fires and `--list` prints nothing.
	 *
	 * @group known-bug
	 * @group SC-11
	 */
	public function test_list_remote_backups_says_when_there_are_none() {
		$this->runner->on( '/^rclone lsf --dirs-only /', '' );
		self::call_private( $this->backup(), 'list_remote_backups' );

		$this->assertStringContainsString( 'No remote backups found.', $this->stdout() );
	}

	public function test_verify_backup_id_accepts_only_listed_ids() {
		$ids = self::ids( 2 );
		$this->remote_has( $ids );

		$this->assertTrue( self::call_private( $this->backup(), 'verify_backup_id', $ids[1] ) );
		$this->assertFalse( self::call_private( $this->backup(), 'verify_backup_id', '1790000000' ) );
		$this->assertFalse( self::call_private( $this->backup(), 'verify_backup_id', $ids[1] . '/' ) );
	}

	public function test_new_backup_id_is_a_listable_timestamp() {
		$this->remote_has( self::ids( 1 ) );
		$before = time();
		$path   = self::call_private( $this->backup(), 'get_remote_path' );

		$this->assertMatchesRegularExpression( '#^easyengine:easyengine/bk\.test/(\d+)_\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}$#', $path );
		preg_match( '#/(\d+)_([\d-]+)$#', $path, $m );
		$this->assertGreaterThanOrEqual( $before, (int) $m[1] );
		$this->assertSame( date( 'Y-m-d-H-i-s', (int) $m[1] ), $m[2] );

		// The id must survive the --list filter, or restore and retention would never see it.
		$this->runner->reset();
		$this->runner->on( '/^rclone lsf --dirs-only /', basename( $path ) . "/\n" );
		$this->assertSame( [ basename( $path ) ], self::call_private( $this->backup(), 'list_remote_backups', true ) );
	}

	public function test_restore_path_is_the_latest_backup_and_is_cached() {
		$ids = self::ids( 3 );
		$this->remote_has( $ids );
		$backup = $this->backup();

		$this->assertSame( 'easyengine:easyengine/bk.test/' . $ids[0], self::call_private( $backup, 'get_remote_path', false ) );
		$this->assertStringContainsString( 'Restoring from backup: ' . $ids[0], $this->stdout() );
		$this->assertSame( 'easyengine:easyengine/bk.test/' . $ids[0], self::call_private( $backup, 'get_remote_path', true ) );
		$this->assertCount( 1, $this->runner->commands() );
	}

	/**
	 * BKP-5: the id has one-second resolution, so two backups started in the same second share a remote directory.
	 *
	 * @group known-bug
	 * @group BKP-5
	 */
	public function test_backups_started_in_the_same_second_get_different_ids() {
		do {
			$second = time();
			$first  = self::call_private( $this->backup(), 'get_remote_path' );
			$other  = self::call_private( $this->backup(), 'get_remote_path' );
		} while ( time() !== $second );

		$this->assertNotSame( $first, $other );
	}

	/**
	 * @dataProvider retention_cases
	 */
	public function test_cleanup_old_backups_purges_the_oldest_beyond_the_limit( $keep, $count, $expected_purged ) {
		$this->write_ee_config( [ 'no-of-backups' => $keep ] );
		$ids = self::ids( $count );
		$this->remote_has( $ids );

		self::call_private( $this->backup(), 'cleanup_old_backups' );

		$purged = array_map(
			function ( $command ) {
				preg_match( "#^rclone purge 'easyengine:easyengine/bk\.test'/'([^']+)'$#", $command, $m );

				return $m[1];
			},
			$this->runner->matching( '/^rclone purge /' )
		);
		$this->assertSame( array_slice( $ids, $count - $expected_purged ), $purged );
	}

	public function retention_cases() {
		return [
			'under the limit'       => [ 3, 2, 0 ],
			'two over'              => [ 3, 5, 2 ],
			'default 7, nine there' => [ '', 9, 2 ],
			'keep 1 of 3'           => [ 1, 3, 2 ],
		];
	}

	public function test_cleanup_old_backups_reports_each_purge() {
		$this->write_ee_config( [ 'no-of-backups' => 1 ] );
		$ids = self::ids( 3 );
		$this->remote_has( $ids );
		$this->runner->on( '/^rclone purge .*' . self::OLDEST . '/', '', 1 );

		self::call_private( $this->backup(), 'cleanup_old_backups' );

		$this->assertStringContainsString( 'Deleting old backup: ' . $ids[1], $this->stdout() );
		$this->assertStringContainsString( 'Failed to delete old backup: ' . self::OLDEST, $this->stderr() );
		$this->assertStringContainsString( 'Cleaned up 2 old backup(s).', $this->stdout() );
	}

	/**
	 * SC-10: pruning only starts above `no-of-backups + 1`, so the remote keeps one backup too many every other run.
	 *
	 * @group known-bug
	 * @group SC-10
	 */
	public function test_cleanup_old_backups_keeps_exactly_the_limit() {
		$this->write_ee_config( [ 'no-of-backups' => 3 ] );
		$this->remote_has( self::ids( 4 ) );

		self::call_private( $this->backup(), 'cleanup_old_backups' );

		$this->assertSame( [ "rclone purge 'easyengine:easyengine/bk.test'/'" . self::OLDEST . "'" ], $this->runner->matching( '/^rclone purge /' ) );
	}

	/**
	 * BKP-7: a non-numeric `no-of-backups` (a typo) becomes intval() 0 and the prune deletes every backup, the new one included.
	 *
	 * @group known-bug
	 * @group BKP-7
	 */
	public function test_cleanup_old_backups_never_purges_the_newest_backup() {
		$this->write_ee_config( [ 'no-of-backups' => 'three' ] );
		$ids = self::ids( 3 );
		$this->remote_has( $ids );

		self::call_private( $this->backup(), 'cleanup_old_backups' );

		$this->assertSame( [], $this->runner->matching( '/' . preg_quote( $ids[0], '/' ) . '/' ) );
	}

	public function test_pre_checks_require_rclone_and_the_configured_remote() {
		$this->runner->on( '/^rclone --version$/', '', 127 );
		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'pre_backup_restore_checks' );
			},
			'rclone is not installed.'
		);

		$this->runner->reset();
		$this->runner->on( '/^rclone listremotes$/', "gdrive:\n" );
		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'pre_backup_restore_checks' );
			},
			'rclone backend "easyengine" does not exist.'
		);
	}

	public function test_pre_checks_take_the_site_lock_once() {
		$this->runner->on( '/^rclone listremotes$/', "easyengine:\n" );
		$lock = $this->track( EE_BACKUP_DIR . '/lock.test.lock' );

		self::call_private( $this->backup( 'lock.test' ), 'pre_backup_restore_checks' );
		$this->assertStringEqualsFile( $lock, 'lock' );
		$this->assertSame( [], $this->runner->matching( '/apt-get/' ), 'Tools present: nothing installed.' );

		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup( 'lock.test' ), 'pre_backup_restore_checks' );
			},
			'Another backup/restore process is running.'
		);
	}

	/**
	 * BKP-12: the remote check is a substring match, so a remote named `myeasyengine:` satisfies `easyengine:`.
	 *
	 * @group known-bug
	 * @group BKP-12
	 */
	public function test_pre_checks_match_the_remote_name_exactly() {
		$this->runner->on( '/^rclone listremotes$/', "myeasyengine:\n" );
		$this->track( EE_BACKUP_DIR . '/bk.test.lock' );

		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'pre_backup_restore_checks' );
			},
			'rclone backend "easyengine" does not exist.'
		);
	}

	public function test_missing_tools_are_installed_with_apt() {
		$this->runner->on( '/^command -v 7z$/', '', 1 );
		self::call_private( $this->backup(), 'check_and_install', '7z', 'p7zip-full' );
		self::call_private( $this->backup(), 'check_and_install', 'zip', 'zip' );

		$this->assertSame( [ 'command -v 7z', 'apt-get --version', 'apt-get update', 'apt-get install -y p7zip-full', 'command -v zip' ], $this->runner->commands() );
	}

	public function test_missing_tools_without_apt_are_an_error() {
		$this->runner->on( '/^(command -v rsync|apt-get --version)$/', '', 1 );
		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'check_and_install', 'rsync', 'rsync' );
			},
			'rsync is not installed. Please install rsync for backup/restore.'
		);
	}

	/**
	 * BKP-3: `apt-get install -y` without DEBIAN_FRONTEND=noninteractive can open a needrestart dialog and hang cron backups.
	 *
	 * @group known-bug
	 * @group BKP-3
	 */
	public function test_tool_install_is_non_interactive() {
		$this->runner->on( '/^command -v zip$/', '', 1 );
		self::call_private( $this->backup(), 'check_and_install', 'zip', 'zip' );

		$install = $this->runner->matching( '/apt-get install/' );
		$this->assertCount( 1, $install );
		$this->assertStringContainsString( 'DEBIAN_FRONTEND=noninteractive', $install[0] );
	}

	/**
	 * @dataProvider rclone_resource_cases
	 */
	public function test_compute_rclone_resources( $cores, $ram, $is_s3, $config, $expected ) {
		$this->write_ee_config( $config );
		$result = self::call_private( $this->backup(), 'compute_rclone_resources', $cores, $ram, $is_s3 );

		$this->assertSame( $expected, array_intersect_key( $result, $expected ) );
		$this->assertLessThanOrEqual(
			max( $result['budget'], $result['transfers'] * ( 16 + $result['s3_chunk_size'] * $result['s3_concurrency'] ) ),
			$result['transfers'] * ( $result['buffer_size'] + $result['s3_chunk_size'] * $result['s3_concurrency'] ),
			'The footprint stays within the budget whenever the minimum fits.'
		);
	}

	public function rclone_resource_cases() {
		return [
			'4 cores 4G'          => [ 4, 4000, false, [], [ 'transfers' => 4, 'buffer_size' => 256, 's3_concurrency' => 0, 'budget' => 2000 ] ],
			'8 cores 4G s3'       => [ 8, 4000, true, [], [ 'transfers' => 8, 'buffer_size' => 122, 's3_concurrency' => 2, 's3_chunk_size' => 64 ] ],
			'tiny s3'             => [ 1, 100, true, [], [ 'transfers' => 1, 'buffer_size' => 16, 's3_concurrency' => 1, 'budget' => 50 ] ],
			'no ram info'         => [ 0, 0, false, [], [ 'transfers' => 1, 'buffer_size' => 16, 'budget' => 0 ] ],
			'fraction clamped'    => [ 2, 1000, false, [ 'rclone-mem-fraction' => '5' ], [ 'transfers' => 2, 'buffer_size' => 256, 'budget' => 900 ] ],
			'bigger max buffer'   => [ 2, 1000, false, [ 'rclone-mem-fraction' => '5', 'rclone-max-buffer-size' => 1024 ], [ 'buffer_size' => 450, 'max_buffer' => 1024 ] ],
			'many cores capped 8' => [ 64, 64000, false, [], [ 'transfers' => 8, 'buffer_size' => 256 ] ],
		];
	}

	/**
	 * @dataProvider rclone_versions
	 */
	public function test_download_command_gates_flags_on_the_rclone_version( $version, $flags ) {
		$this->answer_host_resources( 4, 4000, $version );
		$ids = self::ids( 1 );
		$this->remote_has( $ids );

		self::call_private( $this->backup(), 'rclone_download', '/backup/dir' );

		$this->assertSame(
			[ "rclone copy -P --transfers 4 --buffer-size 250M --multi-thread-streams 3$flags 'easyengine:easyengine/bk.test/{$ids[0]}' '/backup/dir'" ],
			$this->runner->matching( '/^rclone copy /' )
		);
		$this->assertStringContainsString( 'Backup downloaded from remote storage.', $this->stdout() );
	}

	public function rclone_versions() {
		return [
			'new'           => [ 'v1.66.0', ' --multi-thread-write-buffer-size 128Ki --multi-thread-chunk-size 64M' ],
			'write buffer'  => [ 'v1.63.1', ' --multi-thread-write-buffer-size 128Ki' ],
			'old'           => [ 'v1.62.2', '' ],
			'unparseable'   => [ 'rclone-dev', '' ],
		];
	}

	/**
	 * The download's read-ahead buffers plus multi-thread streams must fit the one memory budget.
	 *
	 * @dataProvider host_sizes
	 */
	public function test_download_footprint_fits_the_budget( $cores, $ram ) {
		$this->answer_host_resources( $cores, $ram, 'v1.66.0' );
		$this->remote_has( self::ids( 1 ) );

		self::call_private( $this->backup(), 'rclone_download', '/backup/dir' );

		preg_match( '/--transfers (\d+) --buffer-size (\d+)M --multi-thread-streams (\d+)/', $this->runner->matching( '/^rclone copy /' )[0], $m );
		list( , $transfers, $buffer, $streams ) = array_map( 'intval', $m );
		$budget = (int) floor( $ram * 0.5 );
		$this->assertLessThanOrEqual( max( $budget, 16 + 65 ), $transfers * ( $buffer + $streams * ( 128 / 1024 + 64 ) ) );
		$this->assertGreaterThanOrEqual( 1, $streams );
		$this->assertLessThanOrEqual( min( $cores * 2, 32 ), $streams );
	}

	public function host_sizes() {
		return [ [ 1, 512 ], [ 2, 600 ], [ 4, 4000 ], [ 8, 16000 ], [ 16, 65536 ], [ 2, 100 ] ];
	}

	public function test_download_failure_is_an_error() {
		$this->answer_host_resources( 2, 2000, 'v1.66.0' );
		$this->remote_has( self::ids( 1 ) );
		$this->runner->on( '/^rclone copy /', '', 1 );

		$this->assert_ee_error(
			function () {
				self::call_private( $this->backup(), 'rclone_download', '/backup/dir' );
			},
			'Error downloading backup from remote storage.'
		);
	}

	public function test_rclone_version_and_free_ram_parsing() {
		$this->runner->on( "/^rclone version /", "v1.66.0\n" );
		$this->runner->on( '/free -m/', "3712\n" );

		$this->assertSame( '1.66.0', self::call_private( $this->backup(), 'get_rclone_version' ) );
		$this->assertSame( 3712, self::call_private( $this->backup(), 'get_available_ram_mb' ) );

		$this->runner->on( "/^rclone version /", "\n" );
		$this->assertSame( '', self::call_private( $this->backup(), 'get_rclone_version' ) );
	}

	public function test_s3_detection_reads_the_type_of_the_configured_remote() {
		$this->write_ee_config( [ 'rclone-path' => 'wasabi:bucket/ee' ] );
		$this->runner->on( "/^rclone config show 'wasabi' /", "s3\n" );
		$this->assertTrue( self::call_private( $this->backup(), 'is_s3_remote' ) );

		$this->runner->on( "/^rclone config show 'wasabi' /", "drive\n" );
		$this->assertFalse( self::call_private( $this->backup(), 'is_s3_remote' ) );
	}

	/**
	 * @dataProvider byte_sizes
	 */
	public function test_format_bytes( $bytes, $expected ) {
		$this->assertSame( $expected, self::call_private( $this->backup(), 'format_bytes', $bytes ) );
	}

	public function byte_sizes() {
		return [
			[ 0, '0 B' ],
			[ -5, '0 B' ],
			[ 1023, '1023 B' ],
			[ 1536, '1.5 KB' ],
			[ 1073741824, '1 GB' ],
			[ 5 * 1024 ** 5, '5120 TB' ],
		];
	}

	public function test_disk_space_message_shows_the_shortfall() {
		$message = self::call_private( $this->backup(), 'build_disk_space_error_message', 'backup', 3 * 1024 ** 3, 1024 ** 3 );

		$this->assertStringStartsWith( "Not enough disk space to take backup.\nRequired: 3 GB (3,221,225,472 bytes)\nAvailable: 1 GB (1,073,741,824 bytes)\nAdditional space needed: 2 GB (2,147,483,648 bytes)", $message );
	}

	public function test_sanitize_count_and_first_error_capture() {
		$backup = $this->backup();
		$this->assertSame( 0, self::call_private( $backup, 'sanitize_count', '-' ) );
		$this->assertSame( 0, self::call_private( $backup, 'sanitize_count', 'n/a' ) );
		$this->assertSame( 12, self::call_private( $backup, 'sanitize_count', '12' ) );
		$this->assertSame( 3, self::call_private( $backup, 'sanitize_count', 3.9 ) );

		self::call_private( $backup, 'capture_error', 'rclone is not installed', 'configuration_error', 2001 );
		self::call_private( $backup, 'capture_error', 'later failure', 'unknown_error', 1 );
		$this->assertSame( 'rclone is not installed', self::get_property( $backup, 'dash_error_message' ) );
		$this->assertSame( 2001, self::get_property( $backup, 'dash_error_code' ) );
	}

	public function test_db_size_query_reads_the_second_line_of_mysql_output() {
		$this->track( WEBROOT . 'db.test' );
		$this->runner->on( '/^ee shell db\.test /', "Database\tSize (Bytes)\ndb_name\t4194304\n" );
		$backup = $this->backup( 'db.test', [ 'db_user' => 'u', 'db_password' => 'p', 'db_host' => 'global-db', 'db_name' => 'db_name' ] );

		$this->assertSame( 4194304, self::call_private( $backup, 'get_db_size' ) );
		$this->assertFileDoesNotExist( WEBROOT . 'db.test/app/htdocs/db_size_query.sql', 'The query file is removed again.' );
	}

	/**
	 * BKP-15 (new): the escapeshellarg()-quoted DB credentials sit inside the double-quoted `--command="…"`, so `$(…)` in a password runs locally.
	 *
	 * @group known-bug
	 * @group BKP-15
	 */
	public function test_db_size_query_is_shell_safe() {
		$this->track( WEBROOT . 'db.test' );
		$this->assert_shell_safe(
			function ( $password ) {
				self::call_private( $this->backup( 'db.test', [ 'db_user' => 'u', 'db_password' => $password, 'db_host' => 'global-db', 'db_name' => 'db_name' ] ), 'get_db_size' );
			},
			'/^ee shell /'
		);
	}

	private function answer_host_resources( $cores, $ram, $rclone_version ) {
		$this->runner->on( '/^nproc$/', "$cores\n" );
		$this->runner->on( '/free -m/', "$ram\n" );
		$this->runner->on( '/^rclone version /', "$rclone_version\n" );
	}
}
