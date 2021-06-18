<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class DisableStaticAssetLog extends Base {

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

	/**
	 * Execute nginx config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping nginx-config update migration as it is not needed.' );

			return;
		}

		foreach ( $this->sites as $site ) {
			$main_conf_file = $site->site_fs_path . '/config/nginx/conf.d/main.conf';

			if ( file_exists( $main_conf_file ) ) {
				$main_conf_file_contents     = file_get_contents( $main_conf_file );
				$main_conf_file_new_contents = preg_replace( '/(location[^\}\n\r]+css.*\|js.+)({)(.+})/msU', "$1{\n\t\taccess_log off;$3", $main_conf_file_contents );

				file_put_contents( $main_conf_file, $main_conf_file_new_contents );
			}

			if ( $site->cache_nginx_browser ) {
				$nginx_conf_file          = $site->site_fs_path . '/config/nginx/nginx.conf';
				$nginx_conf_file_contents = file_get_contents( $nginx_conf_file );

				$replacement = <<<EOF
\$1 '\$remote_addr \$srcache_fetch_status [\$time_local] '\n    '\$http_host "\$request" \$status \$body_bytes_sent '\n    '"\$http_referer" "\$http_user_agent"'\n    '\$upstream_response_time \$request_time';
EOF;

				$nginx_conf_file_new_contents = preg_replace( '/(log_format rt_cache)(.+);/msU', $replacement, $nginx_conf_file_contents );

				file_put_contents( $nginx_conf_file, $nginx_conf_file_new_contents );
			}
		}
	}

	/**
	 * Bring back the existing old nginx config.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
