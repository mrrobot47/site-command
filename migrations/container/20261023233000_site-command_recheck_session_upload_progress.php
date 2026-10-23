<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Site\Utils\add_session_upload_progress_off;
use function EE\Site\Utils\ini_global_value;
use function EE\Site\Utils\ini_sets_globally;

/**
 * Checks the custom.ini of every WordPress and PHP site again and turns off session.upload_progress where it is still not set globally.
 *
 * The v4.13.3 migrations took a setting inside a [PATH=]/[HOST=] section for a global one when the section header was quoted, shared its line with another header, was named like an earlier key, or ended with CRLF or CR, and they counted an array entry keyed by the directive too, so those sites kept PHP's default On.
 *
 * A global On or Off is the user's choice and is kept. Disabled sites pick the change up on their next start.
 */
class RecheckSessionUploadProgress extends Base {

	const SITE_TYPES = [ 'wp', 'php' ];

	const DIRECTIVE = 'session.upload_progress.enabled';

	/** @var Site[] */
	private $sites = [];

	/** @var array site_url => [ 'site' => Site, 'file' => string, 'backup' => string|null ] for every custom.ini changed by up(). */
	private $changed = [];

	/** @var array site_url => php container ID seen by up(), for running sites. */
	private $containers = [];

	/** @var string|null */
	private $backup_root;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;

			return;
		}

		$this->sites = array_filter(
			Site::all() ?: [],
			function ( $site ) {
				return in_array( $site->site_type, self::SITE_TYPES, true );
			}
		);
		if ( empty( $this->sites ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Add the setting to every custom.ini that doesn't set it globally.
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping recheck-session-upload-progress migration as it is not needed.' );

			return;
		}

		$this->backup_root = EE_BACKUP_DIR . '/session-upload-progress-recheck-' . date( 'Ymd-His' );
		if ( file_exists( $this->backup_root ) ) {
			$this->backup_root .= '-' . getmypid();
		}

		foreach ( $this->sites as $site ) {
			// One broken site must not block the upgrade of the others.
			try {
				$this->update_site( $site );
			} catch ( \Throwable $e ) {
				if ( isset( $this->changed[ $site->site_url ] ) ) {
					$this->restore_file( $site->site_url );
				}
				unset( $this->containers[ $site->site_url ] );
				EE::warning( "Could not check session.upload_progress in the custom.ini of $site->site_url: {$e->getMessage()}" );
			}
		}

		if ( empty( $this->changed ) ) {
			$this->remove_empty_backup_root();

			return;
		}

		$backed_up = array_filter( array_column( $this->changed, 'backup' ) );
		EE::log( 'Disabled session.upload_progress in custom.ini of ' . count( $this->changed ) . ' more site(s) where it was not set globally.' . ( $backed_up ? ' The original files are in ' . $this->backup_root : '' ) );

		// The image migration may recreate php containers, which then read the new file anyway; reload only the others, once it is done.
		if ( method_exists( '\EE\Migration\CustomContainerMigrations', 'keep_executed_migrations' ) ) {
			EE::add_hook( 'after_docker_image_migration', [ $this, 'reload_unchanged_containers' ] );
		} else {
			$this->reload_unchanged_containers();
		}
	}

	/**
	 * Restore the original custom.ini files and reload the running sites.
	 */
	public function down() {

		if ( empty( $this->changed ) ) {
			return;
		}

		$sites = array_column( $this->changed, 'site' );
		$this->restore_files();

		// A rolled-back image migration may have restarted them on the new file.
		foreach ( $sites as $site ) {
			if ( $site->site_enabled ) {
				$id = $this->get_php_container( $site );
				if ( null !== $id && '' !== $id ) {
					$this->reload_php( $site->site_url, $id );
				}
			}
		}
	}

	/**
	 * Gracefully reload php-fpm of the sites whose php container is still the one up() saw.
	 */
	public function reload_unchanged_containers() {

		foreach ( $this->containers as $site_url => $id ) {
			if ( ! isset( $this->changed[ $site_url ] ) ) {
				continue;
			}
			$now = $this->get_php_container( $this->changed[ $site_url ]['site'] );
			if ( $now !== $id ) {
				if ( null !== $now ) {
					EE::debug( "$site_url: php container was recreated or stopped, no reload needed." );
				}
				continue;
			}
			$this->reload_php( $site_url, $id );
		}
		$this->containers = [];
	}

	/**
	 * Whether PHP reads an ini value as true, like zend_ini_parse_bool(), which falls back to atoi().
	 *
	 * @param string $value Value as the ini scanner gives it.
	 *
	 * @return bool
	 */
	public static function is_on( string $value ) {

		return in_array( strtolower( $value ), [ 'true', 'yes', 'on' ], true ) || 1 === preg_match( '/^\s*[+-]?0*[1-9]/', $value );
	}

	/**
	 * Add the setting to one site's custom.ini. Problems with one site are warnings, so they don't block the upgrade.
	 *
	 * @param Site $site Site to check.
	 *
	 * @throws \Exception
	 */
	private function update_site( $site ) {

		$conf_dir = $site->site_fs_path . '/config/php/php/conf.d';
		$file     = $conf_dir . '/custom.ini';
		$manual   = "Add 'session.upload_progress.enabled = Off' to the top of $file and run: ee site reload $site->site_url";

		if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: custom.ini is not a regular file. $manual" );

			return;
		}

		$created = ! file_exists( $file );
		if ( $created && ! is_dir( $conf_dir ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: $conf_dir is missing. $manual" );

			return;
		}

		$handle = self::open_ini( $file, $created );
		try {
			$this->update_open_file( $site, $handle, $file, $created, $manual );
		} finally {
			fclose( $handle );
		}

		if ( $created && ! isset( $this->changed[ $site->site_url ] ) ) {
			@unlink( $file );
		}
		if ( ! isset( $this->changed[ $site->site_url ] ) ) {
			return;
		}

		if ( $created ) {
			// lchown: never follows a symlink swapped in by the container.
			@lchown( $file, fileowner( $conf_dir ) );
			@lchgrp( $file, filegroup( $conf_dir ) );
		}

		$id = $site->site_enabled ? $this->get_php_container( $site ) : '';
		if ( null !== $id && '' !== $id ) {
			$this->containers[ $site->site_url ] = $id;
		}
		EE::debug( "$site->site_url: disabled session.upload_progress in custom.ini." );
	}

	/**
	 * Read, back up and rewrite custom.ini, all through the handle from open_ini().
	 *
	 * @param Site     $site    Site.
	 * @param resource $handle  custom.ini handle.
	 * @param string   $file    custom.ini path.
	 * @param bool     $created Whether this migration created the file.
	 * @param string   $manual  Manual fix, for warnings.
	 *
	 * @throws \Exception
	 */
	private function update_open_file( $site, $handle, string $file, bool $created, string $manual ) {

		$content = stream_get_contents( $handle );
		if ( false === $content ) {
			throw new \Exception( 'could not read it' );
		}

		$new_content = add_session_upload_progress_off( $content );
		if ( null === $new_content ) {
			$value = ini_global_value( preg_replace( '/^\xEF\xBB\xBF/', '', $content ), self::DIRECTIVE );
			if ( null !== $value && self::is_on( $value ) ) {
				EE::log( "Kept session.upload_progress On for $site->site_url: its custom.ini turns it on. Set it to Off there unless your app needs it." );
			} else {
				EE::debug( "$site->site_url: custom.ini already turns off session.upload_progress." );
			}

			return;
		}

		if ( ! ini_sets_globally( preg_replace( '/^\xEF\xBB\xBF/', '', $new_content ), self::DIRECTIVE ) ) {
			EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not add it to custom.ini safely. $manual" );

			return;
		}

		$backup = null;
		if ( ! $created ) {
			$backup = $this->backup_root . '/' . $site->site_url . '/custom.ini';
			$stat   = fstat( $handle );
			try {
				$this->fs->mkdir( dirname( $backup ), 0700 );
				$this->fs->dumpFile( $backup, $content );
				$this->fs->chmod( $backup, $stat['mode'] & 0777 );
				$this->fs->touch( $backup, $stat['mtime'] );
			} catch ( \Throwable $e ) {
				EE::warning( "Skipped disabling session.upload_progress for $site->site_url: could not back up custom.ini ({$e->getMessage()}). $manual" );

				return;
			}
		}

		try {
			self::rewrite( $handle, $new_content );
		} catch ( \Exception $e ) {
			$restored = $created;
			if ( ! $created ) {
				try {
					self::rewrite( $handle, $content );
					$restored = true;
					$this->fs->remove( dirname( $backup ) );
				} catch ( \Throwable $e2 ) {
					EE::warning( "Could not restore $file; its original is $backup" );
				}
			}
			EE::warning( "Could not disable session.upload_progress for $site->site_url: could not write custom.ini. $manual" );
			if ( $restored ) {
				return;
			}
		}

		// Recorded only now, so a failed write that was undone above leaves nothing for down().
		$this->changed[ $site->site_url ] = [
			'site'   => $site,
			'file'   => $file,
			'backup' => $backup,
		];
	}

	/**
	 * Restore every file changed by up().
	 */
	private function restore_files() {

		foreach ( array_keys( $this->changed ) as $site_url ) {
			$this->restore_file( $site_url );
		}
		$this->containers = [];
		$this->remove_empty_backup_root();
	}

	/**
	 * Restore one file changed by up(); its backup is removed once restored. Never throws.
	 *
	 * @param string $site_url Site.
	 */
	private function restore_file( string $site_url ) {

		$entry = $this->changed[ $site_url ];
		unset( $this->changed[ $site_url ] );

		try {
			if ( null === $entry['backup'] ) {
				if ( ! @unlink( $entry['file'] ) && file_exists( $entry['file'] ) ) {
					throw new \Exception( 'could not remove it' );
				}

				return;
			}

			$content = @file_get_contents( $entry['backup'] );
			if ( false === $content ) {
				throw new \Exception( 'could not read the backup' );
			}
			$handle = self::open_ini( $entry['file'], false );
			try {
				// Skip the write when it never happened.
				if ( stream_get_contents( $handle ) !== $content ) {
					self::rewrite( $handle, $content );
				}
			} finally {
				fclose( $handle );
			}
			$this->fs->remove( dirname( $entry['backup'] ) );
			EE::debug( "$site_url: restored custom.ini." );
		} catch ( \Throwable $e ) {
			EE::warning( "Could not restore {$entry['file']} ({$e->getMessage()}). " . ( null === $entry['backup'] ? 'The session.upload_progress migration created it.' : "Its original is {$entry['backup']}" ) );
		}
	}

	private function remove_empty_backup_root() {

		try {
			if ( ! empty( $this->backup_root ) && is_dir( $this->backup_root ) && ! ( new \FilesystemIterator( $this->backup_root ) )->valid() ) {
				$this->fs->remove( $this->backup_root );
			}
		} catch ( \Throwable $e ) {
			EE::debug( "Could not remove the empty $this->backup_root: {$e->getMessage()}" );
		}
	}

	/**
	 * Opens custom.ini through one handle that every later read and write uses, so nothing swapped in at that path afterwards is followed.
	 * The directory is mounted into the site's php container, so nothing in it is trusted.
	 *
	 * @param string $file   custom.ini path.
	 * @param bool   $create Create it; fails when anything, even a dangling symlink, is there.
	 *
	 * @return resource
	 * @throws \Exception When the path is not a regular, singly linked file.
	 */
	private static function open_ini( string $file, bool $create ) {

		if ( $create ) {
			// fopen() follows a dangling symlink even with 'x', so create an unguessable name and link it into place: link() neither follows nor replaces anything there.
			$tmp    = dirname( $file ) . '/.custom.ini.ee-' . bin2hex( random_bytes( 8 ) );
			$umask  = umask( 022 );
			$handle = @fopen( $tmp, 'x+' );
			umask( $umask );
			if ( false !== $handle ) {
				$linked = @link( $tmp, $file );
				@unlink( $tmp );
				if ( ! $linked ) {
					fclose( $handle );
					$handle = false;
				}
			}
		} else {
			$handle = @fopen( $file, 'r+' );
		}
		if ( false === $handle ) {
			throw new \Exception( $create ? 'could not create custom.ini' : 'could not open custom.ini' );
		}

		// Swapped for a symlink around the open, or a hard link to another file.
		$opened = fstat( $handle );
		clearstatcache( true, $file );
		$linked = @lstat( $file );
		if ( ! $opened || ! $linked || $opened['dev'] !== $linked['dev'] || $opened['ino'] !== $linked['ino'] || 0100000 !== ( $linked['mode'] & 0170000 ) || 1 !== $opened['nlink'] ) {
			fclose( $handle );
			throw new \Exception( 'custom.ini is not a regular file' );
		}

		return $handle;
	}

	/**
	 * Overwrite the file through its handle: owner, mode and inode stay (macOS bind-mounts this single file).
	 *
	 * @param resource $handle  Handle from open_ini().
	 * @param string   $content New content.
	 *
	 * @throws \Exception When the file doesn't hold exactly $content afterwards.
	 */
	private static function rewrite( $handle, string $content ) {

		if ( ! rewind( $handle ) || strlen( $content ) !== fwrite( $handle, $content ) || ! fflush( $handle ) || ! ftruncate( $handle, strlen( $content ) ) || ! rewind( $handle ) || stream_get_contents( $handle ) !== $content ) {
			throw new \Exception( 'could not write custom.ini' );
		}
	}

	/**
	 * @param Site $site Site.
	 *
	 * @return string|null ID of the site's running php container, '' when none runs, null when the lookup failed (already warned).
	 */
	private function get_php_container( $site ) {

		$cwd    = getcwd();
		$result = @chdir( $site->site_fs_path ) ? EE::launch( \EE_DOCKER::docker_compose_with_custom() . ' ps -q php' ) : null;
		if ( false !== $cwd ) {
			chdir( $cwd );
		}

		if ( null === $result || 0 !== $result->return_code ) {
			EE::warning( "Could not check the php container of $site->site_url, so php-fpm may still run with the old session.upload_progress setting. Fix the error, then run: ee site reload $site->site_url" );

			return null;
		}

		return trim( $result->stdout );
	}

	/**
	 * Same graceful reload as `ee site reload`: php-fpm re-reads its ini files.
	 *
	 * @param string $site_url Site.
	 * @param string $id       php container ID.
	 */
	private function reload_php( string $site_url, string $id ) {

		$result = EE::launch( 'docker exec --user root ' . escapeshellarg( $id ) . " sh -c 'kill -USR2 1'" );
		if ( 0 === $result->return_code ) {
			EE::debug( "$site_url: reloaded php-fpm." );
		} else {
			EE::warning( "Could not reload php-fpm of $site_url. Run: ee site reload $site_url" );
		}
	}
}
