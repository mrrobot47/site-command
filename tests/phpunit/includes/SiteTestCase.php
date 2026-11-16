<?php

namespace EE\Site\Tests;

use EE\Tests\TestCase;

/**
 * Base class for site-command unit tests: command objects without their constructor, isolated hooks, a temporary config.yml
 * and removal of the files a test makes the code under test write.
 */
abstract class SiteTestCase extends TestCase {

	/** @var string[] Paths under EE_ROOT_DIR that the test created or made the code under test create. */
	private $tracked = [];

	private $hooks_backup;

	/** @var string|null One RSA key per process; generating it takes most of a test's time. */
	private static $account_key_pem;

	protected function setUp(): void {
		parent::setUp();
		// Other packages hook into site events (auth-command on alias changes, cron on cleanup); test site-command alone.
		$this->hooks_backup = [ self::get_static( 'EE', 'hooks' ), self::get_static( 'EE', 'hooks_passed' ) ];
		self::set_static( 'EE', 'hooks', [] );
		self::set_static( 'EE', 'hooks_passed', [] );
	}

	protected function tearDown(): void {
		self::set_static( 'EE', 'hooks', $this->hooks_backup[0] );
		self::set_static( 'EE', 'hooks_passed', $this->hooks_backup[1] );
		$config = getenv( 'EE_CONFIG_PATH' );
		if ( $config && file_exists( $config ) ) {
			unlink( $config );
		}
		( new \Symfony\Component\Filesystem\Filesystem() )->remove( $this->tracked );
		$this->tracked = [];
		parent::tearDown();
	}

	/**
	 * Remove $path after the test; it must be inside the per-process EE_ROOT_DIR.
	 *
	 * @return string $path
	 */
	protected function track( $path ) {
		if ( 0 !== strpos( $path, EE_ROOT_DIR . '/' ) ) {
			throw new \LogicException( "Refusing to track a path outside EE_ROOT_DIR: $path" );
		}
		$this->tracked[] = $path;

		return $path;
	}

	/**
	 * Record the arguments of every call to hook $name.
	 *
	 * @return \ArrayObject List of argument arrays.
	 */
	protected function capture_hook( $name, ?callable $also = null ) {
		$calls = new \ArrayObject();
		\EE::add_hook(
			$name,
			function () use ( $calls, $also ) {
				$calls[] = func_get_args();
				if ( $also ) {
					call_user_func_array( $also, func_get_args() );
				}
			}
		);

		return $calls;
	}

	/** @return string A 2048-bit RSA private key PEM, generated once per process. */
	protected static function rsa_key_pem() {
		if ( null === self::$account_key_pem ) {
			openssl_pkey_export( openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] ), self::$account_key_pem );
		}

		return self::$account_key_pem;
	}

	/**
	 * Store an ACME account key where Site_Letsencrypt looks, so constructing it doesn't generate a 4096-bit key.
	 */
	protected function seed_acme_account_key() {
		$dir = $this->track( EE_SERVICE_DIR . '/nginx-proxy' ) . '/acme-conf/account';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		$private = self::rsa_key_pem();
		file_put_contents( "$dir/key.private.pem", $private );
		file_put_contents( "$dir/key.public.pem", openssl_pkey_get_details( openssl_pkey_get_private( $private ) )['key'] );
	}

	/**
	 * Run $command in a PATH-less /bin/sh with $program replaced by a function that records its arguments.
	 *
	 * Checks what a command means rather than how it is quoted, so escaping fixes keep these assertions green.
	 *
	 * @return string[] The arguments $program received.
	 */
	protected function shell_argv( $command, $program ) {
		$dir  = $this->sandbox();
		$argv = escapeshellarg( "$dir/argv" );
		\EE\Tests\ShellProbe::run( "$program() { for a in \"\$@\"; do printf '%s\\000' \"\$a\"; done > $argv; }\n$command", $dir );
		$this->assertFileExists( "$dir/argv", "$program was not called by: $command" );

		return explode( "\0", substr( file_get_contents( "$dir/argv" ), 0, -1 ) );
	}

	/**
	 * Write the global config.yml that get_config_value() reads.
	 *
	 * @param array $values Top-level keys and scalar values.
	 */
	protected function write_ee_config( array $values ) {
		$lines = [];
		foreach ( $values as $key => $value ) {
			$lines[] = $key . ': ' . ( is_string( $value ) ? '"' . addcslashes( $value, '"\\' ) . '"' : var_export( $value, true ) );
		}
		file_put_contents( getenv( 'EE_CONFIG_PATH' ), implode( "\n", $lines ) . "\n" );
	}

	/**
	 * EE_Site_Command's constructor installs pcntl signal handlers (no pcntl in the test images) and a shutdown handler.
	 *
	 * @return \EE\Site\Type\HTML
	 */
	protected function html_command( $site_data = null ) {
		$command = ( new \ReflectionClass( \EE\Site\Type\HTML::class ) )->newInstanceWithoutConstructor();
		self::set_property( $command, 'fs', new \Symfony\Component\Filesystem\Filesystem() );
		$command->site_data = $site_data;

		return $command;
	}

	/**
	 * Insert a site whose root is a real directory under WEBROOT.
	 *
	 * @return \EE\Model\Site
	 */
	protected function create_site( $site_url, array $fields = [] ) {
		$fs_path = $this->track( WEBROOT . $site_url );
		if ( ! is_dir( $fs_path ) ) {
			mkdir( $fs_path, 0700, true );
		}
		$this->insert_site( $fields + [ 'site_url' => $site_url, 'site_fs_path' => $fs_path, 'alias_domains' => $site_url ] );

		return \EE\Model\Site::find( $site_url );
	}
}
