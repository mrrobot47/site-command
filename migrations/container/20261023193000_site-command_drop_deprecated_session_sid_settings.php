<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

/**
 * Removes session.sid_length and session.sid_bits_per_character, deprecated as of PHP 8.4, from the php.ini of PHP 8.4 and 8.5 sites.
 *
 * A site's php.ini comes from its image at creation and from the version's archive on a PHP switch, so existing sites keep them otherwise.
 */
class DropDeprecatedSessionSidSettings extends Base {

	/** `latest` sites run the php8.4 image. */
	const PHP_VERSIONS = [ '8.4', '8.5', 'latest' ];

	/** Values EasyEngine shipped; any other value was set by the user. */
	const SHIPPED_VALUES = [
		'session.sid_length'             => '26',
		'session.sid_bits_per_character' => '5',
	];

	/** Comments shipped right above each directive, removed together with it. */
	const SHIPPED_COMMENTS = [
		'session.sid_length'             => [
			'; Set session ID character length. This value could be between 22 to 256.',
			'; Shorter length than default is supported only for compatibility reason.',
			'; Users should use 32 or more chars.',
			'; http://php.net/session.sid-length',
			'; Default Value: 32',
			'; Development Value: 26',
			'; Production Value: 26',
		],
		'session.sid_bits_per_character' => [
			'; Define how many bits are stored in each character when converting',
			'; the binary hash data to something readable.',
			'; Possible values:',
			';   4  (4 bits: 0-9, a-f)',
			';   5  (5 bits: 0-9, a-v)',
			';   6  (6 bits: 0-9, a-z, A-Z, "-", ",")',
			'; Default Value: 4',
			'; Development Value: 5',
			'; Production Value: 5',
			'; http://php.net/session.hash-bits-per-character',
		],
	];

	/** Entry of the quick reference at the top of the shipped php.ini. */
	const SHIPPED_QUICK_REFERENCE = [
		'; session.sid_bits_per_character',
		';   Default Value: 4',
		';   Development Value: 5',
		';   Production Value: 5',
	];

	private $sites;

	/** @var array Files changed by up(), restored by down(). */
	private $changed = [];

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping drop-deprecated-session-sid-settings migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) || ! in_array( (string) $site->php_version, self::PHP_VERSIONS, true ) ) {
				continue;
			}

			// One broken site must not block the upgrade of the others.
			try {
				$this->migrate_site( $site );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not remove the deprecated session ID settings from the php.ini of %s: %s', $site->site_url, $e->getMessage() ) );
			}
		}
	}

	/**
	 * Restores the files changed by up().
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		foreach ( array_reverse( $this->changed ) as $change ) {
			try {
				$old = file_get_contents( $change['backup'] );
				if ( false === $old ) {
					throw new \Exception( 'Could not read ' . $change['backup'] );
				}
				self::replace_file( $change['ini'], $old );
				$this->fs->remove( $change['backup'] );
				self::reload_fpm( $change['site'] );
			} catch ( \Throwable $e ) {
				EE::warning( sprintf( 'Could not restore the php.ini of %s from %s: %s', $change['site']->site_url, $change['backup'], $e->getMessage() ) );
			}
		}
		$this->changed = [];
	}

	/**
	 * @param Site $site PHP 8.4 or 8.5 site.
	 *
	 * @throws \Exception
	 */
	private function migrate_site( $site ) {

		$ini = $site->site_fs_path . '/config/php/php/php.ini';

		// Missing on macOS, where php.ini stays in the container.
		if ( is_link( $ini ) || ! is_file( $ini ) ) {
			EE::debug( "Skipping $site->site_url: no php.ini at $ini." );

			return;
		}

		$content = file_get_contents( $ini );
		if ( false === $content ) {
			throw new \Exception( "Could not read $ini." );
		}

		list( $new_content, $removed ) = self::strip_settings( $content );
		if ( $new_content === $content ) {
			EE::debug( "$ini has no deprecated session ID settings." );

			return;
		}

		$backup = EE_BACKUP_DIR . '/' . $site->site_url . '/php.ini.with-session-sid-settings';
		$this->fs->mkdir( dirname( $backup ) );
		$this->fs->copy( $ini, $backup, true );

		self::replace_file( $ini, $new_content );
		$this->changed[] = [
			'site'   => $site,
			'ini'    => $ini,
			'backup' => $backup,
		];
		EE::debug( "Removed the deprecated session ID settings from $ini; the previous file is $backup." );

		foreach ( $removed as $line => $shipped ) {
			if ( ! $shipped ) {
				EE::warning( sprintf( 'Removed `%s` from the php.ini of %s: it is deprecated as of PHP 8.4, and PHP now uses its default. The previous file is saved as %s.', $line, $site->site_url, $backup ) );
			}
		}

		self::reload_fpm( $site );
	}

	/**
	 * Removes the two directives, wherever they are set, and the shipped comments that document them.
	 * Every other line is kept byte for byte.
	 *
	 * @param string $content php.ini content.
	 *
	 * @return array New content, and the removed directive lines => whether the value is the shipped one.
	 */
	public static function strip_settings( $content ) {

		$lines = preg_split( '/(?<=\n)/', $content, -1, PREG_SPLIT_NO_EMPTY );
		$norm  = array_map(
			function ( $line ) {
				return rtrim( $line, " \t\r\n" );
			},
			$lines
		);
		$drop    = [];
		$removed = [];

		$quick_count = count( self::SHIPPED_QUICK_REFERENCE );
		foreach ( array_keys( $norm ) as $i ) {
			if ( array_slice( $norm, $i, $quick_count ) === self::SHIPPED_QUICK_REFERENCE ) {
				$drop += array_fill( $i, $quick_count, true );
				if ( isset( $norm[ $i + $quick_count ] ) && '' === $norm[ $i + $quick_count ] ) {
					$drop[ $i + $quick_count ] = true;
				}
			}
		}

		foreach ( $norm as $i => $line ) {
			if ( ! preg_match( '/^\s*(session\.sid_length|session\.sid_bits_per_character)\s*=(.*)$/', $line, $matches ) ) {
				continue;
			}
			$name       = $matches[1];
			$drop[ $i ] = true;

			// Inline comment and quotes don't change the value.
			$value                    = trim( preg_replace( '/;.*$/', '', $matches[2] ), " \t\"'" );
			$removed[ trim( $line ) ] = self::SHIPPED_VALUES[ $name ] === $value;

			$comments = self::SHIPPED_COMMENTS[ $name ];
			$count    = count( $comments );
			if ( $i >= $count && array_slice( $norm, $i - $count, $count ) === $comments ) {
				$drop += array_fill( $i - $count, $count, true );
				if ( isset( $norm[ $i + 1 ] ) && '' === $norm[ $i + 1 ] ) {
					$drop[ $i + 1 ] = true;
				}
			}
		}

		return [ implode( '', array_diff_key( $lines, $drop ) ), $removed ];
	}

	/**
	 * Replaces the file only once the new content is complete, keeping its mode and owner.
	 *
	 * @param string $file    File to replace.
	 * @param string $content New content.
	 *
	 * @throws \Exception
	 */
	private static function replace_file( $file, $content ) {

		$tmp = $file . '.ee-tmp';
		if ( strlen( $content ) !== file_put_contents( $tmp, $content )
			|| ! chmod( $tmp, fileperms( $file ) & 0777 )
			|| ! chown( $tmp, fileowner( $file ) )
			|| ! chgrp( $tmp, filegroup( $file ) )
			|| ! rename( $tmp, $file ) ) {
			if ( is_file( $tmp ) ) {
				unlink( $tmp );
			}
			throw new \Exception( "Could not write $file." );
		}
	}

	/**
	 * PHP-FPM reads php.ini only when it starts; a graceful reload (as `ee site reload --php` does) applies it.
	 * Stopped containers read it when they start.
	 *
	 * @param Site $site Site.
	 */
	private static function reload_fpm( $site ) {

		if ( ! $site->site_enabled || ! chdir( $site->site_fs_path ) ) {
			return;
		}

		$compose = \EE_DOCKER::docker_compose_with_custom();
		$id      = trim( EE::launch( "$compose ps -q php" )->stdout );
		if ( '' === $id || 'running' !== \EE_DOCKER::container_status( $id ) ) {
			EE::debug( "The php container of $site->site_url is not running." );

			return;
		}

		if ( EE::launch( "$compose exec -T php bash -c 'kill -USR2 1'" )->return_code ) {
			EE::warning( sprintf( 'Could not reload PHP-FPM of %1$s. Run `ee site restart %1$s --php` to apply its new php.ini.', $site->site_url ) );
		}
	}
}
