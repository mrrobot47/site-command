<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class AddExtConfig8_1 extends Base {

	private $sites;
	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		}
	}

	public function remove_extension( $config_file_tmp, $config_site_path ) {
		if ( $this->fs->exists( $config_file_tmp ) ) {
			$this->fs->remove( $config_file_tmp );
		}

		if ( $this->fs->exists( $config_site_path ) ) {
			$this->fs->remove( $config_site_path );
		}
	}

	public function add_extension( $file_name, $extension_name, $site_fs_path ) {

		$config_file_tmp = EE\Utils\trailingslashit( EE_BACKUP_DIR ) . $file_name;
		$this->fs->dumpFile( $config_file_tmp, "extension=$extension_name" );

		$config_site_path = $site_fs_path . '/config/php/php/conf.d/' . $file_name;

		self::$rsp->add_step(
			"to-$site_fs_path-add-$file_name",
			'EE\Migration\SiteContainers::backup_restore',
			[ $this, 'remove_extension' ],
			[ $config_file_tmp, $config_site_path ],
			[ $config_file_tmp, $config_site_path ]
		);

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run add-ext-config-8.1 upadte migrations.' );
		}

		$this->fs->remove( $config_file_tmp );

	}

	/**
	 * Execute php config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-ext-config-8.1 update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( $site->php_version != '8.1' ) {
				continue;
			}

			EE::debug( "Found site: $site->site_url of type: $site->site_type" );
			EE::debug( "Starting add-ext-config-8.1 updates for: $site->site_url" );

			$this->add_extension(
				'docker-php-ext-timezonedb.ini',
				'timezonedb',
				$site->site_fs_path
			);

			$this->add_extension(
				'docker-php-ext-apcu.ini',
				'apcu',
				$site->site_fs_path
			);

			$this->add_extension(
				'docker-php-ext-mcrypt.ini',
				'mcrypt',
				$site->site_fs_path
			);

			$this->add_extension(
				'docker-php-ext-redis.ini',
				'redis',
				$site->site_fs_path
			);

		}
	}

	/**
	 * Bring back the existing old php config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping add-ext-config-8.1 update migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {

			if ( ! in_array( $site->site_type, [ 'php', 'wp' ], true ) ) {
				continue;
			}

			if ( '8.1' != $site->php_version ) {
				continue;
			}

			EE::debug( "Reverting add-ext-config updates for: $site->site_url" );

			$configs = [
				$site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-timezonedb.ini',
				$site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-apcu.ini',
				$site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-mcrypt.ini',
				$site->site_fs_path . '/config/php/php/conf.d/docker-php-ext-redis.ini',
			];

			foreach ( $configs as $config ) {

				if ( $this->fs->exists( $config ) ) {
					$this->fs->remove( $config );
				}
			}
		}
	}

}
