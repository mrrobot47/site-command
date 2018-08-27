<?php

use EE\Dispatcher\CommandFactory;

use EE\Model\Site;

class Site_Command {

	protected static $site_types = array();

	// Hold an instance of the class
	private static $instance;

	// The singleton method
	public static function instance() {

		if ( ! isset( self::$instance ) ) {
			self::$instance = new Site_Command();
		}

		return self::$instance;
	}

	public static function add_site_type( $name, $callback ) {
		self::$instance->site_types[ $name ] = $callback;
	}

	public static function get_site_types() {
		return self::$instance->site_types;
	}

	public function __invoke( $args, $assoc_args ) {

		$site_types = self::get_site_types();

		if ( isset( $assoc_args['type'] ) ) {
			$type = $assoc_args['type'];
			array_unshift( $args, 'site' );
			unset( $assoc_args['type'] );
		} else {
			$type = $this->determine_type( $args );
		}

		if ( ! isset( $site_types[ $type ] ) ) {
			$error = sprintf(
				"'%s' is not a registered site type of 'ee site --type=%s'. See 'ee help site --type=%s' for available subcommands.",
				$type,
				$type,
				$type
			);
			EE::error( $error );
		}

		$callback = $site_types[ $type ];

		$command      = EE::get_root_command();
		$leaf_command = CommandFactory::create( 'site', $callback, $command );
		$command->add_subcommand( 'site', $leaf_command );

		EE::run_command( $args, $assoc_args );
	}

	private function determine_type( $args ) {

		// default
		$type = 'html';

		$last_arg   = end( $args );
		$arg_search = Site::find( $last_arg, [ 'site_type' ] );

		if ( $arg_search ) {
			return $arg_search->site_type;
		}

		$site_name = EE\SiteUtils\get_site_name();
		if ( $site_name ) {
			// TODO: Add check for wrong site-name entry
			$type = Site::find( $site_name, [ 'site_type' ] )->site_type;
		}

		return $type;
	}
}
