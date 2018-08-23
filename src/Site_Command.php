<?php

use EE\Model\Site;

/**
 * Routes the site-types.
 *
 * @package ee-cli
 */
class Site_Command extends EE_Command {

	/**
	 * Routes the site-types.
	 */
	public function __invoke( $args, $assoc_args ) {
		if ( isset( $assoc_args['type'] ) ) {
			array_unshift( $args, $assoc_args['type'] );
			unset( $assoc_args['type'] );
		}

		$r = EE::get_runner()->find_command_to_run( $args, true );
		if ( is_string( $r ) ) {
			EE::error( $r );
		}

		list( $command, $final_args, $cmd_path ) = $r;

		$name = implode( ' ', $cmd_path );

		$extra_args = array();

		if ( isset( $this->extra_config[ $name ] ) ) {
			$extra_args = $this->extra_config[ $name ];
		}

		EE::debug( 'Running command: ' . $name, 'bootstrap' );
		try {
			$command->invoke( $final_args, $assoc_args, $extra_args );
		} catch ( EE\Iterators\Exception $e ) {
			EE::error( $e->getMessage() );
		}
	}

}
