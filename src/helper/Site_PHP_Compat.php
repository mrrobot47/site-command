<?php

namespace EE\Site\Type;

use EE;
use Symfony\Component\Process\Exception\ProcessSignaledException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use function EE\Utils\delem_log;
use function EE\Utils\get_flag_value;

/**
 * `ee site php-compat`: runs the compat-scanner image against a site's code and prints its verdict or estimate.
 *
 * Output goes through `echo` only: EE::exec(), EE::launch() and EE::log() would put findings into ee.log.
 */
class Site_PHP_Compat {

	const IMAGE            = 'easyengine/compat-scanner';
	const SITE_TYPES       = [ 'wp', 'php' ];
	const DEFAULT_TIMEOUT  = 14400;
	const TIMEOUT_HINT     = '; run it again with a larger --timeout';
	const ESTIMATE_TIMEOUT = 120;
	// Docker's own start and teardown on top of the container's deadline, which normally fires first.
	const PROCESS_MARGIN = 60;
	const PULL_TIMEOUT   = 1800;
	// Estimate tuning passed to the scanner (its defaults: 0.1 and 1.5), from runner and host measurements on 2026-11-24.
	const ESTIMATE_SECONDS_PER_BATCH = '0.5';
	const ESTIMATE_HIGH_FACTOR       = '2.5';
	// Replacing a local copy whose contract label is wrong.
	const REPULL_TIMEOUT = 300;
	// The scanner interface this code reads (env, report keys), as the image's label states it.
	const CONTRACT_LABEL = 'io.easyengine.compat-scanner.contract';
	const CONTRACT       = '2';
	// How long --cancel and delete wait for a stopped check to clean up and release the site lock; a request older than
	// twice this belongs to no waiting --cancel.
	const CANCEL_WAIT = 60;
	const STDOUT_MAX     = 1048576;
	const STDERR_MAX     = 65536;
	const REPORT_MAX     = 134217728;
	// Memory the report may take once decoded; the limit is raised to this when lower.
	const MEMORY_FLOOR = 1073741824;
	// Under EE_ROOT_DIR/tmp/, root-only, so no other user or host uid 33 can reach a scan's /out.
	const TEMP_DIR      = 'php-compat';
	const SCAN_STATUSES = [ 'ok', 'no_code_found', 'tool_error' ];
	const SUMMARY_INTS  = [ 'errors', 'warnings', 'files_checked', 'files_not_checked', 'php_bytes', 'components_checked', 'components_with_errors', 'duration_s' ];
	const ESTIMATE_INTS = [ 'php_files', 'php_bytes', 'components', 'estimated_seconds', 'estimated_seconds_low', 'estimated_seconds_high' ];
	// --format=json prints only the documented keys (INTERFACE.md), in this order.
	const SUMMARY_KEYS  = [
		'v',
		'status',
		'site',
		'target',
		'safe_to_upgrade',
		'errors',
		'warnings',
		'files_checked',
		'files_not_checked',
		'php_bytes',
		'components_checked',
		'components_with_errors',
		'checked',
		'not_checked',
		'not_checked_more',
		'wordpress',
		'ignored',
		'duration_s',
		'tool',
	];

	// stdout stays fixed-size: at most this many not_checked entries, each string at most NOT_CHECKED_CHARS characters.
	const NOT_CHECKED_STDOUT = 10;
	const NOT_CHECKED_CHARS  = 256;
	const TOOL_KEYS     = [ 'phpcs', 'phpcompatibility', 'phpcompatibility_wp', 'image' ];
	const ESTIMATE_KEYS = [ 'v', 'status', 'estimate', 'site', 'target', 'php_files', 'php_bytes', 'components', 'estimated_seconds', 'estimated_seconds_low', 'estimated_seconds_high', 'inputs' ];
	const INPUT_KEYS    = [ 'calibration_bytes', 'calibration_seconds', 'startup_seconds', 'bytes_per_second', 'batches', 'seconds_per_batch', 'cpus', 'load_average_1m', 'low_factor', 'high_factor' ];
	// File names and messages from the scanned code can hold invalid UTF-8.
	const JSON_UTF8 = JSON_INVALID_UTF8_SUBSTITUTE; // phpcs:ignore PHPCompatibility.PHP.NewConstants.json_invalid_utf8_substituteFound -- PHP 7.2+; EE needs 7.4.
	// C1 controls, bidi and zero-width characters: a terminal acts on them or they disguise text.
	const UNSAFE_CHARS = '\x{80}-\x{9F}\x{061C}\x{200B}-\x{200F}\x{2028}-\x{202E}\x{2060}-\x{2069}\x{FEFF}';
	// A public directory as EE stores it (the pattern Cloner checks site_container_fs_path with).
	const ROOT_PATTERN = '#^/var/www/htdocs(?:/[A-Za-z0-9_-][A-Za-z0-9_.-]*)*$#D';

	/**
	 * PHP versions each WordPress branch supports, [ lowest, highest ], from WordPress's own "PHP Compatibility and WordPress
	 * Versions" page (https://make.wordpress.org/core/handbook/references/php-compatibility-and-wordpress-versions/, last
	 * updated 2026-11-04). A target newer than every row is one WordPress hasn't declared support for yet.
	 */
	const WP_PHP_SUPPORT = [
		'7.1' => [ '7.4', '8.5' ],
		'7.0' => [ '7.4', '8.5' ],
		'6.9' => [ '7.2', '8.5' ],
		'6.8' => [ '7.2', '8.4' ],
		'6.7' => [ '7.2', '8.4' ],
		'6.6' => [ '7.2', '8.3' ],
		'6.5' => [ '7.0', '8.3' ],
		'6.4' => [ '7.0', '8.3' ],
		'6.3' => [ '7.0', '8.2' ],
		'6.2' => [ '5.6', '8.2' ],
		'6.1' => [ '5.6', '8.2' ],
		'6.0' => [ '5.6', '8.1' ],
		'5.9' => [ '5.6', '8.1' ],
		'5.8' => [ '5.6', '8.0' ],
		'5.7' => [ '5.6', '8.0' ],
		'5.6' => [ '5.6', '8.0' ],
		'5.5' => [ '5.6', '7.4' ],
		'5.4' => [ '5.6', '7.4' ],
		'5.3' => [ '5.6', '7.4' ],
		'5.2' => [ '5.6', '7.3' ],
		'5.1' => [ '5.2', '7.3' ],
		'5.0' => [ '5.2', '7.3' ],
		'4.9' => [ '5.2', '7.2' ],
		'4.8' => [ '5.2', '7.1' ],
		'4.7' => [ '5.2', '7.1' ],
	];

	// tool_error detail when the site's directory or app/ is missing or its lock can't be opened (a delete in progress).
	const SITE_GONE = 'the site is being deleted or its files are missing';

	/** EE's own config/nginx/custom/admin-tools.conf (site-type-wp templates/config/nginx/admin-tools.conf.mustache). */
	const ADMIN_TOOLS_CONF = "# ee-admin pages\nlocation ^~ /ee-admin/ {\n\troot /var/www/htdocs ;\n\tlocation ~ \\.php$ {\n\t\ttry_files \$uri =404;\n\t\tinclude fastcgi_params;\n\t\tfastcgi_pass php;\n\t}\n}\n";

	// The extensions the scanner reads as code (compat-scanner CODE_EXTENSIONS), for mounts and FPM's limit_extensions.
	const CODE_EXTENSIONS = [ 'php', 'inc', 'phtml', 'module', 'install', 'theme', 'engine', 'php5', 'php7' ];

	// Longest config line read whole; a longer one counts as unreadable, so memory stays bounded whatever the file.
	const LINE_BYTES = 1048576;

	/** @var string Site name. */
	protected $site = '';

	/** @var string Target PHP version. */
	protected $target = '';

	/** @var bool Whether this run is an estimate. */
	protected $estimate = false;

	/** @var bool Whether this run is --cancel. */
	protected $cancel = false;

	/** @var string Path argument for the scanner (under /var/www): the WordPress root, or the root nginx serves. */
	protected $scan_path = '';

	/** @var string COMPAT_SERVED: the one root nginx serves, or '' when config/nginx/ doesn't say so for sure. */
	protected $served = '';

	/** @var string[] --mount arguments for the php container's mounts the scanner gets too. */
	protected $binds = [];

	/** @var string[] COMPAT_EXTRA_ROOTS: destinations of those mounts outside /var/www. */
	protected $extra_roots = [];

	/** @var string[] COMPAT_REQUIRE: prepend, append and preload values of the PHP config. */
	protected $require = [];

	/** @var string[] PHP settings and options whose effect the scan can't follow. */
	protected $settings = [];

	/** @var array[] This check's own not_checked entries: [ file or label, why ]. */
	protected $ee_entries = [];

	/** @var string|null Image of the running php container; null when it doesn't run. */
	protected $php_image;

	/** @var string[] Old releases the scanner left out because nginx doesn't serve them (container paths). */
	protected $old_releases = [];

	/** @var bool Whether nginx serves above a `current` release layout, so every release is scanned. */
	protected $old_releases_served = false;

	/** @var array|null The live WordPress install the scanner reported: dir, version, branch (null when unknown). */
	protected $wordpress;

	/** @var array[] The other installs whose core was left out because their version supports the target: dir, version. */
	protected $other_installs = [];

	/** @var string|null Why the nginx-helper Predis error counts, when the site has one. */
	protected $predis_counts;

	/** @var string|null The file the scanner found naming a phar, which makes every phar not checked. */
	protected $phar_named_by;

	/** @var string|null Status of this scan's result, so cleanup() knows whether a late --cancel can still be honoured. */
	protected $result_status;

	/** @var resource|null The site lock `ee site delete` keeps until it ends. */
	protected $delete_lock;

	/** @var bool Whether cleanup() took a --cancel request that arrived after the scan's last check. */
	protected $late_cancel = false;

	/** @var string The site's app/ with a trailing slash, for paths named in output. */
	protected $app_path = '';

	/** @var string[] Phars no scanned code names, cleaned and cut: listed as ignored. */
	protected $phars = [];

	/** @var int[] Errors of nginx-helper's bundled Predis left out of the verdict, by where the scanner reports each copy. */
	protected $predis_ignored = [];

	/** @var bool Whether the last lock_site() couldn't open the site directory (gone during a delete). */
	protected $lock_unopenable = false;

	/** @var string|null The PHP version the site's record says, major.minor; null when unknown. */
	protected $current_php;

	/** @var string|null The PHP version of the running php container's image (easyengine/phpX.Y), null when unknown. */
	protected $container_php;

	/** @var string|null Cancel request file a scan watches once it holds the site lock. */
	protected $cancel_marker;

	/** @var string|null This run's directory mounted at /out, while it exists. */
	protected $temp_dir;

	/** @var string|null Name of a scan container that may still exist. */
	protected $active_container;

	/** @var resource|null Lock on the site directory while a scan or an estimate runs. */
	protected $lock;

	/** @var string|null Label value of this estimate's container while cleanup() may have to remove it. */
	protected $estimate_label;

	/** @var resource|null Shared host-wide lock while this check uses the scanner image, so no other check removes its tag. */
	protected $image_lock;

	/** @var string|null Real directory of --output, as validated. */
	protected $output_dir;

	/** @var string|null Temporary file of write_report() until it is renamed. */
	protected $output_tmp;

	/** @var callable|null Runs pcntl_signal_dispatch() in the site command's scope, where its protected rollback() handlers can be called. */
	protected $dispatch;

	/** @var bool Set during cleanup(), so a second signal can't interrupt it. */
	protected $cleaning = false;

	/** @var bool Whether the shutdown cleanup is registered. */
	private $cleanup_registered = false;

	/**
	 * Validate the options, run the scan or the estimate and print the result.
	 *
	 * @param object        $site_data  Site record from get_site_info( ..., false ).
	 * @param array         $assoc_args Command options.
	 * @param callable|null $dispatch   Signal dispatcher from EE_Site_Command; null dispatches here.
	 */
	public function run( $site_data, array $assoc_args, $dispatch = null ) {

		$this->site = $site_data->site_url;
		// `--estimate=false` is the string "false": only a bare flag (or --no-<flag>) is a switch.
		foreach ( [ 'estimate', 'cancel' ] as $key ) {
			if ( ! is_bool( get_flag_value( $assoc_args, $key, false ) ) ) {
				EE::error( "--$key doesn't take a value." );
			}
		}
		$this->target   = (string) get_flag_value( $assoc_args, 'php', '' );
		$this->estimate = true === get_flag_value( $assoc_args, 'estimate', false );
		$this->cancel   = true === get_flag_value( $assoc_args, 'cancel', false );
		$format         = get_flag_value( $assoc_args, 'format', 'table' );
		$output         = get_flag_value( $assoc_args, 'output', null );
		$timeout        = get_flag_value( $assoc_args, 'timeout', null );

		// The dispatcher drops a valueless optional flag with only a warning; the runner still has it.
		$raw = (array) EE::get_runner()->assoc_args;
		if ( $this->cancel ) {
			// A cancel stops whichever check of the site is running, whatever its target.
			foreach ( [ 'php', 'estimate', 'output', 'timeout' ] as $key ) {
				if ( array_key_exists( $key, $assoc_args ) || array_key_exists( $key, $raw ) ) {
					EE::error( "--$key doesn't apply to --cancel." );
				}
			}
		}
		foreach ( [ 'php', 'format', 'output', 'timeout' ] as $key ) {
			if ( ! array_key_exists( $key, $assoc_args ) && isset( $raw[ $key ] ) && true === $raw[ $key ] ) {
				EE::error( "--$key needs a value." );
			}
		}
		if ( ! in_array( $site_data->site_type, self::SITE_TYPES, true ) ) {
			EE::error( "ee site php-compat supports wp and php sites; {$this->site} is a {$site_data->site_type} site." );
		}
		if ( ! $this->cancel && ! array_key_exists( 'php', $assoc_args ) ) {
			// Optional in the synopsis only because --cancel doesn't take it; same message as the dispatcher's.
			EE::error( "Parameter errors:\n missing --php parameter (PHP version to check the code against.)" );
		}
		// The dispatcher compares loosely, so `--php=8` or `--php=8.10` (== 8.1) would pass its check.
		if ( ! $this->cancel && ! in_array( $this->target, self::php_versions(), true ) ) {
			EE::error( 'Invalid --php value. Use one of: ' . implode( ', ', self::php_versions() ) . '.' );
		}
		if ( ! in_array( $format, [ 'table', 'json' ], true ) ) {
			EE::error( 'Invalid --format value. Use table or json.' );
		}
		if ( $this->estimate && ( null !== $output || null !== $timeout ) ) {
			EE::error( '--output and --timeout don\'t apply to --estimate.' );
		}
		if ( null === $timeout ) {
			$timeout = self::DEFAULT_TIMEOUT;
		} elseif ( ! is_string( $timeout ) || ! preg_match( '/^[1-9][0-9]{0,8}$/', $timeout ) ) {
			EE::error( '--timeout must be a positive whole number of seconds.' );
		}
		if ( null !== $output ) {
			$output = $this->validate_output_path( $output );
		}

		$this->dispatch = $dispatch;
		if ( null !== $dispatch && 'json' === $format ) {
			self::keep_stdout_for_json();
		}

		delem_log( 'site php-compat start' );
		if ( $this->cancel ) {
			EE::debug( "php-compat: site {$this->site}, cancel" );
		} else {
			EE::debug( sprintf( 'php-compat: site %s, PHP %s, %s', $this->site, $this->target, $this->estimate ? 'estimate' : "scan, timeout $timeout s" ) );
		}
		$started = microtime( true );

		try {
			if ( $this->cancel ) {
				$result = $this->run_cancel( $site_data );
			} elseif ( $this->estimate ) {
				$result = $this->run_estimate( $site_data );
			} else {
				$result = $this->run_scan( $site_data, (int) $timeout, $output );
			}
			// A check stopped by --cancel fails in whichever step it was in; report the cancel instead.
			if ( ! $this->cancel && 'ok' !== $result['doc']['status'] && $this->cancel_requested() ) {
				$result = $this->cancelled();
			}
			$this->result_status = $result['doc']['status'];
		} finally {
			$this->cleanup();
		}
		if ( $this->late_cancel ) {
			// The request came while this scan cleaned up; --cancel reads its removal as stopped, so say the same.
			$result = $this->doc( 'cancelled' );
		}

		// Before printing: PHP stops at an echo to a terminal that is gone.
		$this->log_result( $result['doc'], microtime( true ) - $started );
		if ( 'json' === $format ) {
			echo self::encode( $result['doc'] ) . "\n";
		} elseif ( $this->cancel ) {
			echo $this->cancel_text( $result['doc'] );
		} elseif ( $this->estimate ) {
			echo $this->estimate_text( $result['doc'] );
		} else {
			// Line by line: a large report isn't copied into one more string.
			foreach ( $this->table_lines( $result['doc'], $result['report'] ) as $line ) {
				echo $line, "\n";
			}
		}

		delem_log( 'site php-compat end' );
	}

	/**
	 * The --php values of this command's docblock, the same list as `ee site update --php`.
	 *
	 * @return string[]
	 */
	public static function php_versions() {
		$doc     = ( new \ReflectionMethod( EE_Site_Command::class, 'php_compat' ) )->getDocComment();
		$options = ( new \EE\DocParser( $doc ) )->get_param_args( 'php' );

		// YAML reads 8.0 as a float.
		return array_map(
			function ( $version ) {
				return is_float( $version ) ? number_format( $version, 1, '.', '' ) : (string) $version;
			},
			isset( $options['options'] ) ? $options['options'] : []
		);
	}

	/**
	 * Refuse an --output the report couldn't be written to, before the scan runs.
	 *
	 * @param string $output --output value.
	 *
	 * @return string The path.
	 */
	protected function validate_output_path( $output ) {
		$output = (string) $output;
		if ( '' === $output || '/' !== $output[0] ) {
			EE::error( '--output must be an absolute path.' );
		}
		if ( ! preg_match( '//u', $output ) || preg_match( '/[\x00-\x1F\x7F' . self::UNSAFE_CHARS . ']/u', $output ) ) {
			EE::error( '--output must not contain control characters.' );
		}
		$name = basename( $output );
		if ( '/' === substr( $output, -1 ) || '' === $name || '.' === $name || '..' === $name ) {
			EE::error( '--output must name a file.' );
		}
		$dir = realpath( dirname( $output ) );
		if ( false === $dir || ! is_dir( $dir ) ) {
			EE::error( 'The directory of --output does not exist: ' . dirname( $output ) );
		}
		// The rename would replace a device node such as /dev/null with the report.
		foreach ( [ '/dev', '/proc', '/sys' ] as $system ) {
			if ( $dir === $system || 0 === strpos( $dir, "$system/" ) ) {
				EE::error( "--output can't be in $system: $output" );
			}
		}
		// Anyone could rename entries there and redirect root's write; the sticky bit (/tmp) stops that.
		$mode = fileperms( $dir );
		if ( ( $mode & 0002 ) && ! ( $mode & 01000 ) ) {
			EE::error( "--output can't be in a directory everyone can write unless it is sticky like /tmp: $dir" );
		}
		if ( is_dir( $output ) && ! is_link( $output ) ) {
			EE::error( "--output is a directory: $output" );
		}
		if ( ! self::replaceable( $output ) ) {
			EE::error( "--output is not a regular file: $output" );
		}
		// tempnam() falls back to the system temp dir when $dir isn't writable (read-only mount, not root).
		$probe    = @tempnam( $dir, '.ee-php-compat-' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$writable = false !== $probe && realpath( dirname( $probe ) ) === $dir;
		if ( false !== $probe ) {
			@unlink( $probe ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! $writable ) {
			EE::error( 'The directory of --output is not writable: ' . dirname( $output ) );
		}
		$this->output_dir = $dir;

		return $output;
	}

	/**
	 * @param object $site_data Site record.
	 * @param int    $timeout   --timeout.
	 * @param string $output    --output path, or null.
	 *
	 * @return array 'doc' (summary to print) and 'report' (decoded report, ok only).
	 */
	protected function run_scan( $site_data, $timeout, $output ) {
		$started = $this->start( $site_data );
		if ( isset( $started['doc'] ) ) {
			return $started;
		}
		list( $image, $code ) = $started;
		$out                  = $this->make_temp_dir();
		if ( null === $out ) {
			return $this->doc( 'tool_error', 'could not create the temporary directory' );
		}
		if ( false !== strpbrk( $out, ',"' ) ) {
			return $this->doc( 'tool_error', 'the temporary directory path has a comma or a quote' );
		}
		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}

		// From here until cleanup() confirms it is gone, /out may be in use by this container.
		$name                   = 'ee-php-compat-' . $this->site;
		$this->active_container = $name;
		$run                    = $this->docker( $this->docker_run_args( 'scan', $image, $site_data, $code, $out, $timeout ), $timeout + self::PROCESS_MARGIN, true );
		if ( $run['timed_out'] ) {
			return $this->doc( 'tool_error', "timed out after $timeout s" . self::TIMEOUT_HINT );
		}
		// Docker refuses a taken name before creating anything, so nothing is printed, and the other container exists.
		$conflict = 125 === $run['code'] && '' === trim( $run['stdout'] ) && false !== stripos( $run['stderr'], 'Conflict' );
		if ( $conflict && false !== strpos( $run['stderr'], $name ) && false !== $this->container_status( $name ) ) {
			// That container is another run's: leave it alone.
			$this->active_container = null;

			return $this->doc( 'busy' );
		}

		$doc = empty( $run['overflow'] ) ? $this->parse_document( $run['stdout'] ) : null;
		if ( null === $doc || ! $this->valid_scan_doc( $doc ) ) {
			return $this->doc( 'tool_error', self::exit_detail( $run, 'without a valid result' ) );
		}
		if ( 'tool_error' === $doc['status'] && 0 === strpos( $doc['detail'], 'timed out after ' ) ) {
			return $this->doc( 'tool_error', $doc['detail'] . self::TIMEOUT_HINT );
		}
		if ( 'no_code_found' === $doc['status'] && $this->ee_entries ) {
			// This check's own entries still count: an empty scan, so "can't confirm" rather than "no code".
			$doc           = array_fill_keys( self::SUMMARY_INTS, 0 ) + [ 'tool' => [ 'image' => $image ] ] + $doc;
			$doc['status'] = 'ok';
			$report        = [
				'components'            => [],
				'not_checked'           => [],
				'wordpress_installs'    => [],
				'excluded_old_releases' => [],
				'phars'                 => [],
				'phar_named_by'         => null,
			];
		} elseif ( 'ok' !== $doc['status'] ) {
			return $this->doc( $doc['status'], 'tool_error' === $doc['status'] ? $doc['detail'] : null );
		} else {
			$report = $this->read_report( $out, $doc );
			if ( ! is_array( $report ) ) {
				return $this->doc( 'tool_error', null === $report ? self::exit_detail( $run, 'without a valid report' ) : $report );
			}
		}
		$this->read_installs( $report );
		$this->ignore_unused_predis( $doc, $report );

		// Last point a cancel wins: after it, the result stands and --output is written.
		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}

		$full    = $this->summary( $this->scoped( $doc, $report ) );
		$summary = self::capped( $full );
		if ( null !== $output ) {
			// The report says what stdout says (gated verdict, scoped fields), with the full not_checked list and the components.
			$raw = self::encode( $full + [ 'components' => $report['components'] ] );
			// A value json_encode() refuses (INF in a key EE doesn't know): no report rather than an empty one.
			if ( ! is_string( $raw ) || '' === $raw ) {
				return $this->doc( 'tool_error', 'could not encode the report' );
			}
			if ( ! $this->write_report( $output, $raw ) ) {
				return $this->doc( 'tool_error', "could not write the report to $output" );
			}
			$summary['report']       = $output;
			$summary['report_bytes'] = strlen( $raw );
		}

		return [
			'doc'    => $summary,
			'report' => $report,
		];
	}

	/**
	 * The container ended (docker run --rm returns once it is removed), so nothing changes /out any more.
	 *
	 * @param string $out This run's /out.
	 * @param array  $doc Validated stdout summary.
	 *
	 * @return array|string|null The validated report, a tool_error detail, or null when it is missing or invalid.
	 */
	protected function read_report( $out, array $doc ) {
		$file = $out . '/report.json';
		clearstatcache( true, $file );
		$size = @filesize( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a missing file is refused below.
		if ( false !== $size && $size > self::REPORT_MAX ) {
			return 'the report is larger than ' . ( self::REPORT_MAX >> 20 ) . ' MiB';
		}
		$raw    = null;
		$report = null;
		try {
			self::raise_memory_limit();
			$raw = \EE\Site\Utils\get_container_file_contents( $file, $out, self::REPORT_MAX );
			if ( ! $this->make_room( self::decode_cost( $raw ) ) ) {
				return sprintf( 'the report (%s) needs more memory to read than is available', self::size( strlen( $raw ) ) );
			}
			$report = json_decode( $raw, true, 512, self::JSON_UTF8 );
		} catch ( \Exception $e ) {
			EE::debug( 'php-compat report: ' . self::clean( $e->getMessage() ) );
		}
		// The scanner never prints invalid UTF-8; the decode above only substitutes it, and --output publishes these bytes as they are.
		if ( ! is_array( $report ) || 1 !== preg_match( '//u', $raw ) || ! $this->valid_report( $report, $doc ) ) {
			return null;
		}

		return $report;
	}

	/**
	 * @param object $site_data Site record.
	 *
	 * @return array 'doc' (estimate to print) and 'report' (always null).
	 */
	protected function run_estimate( $site_data ) {
		$started = $this->start( $site_data );
		if ( isset( $started['doc'] ) ) {
			return $started;
		}
		list( $image, $code ) = $started;
		if ( $this->cancel_requested() ) {
			return $this->cancelled();
		}
		// The client ignores signals (no --sig-proxy) and a timeout stops only the client: cleanup() removes the container by label.
		$this->estimate_label = bin2hex( random_bytes( 8 ) );
		$run                  = $this->docker( $this->docker_run_args( 'estimate', $image, $site_data, $code ), self::ESTIMATE_TIMEOUT + self::PROCESS_MARGIN, true );
		if ( null !== $run['code'] ) {
			// Ended on its own: --rm removed it.
			$this->estimate_label = null;
		}
		if ( $run['timed_out'] ) {
			return $this->doc( 'tool_error', 'timed out after ' . self::ESTIMATE_TIMEOUT . ' s' );
		}

		$doc = empty( $run['overflow'] ) ? $this->parse_document( $run['stdout'] ) : null;
		if ( null === $doc || ! $this->valid_estimate_doc( $doc ) ) {
			return $this->doc( 'tool_error', self::exit_detail( $run, 'without a valid result' ) );
		}
		if ( 'ok' !== $doc['status'] ) {
			return $this->doc( $doc['status'], 'tool_error' === $doc['status'] ? $doc['detail'] : null );
		}

		return [
			'doc'    => $this->with_site( self::pick( $doc, self::ESTIMATE_KEYS ) ),
			'report' => null,
		];
	}

	/**
	 * Steps a scan and an estimate share: the site lock, the image, then what the scanner needs to see what PHP runs.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return array [ image, code ] or, in 'doc', the result to print.
	 */
	protected function start( $site_data ) {
		$image = $this->image();
		if ( null === $image ) {
			return $this->doc( 'tool_error', 'no ' . self::IMAGE . ' tag in img-versions.json' );
		}
		$code = $this->code_dir( $site_data->site_fs_path );
		if ( null === $code ) {
			// Delete removes the site's files before its record, so no app/ is never "no code found".
			return $this->doc( 'tool_error', self::SITE_GONE );
		}
		if ( false === $code ) {
			return $this->doc( 'tool_error', "can't resolve {$site_data->site_fs_path}/app, the link to the site's code" );
		}
		if ( false !== strpbrk( $code, ',"' ) ) {
			return $this->doc( 'tool_error', 'the site code path has a comma or a quote' );
		}
		// One lock per site: a check and an estimate never overlap, and a restore holds it shared.
		if ( ! $this->lock_site( $site_data->site_fs_path ) ) {
			return $this->restore_running( $site_data->site_fs_path ) ? $this->doc( 'tool_error', 'a restore of the site is running' ) : $this->doc( 'busy' );
		}
		$this->watch_cancel();
		// A site directory that can't be opened is being deleted (delete removes it before the record); never scan unlocked.
		if ( $this->lock_unopenable ) {
			$unopenable = "the site's directory {$site_data->site_fs_path} can't be opened for locking";
			return $this->doc( 'tool_error', is_dir( $site_data->site_fs_path ) ? $unopenable : self::SITE_GONE );
		}
		if ( ! $this->estimate ) {
			$leftover = $this->remove_leftover_container();
			if ( null !== $leftover ) {
				return $leftover;
			}
		}
		$this->hold_image();
		$pull = $this->ensure_image( $image );
		if ( null !== $pull ) {
			return $this->doc( 'tool_error', $pull );
		}
		$inputs = $this->read_inputs( $site_data, $code );
		if ( null !== $inputs ) {
			return $this->doc( 'tool_error', $inputs );
		}

		return [ $image, $code ];
	}

	/**
	 * @return array|null busy or tool_error result, or null when no container of the site's name is left.
	 */
	protected function remove_leftover_container() {
		$name   = 'ee-php-compat-' . $this->site;
		$status = $this->container_status( $name );
		if ( 'running' === $status ) {
			return $this->doc( 'busy' );
		}
		if ( 'unknown' === $status ) {
			return $this->doc( 'tool_error', "could not check the container $name" );
		}
		if ( 'exited' === $status ) {
			EE::debug( "Removing the leftover container $name." );
			// Known limit: a container whose --rm is still in progress can't be removed again, which reads as this tool_error.
			if ( 0 !== $this->docker( [ 'docker', 'rm', '-f', $name ], 60 )['code'] ) {
				return $this->doc( 'tool_error', "could not remove the leftover container $name" );
			}
		}

		return null;
	}

	/**
	 * What the scanner needs besides app/: the php container's mounts, the root nginx serves, where WordPress is, and the
	 * files PHP is told to load first. Nothing here refuses a site file.
	 *
	 * @param object $site_data Site record.
	 * @param string $code      Real path of the site's app/.
	 *
	 * @return string|null tool_error detail.
	 */
	protected function read_inputs( $site_data, $code ) {
		$this->app_path    = $site_data->site_fs_path . '/app/';
		$record_php        = isset( $site_data->php_version ) ? (string) $site_data->php_version : '';
		$this->current_php = preg_match( '/^\d+\.\d+$/D', $record_php ) ? $record_php : null;
		$config            = $this->php_container( $site_data, $code );
		if ( is_string( $config ) ) {
			return $config;
		}
		$hint = $this->read_nginx_roots( $site_data );
		$rel  = ltrim( substr( $hint, strlen( '/var/www' ) ), '/' );
		$this->scan_path = $hint;
		if ( 'wp' === $site_data->site_type ) {
			$this->scan_path = '/var/www/' . $this->wordpress_hint( $code, $rel );
		}
		$served_rel                = ltrim( substr( $this->served, strlen( '/var/www' ) ), '/' );
		$this->old_releases_served = '' !== $this->served && self::release_layout_in( $code, $served_rel );
		$this->read_php_settings( $config );
		if ( $this->settings ) {
			$this->ee_entries[] = [ 'PHP settings', 'PHP settings this check can\'t follow: ' . implode( '; ', array_unique( $this->settings ) ) ];
		}

		return null;
	}

	/**
	 * The site's php container as the scanner must see it: its mounts become binds, extra roots or not-checked entries.
	 *
	 * @param object $site_data Site record.
	 * @param string $code      Real path of the site's app/.
	 *
	 * @return string[]|string php_config_files(), or a tool_error detail.
	 */
	protected function php_container( $site_data, $code ) {
		$this->binds       = [];
		$this->extra_roots = [];
		$this->ee_entries  = [];
		$this->settings    = [];
		$this->php_image   = null;
		$label             = 'label=io.easyengine.site=' . $this->site;
		$service           = 'label=com.docker.compose.service=php';
		$ps                = $this->docker( [ 'docker', 'ps', '-q', '--no-trunc', '--filter', $label, '--filter', $service ], 60 );
		if ( 0 !== $ps['code'] ) {
			return 'could not list the site\'s php container: ' . self::first_line( $ps['stderr'] );
		}
		$ids = preg_split( '/\s+/', trim( $ps['stdout'] ), -1, PREG_SPLIT_NO_EMPTY );
		// EE runs one php container per site; with more, which one serves a request (and what it mounts) is unknown.
		if ( count( $ids ) > 1 ) {
			return 'the site has more than one php container (' . count( $ids ) . '), which this check can\'t confirm';
		}
		$config = [
			'/usr/local/etc' => $site_data->site_fs_path . '/config/php',
		];
		if ( ! $ids ) {
			$this->stopped_site_mounts( $site_data->site_fs_path );

			return self::php_config_files( $config );
		}
		$inspect = $this->docker( [ 'docker', 'inspect', '--format', '{{json .}}', $ids[0] ], 60 );
		$info    = 0 === $inspect['code'] ? json_decode( trim( $inspect['stdout'] ), true ) : null;
		if ( ! isset( $info['Mounts'], $info['Config'] ) || ! is_array( $info['Mounts'] ) || ! is_array( $info['Config'] ) ) {
			return 'could not read the mounts of the site\'s php container';
		}
		$this->php_image     = isset( $info['Config']['Image'] ) ? (string) $info['Config']['Image'] : '';
		$this->container_php = preg_match( '#^easyengine/php(\d+\.\d+):#', $this->php_image, $m ) ? $m[1] : null;
		$this->command_settings( $info['Config'], isset( $info['Image'] ) ? (string) $info['Image'] : '' );
		if ( ! empty( $info['HostConfig']['GroupAdd'] ) ) {
			// PHP may then read files the scanner's uid 33 can't, which the scanner skips without an entry.
			$this->ee_entries[] = [ 'php container groups', 'the php container\'s user has extra groups, so PHP may read files this check can\'t' ];
		}
		foreach ( $info['Mounts'] as $mount ) {
			$dst = isset( $mount['Destination'] ) ? rtrim( (string) $mount['Destination'], '/' ) : '';
			$src = isset( $mount['Source'] ) ? (string) $mount['Source'] : '';
			if ( '/var/www' === $dst ) {
				// The record and the container disagree about where the site's files are: no EE command makes that.
				if ( realpath( $src ) !== $code ) {
					return 'the site\'s php container gets /var/www from ' . self::clean( $src ) . ', not the site\'s app/ (' . $code . ')';
				}
				continue;
			}
			if ( '/usr/local/etc' === $dst || 0 === strpos( $dst, '/usr/local/etc/' ) ) {
				$config[ $dst ] = $src;
				continue;
			}
			$this->classify_mount( $mount, $dst, $src, $site_data->site_fs_path, $code );
		}

		return self::php_config_files( $config );
	}

	/**
	 * A mount of the php container outside /usr/local/etc: ignored when it can't hold site code, bound into the scanner,
	 * or a not-checked entry.
	 *
	 * @param array  $mount    docker inspect Mounts entry.
	 * @param string $dst      Its destination, without a trailing slash.
	 * @param string $src      Its host source.
	 * @param string $site_dir Site directory.
	 * @param string $code     Real path of the site's app/.
	 */
	protected function classify_mount( array $mount, $dst, $src, $site_dir, $code ) {
		$unbindable = [ $dst, 'a php container mount this check can\'t mount into the scanner' ];
		$real       = realpath( $src );
		if ( '/var/log/php' === $dst && false !== $real && realpath( "$site_dir/logs/php" ) === $real ) {
			return;
		}
		if ( self::ee_newrelic_mount( $mount ) && $this->ee_plain_volume( $mount['Name'] ) ) {
			return;
		}
		$below = 0 === strpos( $dst, '/var/www/' );
		// EE's own admin tools, not the site's code.
		if ( '/var/www/htdocs/ee-admin' === $dst && false !== $real && realpath( EE_ROOT_DIR . '/admin-tools' ) === $real ) {
			return;
		}
		$dir = false !== $real && is_dir( $real );
		if ( ! $below && ! $dir && false !== $real && ! self::code_name( $dst ) && ! self::code_name( $src ) ) {
			// A time zone file, a certificate, a socket: not site code.
			return;
		}
		// --mount takes CSV, and the scanner refuses a control character in COMPAT_EXTRA_ROOTS.
		if ( '' === $src || false === $real || preg_match( '/[,"\x00-\x1F\x7F]/', $src . $dst ) ) {
			$this->ee_entries[] = $unbindable;
			return;
		}
		if ( $below ) {
			// The scanner's /var/www is read-only, so the mount point must already be there.
			if ( null === self::resolve_in( $code, substr( $dst, strlen( '/var/www/' ) ) ) ) {
				$this->ee_entries[] = $unbindable;
				return;
			}
			array_push( $this->binds, '--mount', "type=bind,src=$src,dst=$dst,readonly" );
			return;
		}
		if ( ! $dir ) {
			$this->ee_entries[] = [ $dst, 'a code file mounted into the php container' ];
			return;
		}
		if ( self::runtime_path( $dst ) ) {
			$this->ee_entries[] = [ $dst, 'a php container mount at a kernel or runtime path, which isn\'t scanned' ];
			return;
		}
		if ( self::scanner_path( $dst ) ) {
			$this->ee_entries[] = $unbindable;
			return;
		}
		$this->extra_roots[] = $dst;
		array_push( $this->binds, '--mount', "type=bind,src=$src,dst=$dst,readonly" );
	}

	/**
	 * EE's stock New Relic socket volume, which every Linux site's php container has, exactly as EE creates it.
	 *
	 * @param array $mount docker inspect Mounts entry.
	 *
	 * @return bool
	 */
	protected static function ee_newrelic_mount( array $mount ) {
		$volume = ( defined( 'GLOBAL_NEWRELIC_DAEMON' ) ? GLOBAL_NEWRELIC_DAEMON : 'global-newrelic-daemon' ) . '_newrelic_sock';

		return isset( $mount['Destination'], $mount['Type'], $mount['Name'] ) && '/run/newrelic' === rtrim( $mount['Destination'], '/' )
			&& 'volume' === $mount['Type'] && $volume === $mount['Name'];
	}

	/**
	 * @param string $dst Mount destination.
	 *
	 * @return bool Whether it is at or below a kernel or runtime path, which the scanner doesn't get.
	 */
	protected static function runtime_path( $dst ) {
		foreach ( [ '/dev', '/proc', '/sys', '/run', '/tmp' ] as $path ) {
			if ( $dst === $path || 0 === strpos( $dst, "$path/" ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $dst Mount destination outside /var/www.
	 *
	 * @return bool Whether a bind there would hide what the scanner image runs from.
	 */
	protected static function scanner_path( $dst ) {
		foreach ( [ '/usr/local', '/opt/compat-scanner', '/out', '/var/www', '/bin', '/sbin', '/lib', '/etc' ] as $path ) {
			if ( 0 === strpos( $path . '/', rtrim( $dst, '/' ) . '/' ) ) {
				return true;
			}
		}

		return 0 === strpos( $dst, '/usr/local/' ) || 0 === strpos( $dst, '/opt/compat-scanner/' ) || 0 === strpos( $dst, '/out/' );
	}

	/**
	 * @param string $path File name.
	 *
	 * @return bool Whether PHP-FPM would run it by its extension (the scanner's code extensions).
	 */
	protected static function code_name( $path ) {
		return 1 === preg_match( '/\.(?:' . implode( '|', self::CODE_EXTENSIONS ) . ')$/i', $path );
	}

	/**
	 * A disabled site has no container to read: with an override file its mounts are unknown.
	 *
	 * @param string $site_dir Site directory.
	 */
	protected function stopped_site_mounts( $site_dir ) {
		$overrides = array_merge( [ "$site_dir/docker-compose-custom.yml" ], (array) glob( "$site_dir/user-docker-compose/*.{yml,yaml}", GLOB_BRACE ) );
		foreach ( $overrides as $file ) {
			if ( file_exists( $file ) ) {
				$this->ee_entries[] = [ 'php container mounts', "they can't be read while the site is disabled; enable the site for a full result" ];
				return;
			}
		}
	}

	/**
	 * Settings on the php container's command line, a command other than its image's, or an ini path in its environment,
	 * which this check can't follow.
	 *
	 * @param array  $config docker inspect Config.
	 * @param string $image  ID of the container's image.
	 */
	protected function command_settings( array $config, $image ) {
		$config += [
			'Entrypoint' => [],
			'Cmd'        => [],
			'Env'        => [],
		];
		$args    = array_merge( (array) $config['Entrypoint'], (array) $config['Cmd'] );
		// Also inside one argument, as in `bash -lc "php-fpm -d …"`.
		if ( preg_grep( '/(?:^|\s)(?:-[dcy]|--define|--php-ini|--fpm-config)/', array_map( 'strval', $args ) ) ) {
			$this->settings[] = 'PHP settings on the php container\'s command line';
		} elseif ( $args !== $this->image_command( $image ) ) {
			// A wrapper script can set anything before PHP starts.
			$this->settings[] = 'a command or entrypoint other than its image\'s on the php container';
		}
		foreach ( (array) $config['Env'] as $pair ) {
			$name = strtok( (string) $pair, '=' );
			if ( in_array( $name, [ 'PHPRC', 'PHP_INI_SCAN_DIR' ], true ) ) {
				$this->settings[] = "$name in the php container's environment";
			}
		}
	}

	/**
	 * @param string $image Image ID.
	 *
	 * @return string[]|null Its entrypoint and command, or null when they can't be read.
	 */
	protected function image_command( $image ) {
		$run    = $this->docker( [ 'docker', 'image', 'inspect', '--format', '{{json .Config}}', $image ], 60 );
		$config = 0 === $run['code'] ? json_decode( trim( $run['stdout'] ), true ) : null;
		if ( ! is_array( $config ) ) {
			return null;
		}

		return array_merge( (array) ( $config['Entrypoint'] ?? [] ), (array) ( $config['Cmd'] ?? [] ) );
	}

	/**
	 * The ini and FPM files PHP reads, named by where PHP sees them: a mount decides by its destination, whatever its host
	 * name, and a file mounted into conf.d or php-fpm.d is listed there. PHP's conf.d scan loads dot-files too.
	 *
	 * @param string[] $mounts Host source by destination: /usr/local/etc and the mounts below it.
	 *
	 * @return string[] Host path by container path.
	 */
	protected static function php_config_files( array $mounts ) {
		$files = [];
		foreach ( [ '/usr/local/etc/php/php.ini', '/usr/local/etc/php/php-fpm-fcgi.ini', '/usr/local/etc/php-fpm.conf' ] as $file ) {
			$files[ $file ] = self::config_host_path( $mounts, $file );
		}
		$dirs = [
			'/usr/local/etc/php/conf.d' => '.ini',
			'/usr/local/etc/php-fpm.d'  => '.conf',
		];
		foreach ( $dirs as $dir => $suffix ) {
			$mounted = preg_grep( '#^' . preg_quote( $dir, '#' ) . '/[^/]+$#', array_keys( $mounts ) );
			$names   = array_merge( self::entries( self::config_host_path( $mounts, $dir ) ), array_map( 'basename', $mounted ) );
			foreach ( $names as $name ) {
				if ( substr( $name, -strlen( $suffix ) ) === $suffix ) {
					$files[ "$dir/$name" ] = self::config_host_path( $mounts, "$dir/$name" );
				}
			}
		}

		return $files;
	}

	/**
	 * @param string[] $mounts See php_config_files().
	 * @param string   $path   Container path under /usr/local/etc.
	 *
	 * @return string Its host path, through the most specific mount holding it.
	 */
	protected static function config_host_path( array $mounts, $path ) {
		$best = '/usr/local/etc';
		foreach ( array_keys( $mounts ) as $dst ) {
			if ( strlen( $dst ) > strlen( $best ) && ( $path === $dst || 0 === strpos( $path, "$dst/" ) ) ) {
				$best = $dst;
			}
		}

		return $mounts[ $best ] . substr( $path, strlen( $best ) );
	}

	/**
	 * Collect the files PHP is told to load first or last (COMPAT_REQUIRE), and note the settings whose effect the scan
	 * can't follow, from every ini and FPM file PHP reads, line by line.
	 *
	 * @param string[] $files php_config_files().
	 */
	protected function read_php_settings( array $files ) {
		$this->require = [];
		foreach ( $files as $path => $file ) {
			// A link made inside the container (to a container path) doesn't lead to the same file here.
			if ( is_link( $file ) && ! file_exists( $file ) ) {
				$this->settings[] = 'a linked PHP config file this check can\'t follow';
				continue;
			}
			$fpm = '.conf' === substr( $path, -5 );
			self::each_line(
				$file,
				function ( $line ) use ( $fpm ) {
					if ( null === $line ) {
						$this->settings[] = 'a line too long to read in the PHP config';
						return;
					}
					foreach ( self::php_settings( $line, $fpm ? 'fpm' : 'ini' ) as list( $key, $value, $quoted ) ) {
						$this->php_setting( $key, $value, $quoted );
					}
				}
			);
		}
	}

	/**
	 * @param string $key    Setting, lowercase.
	 * @param string $value  Its value.
	 * @param bool   $quoted Whether it was quoted.
	 */
	protected function php_setting( $key, $value, $quoted ) {
		// Only bare keywords mean "nothing" to PHP; a quoted "none", or 0, is a file name.
		$off = '' === $value || ! $quoted && in_array( strtolower( $value ), [ 'none', 'off', 'no', 'false', 'null' ], true );
		switch ( $key ) {
			case 'auto_prepend_file':
			case 'auto_append_file':
			case 'opcache.preload':
				// A control character can't go into the scanner's environment.
				if ( ! $off && preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
					$this->settings[] = "a $key value this check can't pass to the scanner";
				} elseif ( ! $off ) {
					$this->require[] = $value;
				}
				return;
			case 'include_path':
				// A bare include through it reaches the php image's own files or what the scan reads anyway.
				return;
			case 'include':
				// php-fpm.conf of EE's images: relative before PHP 8.6, absolute from 8.6.
				$changed = ! in_array( $value, [ 'etc/php-fpm.d/*.conf', '/usr/local/etc/php-fpm.d/*.conf' ], true );
				break;
			case 'security.limit_extensions':
				// Empty lets PHP-FPM run any extension.
				$extensions = preg_split( '/\s+/', strtolower( $value ), -1, PREG_SPLIT_NO_EMPTY );
				$changed    = ! $extensions || array_diff( $extensions, preg_filter( '/^/', '.', self::CODE_EXTENSIONS ) );
				break;
			case 'user_ini.filename':
				$changed = '' !== $value && '.user.ini' !== $value;
				break;
			case 'zend.multibyte':
				$changed = ! $off && '0' !== $value;
				break;
			default:
				// zend.script_encoding changes how PHP reads the source.
				$changed = ! $off;
		}
		if ( $changed ) {
			$this->settings[] = "$key = " . self::clean( $value );
		}
	}

	/**
	 * Calls $line with each line of a regular file, without its line break, or with null for a line longer than LINE_BYTES,
	 * so no file size matters. FIFOs, devices and directories are never opened.
	 *
	 * @param string   $path File.
	 * @param callable $line Called per line.
	 */
	protected static function each_line( $path, callable $line ) {
		$handle = is_file( $path ) ? @fopen( $path, 'rb' ) : false; // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- unreadable reads nothing.
		if ( false === $handle ) {
			return;
		}
		$long = false;
		while ( false !== ( $piece = fgets( $handle, self::LINE_BYTES ) ) ) {
			$ends = "\n" === substr( $piece, -1 ) || feof( $handle );
			if ( ! $ends ) {
				$long = true;
				continue;
			}
			$line( $long ? null : rtrim( $piece, "\r\n" ) );
			$long = false;
		}
		// A last line of exactly LINE_BYTES - 1 bytes without a line break ends no piece above.
		if ( $long ) {
			$line( null );
		}
		fclose( $handle );
	}

	/**
	 * Reads the `root` and `alias` lines of every regular file under config/nginx/. Sets COMPAT_SERVED: the one literal
	 * root under /var/www when nothing else (another root, an alias into /var/www, a variable) makes it uncertain, else ''.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return string The scan path hint: main.conf's first root, or the record's public directory.
	 */
	protected function read_nginx_roots( $site_data ) {
		$roots  = [];
		$first  = null;
		$dir    = $site_data->site_fs_path . '/config/nginx';
		$files = self::files_under( $dir, $outside );
		$sure  = ! $outside;
		foreach ( $files as $file ) {
			$main = realpath( "$dir/conf.d/main.conf" ) === $file;
			if ( 'admin-tools.conf' === basename( $file ) && self::is_ee_admin_tools( $file ) ) {
				continue;
			}
			self::each_line(
				$file,
				function ( $line ) use ( &$roots, &$sure, &$first, $main ) {
					foreach ( self::nginx_paths( $line ) as list( $directive, $value ) ) {
						$path   = null === $value ? null : self::container_path( $value );
						$in_www = null !== $path && ( '/var/www' === $path || 0 === strpos( $path, '/var/www/' ) );
						// The scanner refuses a `.` or `..` segment or a control character in COMPAT_SERVED.
						$plain = $in_www && ! preg_match( '#/\.\.?(?:/|$)|[\x00-\x1F\x7F]#', $path );
						if ( null === $value || false !== strpos( $value, '$' ) || ( $in_www && ( 'alias' === $directive || ! $plain ) ) ) {
							$sure = false;
						} elseif ( $in_www ) {
							$roots[ $path ] = true;
							$first          = $main && null === $first ? $path : $first;
						}
					}
				}
			);
		}
		$this->served = $sure && 1 === count( $roots ) ? (string) key( $roots ) : '';

		$db   = self::container_path( (string) $site_data->site_container_fs_path );
		$db   = '/var/www' === $db ? '/var/www/htdocs' : $db;
		$hint = null !== $first && preg_match( self::ROOT_PATTERN, $first ) ? $first : $db;
		if ( $hint !== $db ) {
			EE::warning( "EE's record of {$this->site} has $db as its public directory, but nginx serves $hint. Checking what nginx serves." );
		}
		if ( '' === $this->served ) {
			EE::warning( "config/nginx/ of {$this->site} doesn't name one directory nginx serves, so old releases of the site are checked too." );
		}

		return $hint;
	}

	/**
	 * @param string $line A line of an nginx file.
	 *
	 * @return array[] [ directive, value ] for each root or alias on it; value null when it doesn't end on this line.
	 */
	protected static function nginx_paths( $line ) {
		if ( null === $line ) {
			return [ [ 'root', null ] ];
		}
		// nginx reads `#` literally inside a regex or a quoted value: only one at a token start begins a comment.
		$code = preg_replace( '/(?<![^\s;{}])#.*$/', '', $line );
		if ( null !== $code && $code !== $line ) {
			$bare = preg_replace( '/"(?:[^"\\\\]++|\\\\.)*+"|\'(?:[^\'\\\\]++|\\\\.)*+\'/', '', $code );
			// That `#` was inside an open quote.
			$code = null === $bare || false !== strpbrk( $bare, '"\'' ) ? null : $code;
		}
		// Possessive, so a long run of blanks can't exhaust PCRE's backtracking.
		$pattern = '/(?:^|[;{}])\s*(root|alias)(?:\s++([^;{}]*?)\s*(;)|\s*$|\s+[^;{}]*$)/';
		if ( null === $code || false === preg_match_all( $pattern, $code, $all, PREG_SET_ORDER ) ) {
			return [ [ 'root', null ] ];
		}
		$found = [];
		foreach ( $all as $m ) {
			$found[] = [ $m[1], isset( $m[3] ) ? trim( $m[2], '"\'' ) : null ];
		}

		return $found;
	}

	/**
	 * @param string $file A file named admin-tools.conf.
	 *
	 * @return bool Whether it is EE's own, whose root serves only /ee-admin/.
	 */
	protected static function is_ee_admin_tools( $file ) {
		if ( filesize( $file ) > 2 * strlen( self::ADMIN_TOOLS_CONF ) ) {
			return false;
		}
		$text = (string) file_get_contents( $file );

		return self::ADMIN_TOOLS_CONF === str_replace( "\r\n", "\n", $text );
	}

	/**
	 * @param string $dir Directory.
	 *
	 * @return string[] Its entries without . and ..; none when it is missing or unreadable.
	 */
	protected static function entries( $dir ) {
		$names = is_dir( $dir ) ? scandir( $dir ) : false;

		return false === $names ? [] : array_values( array_diff( $names, [ '.', '..' ] ) );
	}

	/**
	 * @param string $dir     Directory.
	 * @param bool   $outside Set when a link below it leads out of it; that target is the nginx container's, not the host's.
	 *
	 * @return string[] Real paths of the regular files below it, links inside it followed, each directory once.
	 */
	protected static function files_under( $dir, &$outside = false ) {
		$base    = realpath( $dir );
		$files   = [];
		$todo    = false === $base ? [] : [ $base ];
		$seen    = [];
		$outside = false;
		while ( $todo ) {
			$current = array_pop( $todo );
			foreach ( self::entries( $current ) as $name ) {
				$real = realpath( "$current/$name" );
				if ( false === $real ) {
					// A dangling link usually leads into the nginx container: not the host's, so not sure either.
					$outside = $outside || is_link( "$current/$name" );
					continue;
				}
				if ( isset( $seen[ $real ] ) ) {
					continue;
				}
				$seen[ $real ] = true;
				if ( $real !== $base && 0 !== strpos( $real, "$base/" ) ) {
					$outside = true;
				} elseif ( is_dir( $real ) ) {
					$todo[] = $real;
				} elseif ( is_file( $real ) ) {
					$files[] = $real;
				}
			}
		}

		return $files;
	}

	/**
	 * @param string $path Container path as nginx or EE's record writes it.
	 *
	 * @return string It with repeated and trailing slashes dropped.
	 */
	protected static function container_path( $path ) {
		$path = rtrim( preg_replace( '#/+#', '/', $path ), '/' );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Where WordPress probably is, read from the host: the first candidate holding wp-settings.php and wp-includes/. The
	 * scanner checks it again in its own view; with none, the served root is passed and every install found is judged.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Served root relative to app/.
	 *
	 * @return string The WordPress root relative to app/.
	 */
	protected function wordpress_hint( $code, $rel ) {
		$loader = self::index_loader( (string) self::resolve_in( $code, "$rel/index.php" ) );
		$cli    = $this->wp_cli_path( $code, $rel );
		foreach ( [ $rel, null === $loader ? null : "$rel/$loader", "$rel/current", $cli ] as $candidate ) {
			$candidate = null === $candidate ? '' : trim( preg_replace( [ '#/+#', '#(?<=^|/)\./#' ], [ '/', '' ], $candidate ), '/' );
			// The scanner refuses a path with `.` or `..` segments or control characters; such a root is judged as another install.
			if ( '' === $candidate || preg_match( '#(?:^|/)\.\.?(?:/|$)|[\x00-\x1F]#', $candidate ) ) {
				continue;
			}
			$settings = self::resolve_in( $code, "$candidate/wp-settings.php" );
			if ( null !== $settings && is_file( $settings ) && is_dir( dirname( $settings ) . '/wp-includes' ) ) {
				return $candidate;
			}
		}

		return $rel;
	}

	/**
	 * @param string $index Real path of the served index.php.
	 *
	 * @return string|null The directory its first literal wp-blog-header.php require names, relative to it.
	 */
	protected static function index_loader( $index ) {
		$dir = null;
		self::each_line(
			$index,
			function ( $line ) use ( &$dir ) {
				// `__DIR__ . '/x/wp-blog-header.php'`, `dirname( __FILE__ ) . '/x/…'` or `'./x/…'`.
				$base    = '(?:__DIR__|dirname\(\s*__FILE__\s*\))\s*\.\s*[\'"]/?|[\'"]\./';
				$pattern = '#(?:' . $base . ')((?:[^\'"]*/)?)wp-blog-header\.php[\'"]#';
				if ( null === $dir && null !== $line && preg_match( $pattern, $line, $m ) ) {
					$dir = rtrim( $m[1], '/' );
				}
			}
		);

		return $dir;
	}

	/**
	 * @param string $code Real path of the site's app/.
	 * @param string $dir  Directory relative to app/ whose wp-cli.yml is read.
	 *
	 * @return string|null Its `path:` relative to app/, when that stays inside /var/www.
	 */
	protected function wp_cli_path( $code, $dir ) {
		$path = null;
		self::each_line(
			(string) self::resolve_in( $code, "$dir/wp-cli.yml" ),
			function ( $line ) use ( &$path ) {
				if ( null === $path && null !== $line && preg_match( '/^path:[ \t]*([\'"]?)([^\'"]+?)\1[ \t]*$/', $line, $m ) ) {
					$path = $m[2];
				}
			}
		);
		if ( null === $path ) {
			return null;
		}
		if ( '/' !== $path[0] ) {
			return "$dir/$path";
		}

		return 0 === strpos( $path . '/', '/var/www/' ) ? ltrim( substr( $path, strlen( '/var/www' ) ), '/' ) : null;
	}

	/**
	 * Whether a `current` link in this directory leads into a real releases/ beside it: nginx serving it serves every release.
	 *
	 * @param string $code Real path of the site's app/.
	 * @param string $rel  Directory relative to app/.
	 *
	 * @return bool
	 */
	protected static function release_layout_in( $code, $rel ) {
		$base = self::resolve_in( $code, $rel );
		$live = self::resolve_in( $code, ltrim( "$rel/current", '/' ) );
		if ( null === $base || null === $live || ! is_link( "$base/current" ) || is_link( "$base/releases" ) ) {
			return false;
		}

		return is_dir( $live ) && dirname( $live ) === "$base/releases";
	}

	/**
	 * --cancel: stop the site's running check or estimate, wait until it has let go of the site lock, then remove what a
	 * killed check left in the temporary directory.
	 *
	 * @param object $site_data Site record.
	 *
	 * @return array 'doc' (cancelled, not_running or tool_error) and 'report' (always null).
	 */
	protected function run_cancel( $site_data ) {
		$parent = $this->temp_parent();
		$marker = null === $parent ? null : $this->cancel_path( $parent );
		if ( null === $marker ) {
			return $this->doc( 'tool_error', 'could not create the temporary directory' );
		}
		// The request stops a check that is still before its container (pulling the image, reading the layout).
		if ( ! @touch( $marker ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
			return $this->doc( 'tool_error', 'could not write the cancel request' );
		}
		$stopped = $this->stop_containers();
		if ( is_string( $stopped ) ) {
			return $this->doc( 'tool_error', $stopped );
		}
		if ( $this->restore_running( $site_data->site_fs_path ) ) {
			@unlink( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			return $this->doc( 'tool_error', 'a restore of the site is running' );
		}
		$waited = ! $this->lock_site( $site_data->site_fs_path );
		if ( $waited && ! $this->wait_for_site_lock( $site_data->site_fs_path, $this->cancel_wait() ) ) {
			// The request stays: the check still reports cancelled when it gets to it.
			return $this->doc( 'tool_error', 'the running check did not stop within ' . $this->cancel_wait() . ' s' );
		}
		// A check that stopped for the request removed it; one still there was taken by no check.
		clearstatcache( true, $marker );
		$taken = ! file_exists( $marker );
		@unlink( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		// Without the lock a live check may still own the directory.
		if ( null !== $this->lock ) {
			$this->remove_site_temp( $parent );
		}

		return $this->doc( $stopped || ( $waited && $taken ) ? 'cancelled' : 'not_running' );
	}

	/**
	 * Remove the site's scan container and its estimate containers, running or left by a killed run.
	 *
	 * @return bool|string Whether one of them was running, or why one couldn't be removed.
	 */
	protected function stop_containers() {
		$name   = 'ee-php-compat-' . $this->site;
		$status = $this->container_status( $name );
		if ( 'unknown' === $status ) {
			return "could not check the container $name";
		}
		if ( false !== $status && ! $this->container_gone( $name ) ) {
			return "could not remove the container $name";
		}
		$running = 'running' === $status;
		$label   = 'label=ee-php-compat-estimate-site=' . $this->site;
		$ps      = $this->docker( [ 'docker', 'ps', '-a', '--no-trunc', '--format', '{{.ID}} {{.State}}', '--filter', $label ], 60 );
		if ( 0 !== $ps['code'] ) {
			return 'could not list the estimate containers of the site';
		}
		foreach ( preg_split( '/\n/', trim( $ps['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
			list( $id, $state ) = explode( ' ', "$line ", 2 );
			$rm                 = $this->docker( [ 'docker', 'rm', '-f', $id ], 60 );
			// --rm may have removed it in the meantime.
			if ( 0 !== $rm['code'] && ! preg_match( '/no such container/i', $rm['stderr'] ) ) {
				return 'could not remove the estimate container ' . self::clean( $id );
			}
			$running = $running || 'running' === trim( $state );
		}

		return $running;
	}

	/**
	 * Outside the per-site directory a scan clears; a site name never starts with a dot.
	 *
	 * @param string $parent temp_parent().
	 *
	 * @return string|null The site's cancel request file.
	 */
	protected function cancel_path( $parent ) {
		return $this->own_dir( "$parent/.cancel" ) ? "$parent/.cancel/{$this->site}" : null;
	}

	/**
	 * With the site lock, watch its cancel request. A request younger than twice CANCEL_WAIT may come from a --cancel that is
	 * still waiting, even one sent just before this run took the lock, so only an older one is dropped as stale.
	 */
	protected function watch_cancel() {
		$parent              = $this->temp_parent();
		$this->cancel_marker = null === $parent ? null : $this->cancel_path( $parent );
		if ( null === $this->cancel_marker ) {
			return;
		}
		clearstatcache( true, $this->cancel_marker );
		$written = @filemtime( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- usually missing.
		if ( false !== $written && time() - $written > 2 * self::CANCEL_WAIT ) {
			@unlink( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
	}

	/**
	 * @return bool Whether --cancel asked this run to stop.
	 */
	protected function cancel_requested() {
		if ( null === $this->cancel_marker ) {
			return false;
		}
		clearstatcache( true, $this->cancel_marker );

		return file_exists( $this->cancel_marker );
	}

	/**
	 * The run's result once cancelled; removing the request tells the waiting --cancel that this run stopped for it.
	 *
	 * @return array 'doc' and 'report'.
	 */
	protected function cancelled() {
		$this->take_request();

		return $this->doc( 'cancelled' );
	}

	/**
	 * Remove the cancel request this run stopped for: the waiting --cancel reads that as "stopped".
	 */
	protected function take_request() {
		@unlink( $this->cancel_marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- another run may have taken it.
		$this->cancel_marker = null;
	}

	/**
	 * @param string $site_fs_path Site directory.
	 * @param int    $seconds      How long to try.
	 *
	 * @return bool Whether this run got the site lock in time.
	 */
	protected function wait_for_site_lock( $site_fs_path, $seconds ) {
		$until = microtime( true ) + $seconds;
		do {
			usleep( 100000 );
			$this->dispatch_signals();
			if ( $this->lock_site( $site_fs_path ) ) {
				return true;
			}
		} while ( microtime( true ) < $until );

		return false;
	}

	/**
	 * Let SIGTERM and SIGINT reach the command's handlers while this process polls.
	 */
	protected function dispatch_signals() {
		// The site command's dispatcher calls pcntl too, which a PHP without the extension lacks.
		if ( ! function_exists( 'pcntl_signal_dispatch' ) ) {
			return;
		}
		null === $this->dispatch ? pcntl_signal_dispatch() : call_user_func( $this->dispatch );
	}

	/**
	 * @return int Seconds; tests wait less.
	 */
	protected function cancel_wait() {
		return self::CANCEL_WAIT;
	}

	/**
	 * The `docker run` command line. Estimates have no name, so they never conflict, and no /out.
	 *
	 * @param string      $mode      scan or estimate.
	 * @param string      $image     Image with tag.
	 * @param object      $site_data Site record.
	 * @param string      $code      Real path of the site's code.
	 * @param string|null $out       Directory mounted at /out (scan only).
	 * @param int|null    $timeout   --timeout (scan only).
	 *
	 * @return string[]
	 */
	public function docker_run_args( $mode, $image, $site_data, $code, $out = null, $timeout = null ) {
		// No --sig-proxy: the client would pass a terminal's SIGHUP on to the scanner.
		$args = [ 'docker', 'run', '--rm', '--init', '--sig-proxy=false' ];
		if ( 'scan' === $mode ) {
			array_push( $args, '--name', 'ee-php-compat-' . $this->site );
		}
		// No log driver: the daemon would otherwise keep its own unbounded copy of the container's stdout.
		array_push(
			$args,
			'--network', 'none', '--log-driver', 'none', '--read-only', '--tmpfs', '/tmp:size=64m',
			'--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--pids-limit', '64',
			'--memory', '1g', '--memory-swap', '1g', '--cpus', '1', '--cpu-shares', '256',
			'--ulimit', 'core=0',
			'--user', '33:33',
			// The scanner stops at the same report size EE accepts.
			'-e', 'COMPAT_SITE=' . $this->site, '-e', 'COMPAT_IMAGE=' . $image, '-e', 'COMPAT_REPORT_MAX=' . self::REPORT_MAX,
			// Always with `=`: an empty value means "not sure" or "none", while a bare name would pass EE's own environment.
			'-e', 'COMPAT_SERVED=' . $this->served,
			'-e', 'COMPAT_REQUIRE=' . implode( "\n", $this->require ),
			'-e', 'COMPAT_EXTRA_ROOTS=' . implode( "\n", $this->extra_roots ),
			'-e', 'COMPAT_WP_SUPPORTED=' . implode( ',', self::supported_branches( $this->target ) ),
			'--mount', "type=bind,src=$code,dst=/var/www,readonly"
		);
		if ( 'estimate' === $mode && null !== $this->estimate_label ) {
			// The site label lets `ee site delete` stop a running estimate.
			array_push( $args, '--label', 'ee-php-compat-estimate=' . $this->estimate_label, '--label', 'ee-php-compat-estimate-site=' . $this->site );
		}
		if ( 'estimate' === $mode ) {
			// The byte model misses PHPCS's per-file cost on dense many-file trees (2x short seen): more per batch, wider top end.
			array_push( $args, '-e', 'COMPAT_SECONDS_PER_BATCH=' . self::ESTIMATE_SECONDS_PER_BATCH );
			array_push( $args, '-e', 'COMPAT_HIGH_FACTOR=' . self::ESTIMATE_HIGH_FACTOR );
		}
		$args = array_merge( $args, $this->binds );
		if ( 'scan' === $mode ) {
			array_push( $args, '--mount', "type=bind,src=$out,dst=/out" );
		}
		array_push( $args, $image, $mode, $site_data->site_type, $this->scan_path, $this->target );
		if ( 'scan' === $mode ) {
			$args[] = (string) $timeout;
		}

		return $args;
	}

	/**
	 * @return string|null Image with its tag from img-versions.json.
	 */
	protected function image() {
		$versions = \EE\Utils\get_image_versions();

		return empty( $versions[ self::IMAGE ] ) ? null : self::IMAGE . ':' . $versions[ self::IMAGE ];
	}

	/**
	 * `app` is a symlink to the htdocs volume; Docker needs its real path.
	 *
	 * @param string $site_fs_path Site directory.
	 *
	 * @return string|null|false Real path; null when there is no app/ at all; false when app/ is there but can't be resolved.
	 */
	protected function code_dir( $site_fs_path ) {
		$app = $site_fs_path . '/app';
		if ( ! is_link( $app ) && ! file_exists( $app ) ) {
			return null;
		}
		$real = realpath( $app );
		if ( false === $real && is_link( $app ) ) {
			if ( ! preg_match( '#/volumes/([A-Za-z0-9][A-Za-z0-9_.-]*)/_data/?$#D', (string) readlink( $app ), $m ) ) {
				return false;
			}
			// The link holds Docker's root dir of when the volume was made; ask Docker where the volume is now.
			$run  = $this->docker( [ 'docker', 'volume', 'inspect', '--format', '{{.Mountpoint}}', $m[1] ], 60 );
			$real = 0 === $run['code'] && '' !== trim( $run['stdout'] ) ? realpath( trim( $run['stdout'] ) ) : false;
		}

		return ( false !== $real && is_dir( $real ) ) ? $real : false;
	}

	/**
	 * Every assignment of the settings that load or change code (auto_prepend_file, auto_append_file, opcache.preload,
	 * include_path, user_ini.filename, zend.multibyte, zend.script_encoding) and FPM's include= and
	 * security.limit_extensions, in file order.
	 *
	 * @param string $text Its text.
	 * @param string $kind ini or fpm.
	 *
	 * @return array[] [ key, value (unquoted), whether it was quoted ] each.
	 */
	public static function php_settings( $text, $kind ) {
		// PHP's ini scanner ends a line at \r\n, \n or a bare \r; a UTF-8 BOM before the first key is skipped.
		$text    = str_replace( [ "\r\n", "\r" ], "\n", preg_replace( '/^\xEF\xBB\xBF/', '', $text ) );
		$keys    = '(auto_prepend_file|auto_append_file|opcache\.preload|include_path|user_ini\.filename|zend\.multibyte|zend\.script_encoding)';
		// PHP's scanner takes an assignment right after a section header on the same line (`[PHP] auto_prepend_file=…`).
		$section = '(?:\[[^\]\n]*\][ \t]*)*';
		$pool    = '(?:php(?:_admin)?_(?:value|flag)[ \t]*\[[ \t]*["\']?' . $keys . '["\']?[ \t]*\]|(include|security\.limit_extensions))';
		$pattern = '/^[ \t]*' . $section . ( 'fpm' === $kind ? $pool : $keys . '()' ) . '[ \t]*=[ \t]*([^\n]*)$/mi';
		$out     = [];
		preg_match_all( $pattern, $text, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			$value  = trim( $m[3] );
			$quoted = false;
			if ( preg_match( '/^(["\'])(.*?)\1(.*)$/', $value, $q ) ) {
				$rest = trim( $q[3] );
				// PHP joins adjacent parts ("/a".php): anything but a comment after the quote keeps the raw text, never matched.
				$value  = '' === $rest || ';' === $rest[0] ? $q[2] : $value;
				$quoted = true;
			} else {
				$value = trim( preg_replace( '/[ \t]*;.*$/', '', $value ) );
			}
			$out[] = [ strtolower( '' !== $m[1] ? $m[1] : $m[2] ), $value, $quoted ];
		}

		return $out;
	}


	/**
	 * Whether a named volume is a plain local volume EE made: its EasyEngine label, and no options (a bind or a remote
	 * filesystem under the same name would have them).
	 *
	 * @param string $name Volume name.
	 *
	 * @return bool
	 */
	protected function ee_plain_volume( $name ) {
		$run  = $this->docker( [ 'docker', 'volume', 'inspect', '--format', '{{json .}}', $name ], 60 );
		$info = 0 === $run['code'] ? json_decode( trim( $run['stdout'] ), true ) : null;

		return is_array( $info ) && isset( $info['Labels']['org.label-schema.vendor'] ) && 'EasyEngine' === $info['Labels']['org.label-schema.vendor']
			&& ( ! isset( $info['Driver'] ) || 'local' === $info['Driver'] ) && empty( $info['Options'] );
	}


	/**
	 * Resolves a path under app/ as the container sees it: a link to /var/www/... points into app/.
	 *
	 * @param string      $code Real path of the site's app/.
	 * @param string      $rel  Path relative to app/.
	 * @param string|null $why  Set on null: 'missing' (the kernel can't follow it either) or 'escape' (it leaves app/, or
	 *                          this can't tell where the kernel would take it, e.g. /var/www/../www or /proc/self/root).
	 * @param array|null  $passed Set to the links passed on the way (their real-location host paths).
	 *
	 * @return string|null Real host path inside app/, or null when it doesn't exist or leaves app/.
	 */
	public static function resolve_in( $code, $rel, &$why = null, &$passed = null ) {
		$why    = 'escape';
		$passed = [];
		// Path text from the site's files: a NUL or another control character is never a real path here.
		if ( preg_match( '/[\x00-\x1F\x7F]/', $rel ) ) {
			return null;
		}
		$todo = array_values( array_filter( explode( '/', $rel ), 'strlen' ) );
		$done = [];
		$hops = 0;
		while ( $todo ) {
			$part = array_shift( $todo );
			if ( '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $done ) {
					return null;
				}
				array_pop( $done );
				continue;
			}
			$path = $code . '/' . implode( '/', array_merge( $done, [ $part ] ) );
			if ( is_link( $path ) ) {
				$passed[] = $path;
				$target = readlink( $path );
				if ( ++$hops > 40 ) {
					$why = 'missing';
					return null;
				}
				if ( false === $target || preg_match( '/[\x00-\x1F\x7F]/', $target ) ) {
					return null;
				}
				if ( '/' === substr( $target, 0, 1 ) ) {
					if ( 0 !== strpos( $target . '/', '/var/www/' ) ) {
						return null;
					}
					$done   = [];
					$target = substr( $target, strlen( '/var/www' ) );
				}
				$todo = array_merge( array_values( array_filter( explode( '/', $target ), 'strlen' ) ), $todo );
				continue;
			}
			// Only a directory can be passed through, as in the kernel.
			if ( ! file_exists( $path ) || ( $todo && ! is_dir( $path ) ) ) {
				$why = 'missing';
				return null;
			}
			$done[] = $part;
		}

		$why = null;

		return rtrim( $code . '/' . implode( '/', $done ), '/' );
	}

	/**
	 * @param string $site_fs_path Site directory.
	 *
	 * @return bool False when another php-compat run holds it.
	 */
	protected function lock_site( $site_fs_path ) {
		// Close-on-exec: a child (docker pull, rm) must not keep the lock after this process is killed.
		$handle                = @fopen( $site_fs_path, 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		$this->lock_unopenable = false === $handle;
		if ( false === $handle ) {
			EE::debug( "Could not open $site_fs_path to lock it; relying on the container name." );

			return true;
		}
		if ( ! flock( $handle, LOCK_EX | LOCK_NB, $would_block ) ) {
			fclose( $handle );
			if ( ! $would_block ) {
				EE::debug( "Could not lock $site_fs_path; relying on the container name." );

				return true;
			}

			return false;
		}
		$this->lock = $handle;

		return true;
	}

	/**
	 * A restore holds the site directory shared and every php-compat run exclusive, so only shared holders mean a restore.
	 * Briefly locks it when no one holds it.
	 *
	 * @param string $site_fs_path Site directory.
	 *
	 * @return bool
	 */
	protected function restore_running( $site_fs_path ) {
		$handle = @fopen( $site_fs_path, 're' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- none then.
		if ( false === $handle ) {
			return false;
		}
		$restore = flock( $handle, LOCK_SH | LOCK_NB ) && ! flock( $handle, LOCK_EX | LOCK_NB );
		fclose( $handle );

		return $restore;
	}

	/**
	 * @param string $name Container name.
	 *
	 * @return string|false running, exited, unknown (docker failed or timed out), or false when there is none.
	 */
	protected function container_status( $name ) {
		$run = $this->docker( [ 'docker', 'inspect', '--format', '{{.State.Running}}', $name ], 60 );
		if ( 0 === $run['code'] ) {
			return 'true' === trim( $run['stdout'] ) ? 'running' : 'exited';
		}
		// Only Docker's own answer means none: a daemon that fails or hangs may still run it.
		return 1 === $run['code'] && preg_match( '/no such (object|container)/i', $run['stderr'] ) ? false : 'unknown';
	}

	/**
	 * Remove a container if it still exists and confirm it is gone.
	 *
	 * @param string $name Container name.
	 *
	 * @return bool
	 */
	protected function container_gone( $name ) {
		$this->docker( [ 'docker', 'rm', '-f', $name ], 60 );
		for ( $try = 1; ; $try++ ) {
			if ( false === $this->container_status( $name ) ) {
				return true;
			}
			if ( 3 === $try ) {
				return false;
			}
			sleep( 1 );
		}
	}

	/**
	 * Use the pinned scanner image when its contract label is the one this EasyEngine reads; pull it only when it is
	 * missing or the label differs, so a host contacts the registry once per tag and contract.
	 *
	 * @param string $image Image with tag.
	 *
	 * @return string|null tool_error detail, or null when the image is there and matches.
	 */
	protected function ensure_image( $image ) {
		$contract = $this->image_contract( $image );
		if ( self::CONTRACT === $contract ) {
			return null;
		}
		$mismatch = "the scanner image doesn't match this EasyEngine; run `docker pull $image` and try again";
		$timeout  = null === $contract ? self::PULL_TIMEOUT : self::REPULL_TIMEOUT;
		EE::debug( "Pulling $image." );
		$pull = $this->docker( [ 'docker', 'pull', '--quiet', $image ], $timeout, true );
		if ( 0 !== $pull['code'] || $pull['timed_out'] ) {
			$why = $pull['timed_out'] ? "timed out after $timeout s" : self::first_line( $pull['stderr'] );
			if ( null !== $contract ) {
				return $mismatch;
			}

			return 'image pull failed' . ( '' === $why ? '' : ": $why" );
		}
		$this->remove_other_tags( $image );

		return self::CONTRACT === $this->image_contract( $image ) ? null : $mismatch;
	}

	/**
	 * @param string $image Image with tag.
	 *
	 * @return string|null Its contract label, '' when it has none, or null when the image isn't there.
	 */
	protected function image_contract( $image ) {
		$run = $this->docker( [ 'docker', 'image', 'inspect', '--format', '{{json .Config.Labels}}', $image ], 60 );
		if ( 0 !== $run['code'] ) {
			return null;
		}
		$labels = json_decode( trim( $run['stdout'] ), true );

		return isset( $labels[ self::CONTRACT_LABEL ] ) && is_string( $labels[ self::CONTRACT_LABEL ] ) ? $labels[ self::CONTRACT_LABEL ] : '';
	}

	/**
	 * @param string $text Command output.
	 *
	 * @return string Its first non-empty line, cleaned and capped, for a one-line detail.
	 */
	protected static function first_line( $text ) {
		foreach ( preg_split( '/\r?\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				return self::clean( strlen( $line ) > 200 ? substr( $line, 0, 200 ) . '...' : $line );
			}
		}

		return '';
	}

	/**
	 * After pulling a new tag, remove the scanner's other tags that no container uses; failures only cost disk.
	 *
	 * @param string $image The pinned image with its tag, never removed.
	 */
	protected function remove_other_tags( $image ) {
		// Only with no other check running: one between its pull and its `docker run` has no container that keeps its tag.
		if ( null !== $this->image_lock ) {
			flock( $this->image_lock, LOCK_UN );
			$alone = flock( $this->image_lock, LOCK_EX | LOCK_NB );
			if ( ! $alone ) {
				flock( $this->image_lock, LOCK_SH );
				EE::debug( 'Keeping the other ' . self::IMAGE . ' tags: another check is running.' );

				return;
			}
		}
		try {
			$this->remove_unused_tags( $image );
		} finally {
			if ( null !== $this->image_lock ) {
				flock( $this->image_lock, LOCK_SH );
			}
		}
	}

	/**
	 * @param string $image The pinned image with its tag, never removed.
	 */
	protected function remove_unused_tags( $image ) {
		$list = $this->docker( [ 'docker', 'image', 'ls', '--format', '{{.Tag}}', self::IMAGE ], 60 );
		if ( 0 !== $list['code'] ) {
			EE::debug( 'Could not list the ' . self::IMAGE . ' tags.' );

			return;
		}
		foreach ( array_unique( preg_split( '/\s+/', trim( $list['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) ) as $tag ) {
			$old = self::IMAGE . ':' . $tag;
			if ( $old === $image || ! preg_match( '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/', $tag ) ) {
				continue;
			}
			$users = $this->docker( [ 'docker', 'ps', '-a', '-q', '--filter', "ancestor=$old" ], 60 );
			if ( 0 !== $users['code'] || '' !== trim( $users['stdout'] ) ) {
				EE::debug( "Keeping $old: a container uses it." );
				continue;
			}
			// Without -f, Docker also refuses an image a container started in the meantime.
			if ( 0 !== $this->docker( [ 'docker', 'image', 'rm', $old ], 60 )['code'] ) {
				EE::debug( "Could not remove $old." );
			}
		}
	}

	/**
	 * Hold the host-wide image lock shared until cleanup(); without it (no usable temp directory) old tags are removed as before.
	 */
	protected function hold_image() {
		$parent = $this->temp_parent();
		$handle = null === $parent ? false : @fopen( "$parent/.image-lock", 'ce' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- handled below.
		if ( false === $handle ) {
			return;
		}
		// Held exclusive only while another check removes old tags; polled, so signals still reach the handlers.
		$until = microtime( true ) + 60;
		while ( ! flock( $handle, LOCK_SH | LOCK_NB, $would_block ) ) {
			if ( ! $would_block || microtime( true ) >= $until ) {
				fclose( $handle );
				EE::debug( 'Going on without the scanner image lock.' );

				return;
			}
			usleep( 100000 );
			$this->dispatch_signals();
		}
		$this->image_lock = $handle;
	}

	/**
	 * This run's /out: a new random directory in a root-only per-site directory, after removing what a killed run left there.
	 *
	 * Only called with the site lock held and no container of the site left, so nothing else uses that directory.
	 *
	 * @return string|null Its real path.
	 */
	protected function make_temp_dir() {
		$parent = $this->temp_parent();
		if ( null === $parent ) {
			return null;
		}
		$site_dir = $parent . '/' . $this->site;
		if ( ! $this->own_dir( $site_dir ) ) {
			EE::debug( "Refusing $site_dir as a temporary directory: not a directory of this user with mode 0700." );

			return null;
		}

		// One that can't be removed doesn't block this run: it gets its own directory.
		$this->remove_leftovers( $site_dir );

		$dir = $site_dir . '/' . bin2hex( random_bytes( 8 ) );
		if ( ! @mkdir( $dir, 0700 ) ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
			return null;
		}
		$this->temp_dir = $dir;
		$this->register_cleanup();
		clearstatcache( true, $dir );
		// Without root (macOS), Docker Desktop's file sharing maps the owner itself.
		if ( is_link( $dir ) || ! is_dir( $dir ) || ! chmod( $dir, 0700 ) || ( 0 === self::euid() && ! ( chown( $dir, 33 ) && chgrp( $dir, 33 ) ) ) || ( null === self::euid() && ! ( @chown( $dir, 33 ) && @chgrp( $dir, 33 ) ) && 0 === fileowner( $dir ) ) ) {
			return null;
		}
		$real = realpath( $dir );
		if ( false === $real ) {
			return null;
		}
		$this->temp_dir = $real;

		return $real;
	}

	/**
	 * Remove what killed runs left in the per-site directory. Only with the site lock held and no container of the site left.
	 *
	 * @param string $site_dir Per-site directory, checked with own_dir().
	 */
	protected function remove_leftovers( $site_dir ) {
		foreach ( array_diff( (array) @scandir( $site_dir ), [ '.', '..', false ] ) as $entry ) { // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- an unreadable dir has nothing to remove.
			EE::debug( "Removing the leftover temporary directory $site_dir/" . self::clean( $entry ) . '.' );
			$this->remove_tree( "$site_dir/$entry" );
		}
	}

	/**
	 * Remove the directory holding the site's scan /out directories. Only with the site lock held and the scan container removed.
	 *
	 * @param string $parent temp_parent().
	 */
	protected function remove_site_temp( $parent ) {
		$dir = "$parent/{$this->site}";
		if ( is_dir( $dir ) && ! is_link( $dir ) ) {
			$this->remove_tree( $dir );
		}
	}

	/**
	 * @return string|null EE_ROOT_DIR/tmp/php-compat, checked to be root-only; null when it isn't.
	 */
	protected function temp_parent() {
		$base = rtrim( $this->temp_base(), '/' );
		foreach ( [ $base, $base . '/' . self::TEMP_DIR ] as $dir ) {
			if ( ! $this->own_dir( $dir ) ) {
				EE::debug( "Refusing $dir as a temporary directory: not a directory of this user with mode 0700." );

				return null;
			}
		}

		return $base . '/' . self::TEMP_DIR;
	}

	/**
	 * Create a directory 0700 when missing, and accept it only as a real directory of this user that no one else can enter.
	 *
	 * @param string $dir Path.
	 *
	 * @return bool
	 */
	protected function own_dir( $dir ) {
		@mkdir( $dir, 0700 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- it may exist; checked below.
		clearstatcache( true, $dir );
		$stat = @lstat( $dir ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$euid = self::euid();
		if ( ! $stat || 0040000 !== ( $stat['mode'] & 0170000 ) || ( null !== $euid && $stat['uid'] !== $euid ) ) {
			return false;
		}

		return 0 === ( $stat['mode'] & 0077 ) || chmod( $dir, 0700 );
	}

	/**
	 * Remove a tree without following symlinks: rm's *at() calls also survive a directory swapped mid-way and any depth.
	 *
	 * @param string $path Path.
	 *
	 * @return bool Whether it is gone.
	 */
	protected function remove_tree( $path ) {
		$argv = ( defined( 'IS_DARWIN' ) && IS_DARWIN ) ? [ 'rm', '-rf', '--', $path ] : [ 'rm', '-rf', '--one-file-system', '--', $path ];
		$run  = $this->run_process( $argv, 600 );
		clearstatcache( true, $path );
		if ( file_exists( $path ) || is_link( $path ) ) {
			// stderr can hold names chosen inside the container.
			EE::debug( 'rm: ' . self::clean( trim( $run['stderr'] ) ) );

			return false;
		}

		return true;
	}

	/**
	 * @return string Base directory with a trailing slash.
	 */
	protected function temp_base() {
		return EE_ROOT_DIR . '/tmp/';
	}

	/**
	 * Run a docker command without EE::exec()/EE::launch(), which would copy its output into ee.log.
	 *
	 * @param string[] $argv     Command and arguments.
	 * @param int      $timeout  Seconds.
	 * @param bool     $bounded  Stop it once stdout or stderr passes its cap.
	 *
	 * @return array code (int|null), stdout, stderr, timed_out, overflow, signal (int|null), cancelled.
	 */
	protected function docker( array $argv, $timeout, $bounded = false ) {
		EE::debug( 'COMMAND: ' . implode( ' ', array_map( 'escapeshellarg', $argv ) ) );
		$run = $this->run_process( $argv, $timeout, $bounded );
		EE::debug( 'RETURN CODE: ' . var_export( $run['code'], true ) );

		return $run;
	}

	/**
	 * @param string[] $argv    Command and arguments.
	 * @param int      $timeout Seconds.
	 * @param bool     $bounded Stop it once stdout or stderr passes its cap.
	 *
	 * @return array See docker().
	 */
	protected function run_process( array $argv, $timeout, $bounded = false ) {
		$result  = [
			'code'      => null,
			'stdout'    => '',
			'stderr'    => '',
			'timed_out' => false,
			'overflow'  => false,
			'signal'    => null,
			'cancelled' => false,
		];
		$process = new Process( $argv );
		$process->setTimeout( $timeout );
		// Process keeps its own copy of all output unless disabled; the callback keeps a capped one.
		$process->disableOutput();
		try {
			$process->start(
				function ( $type, $buffer ) use ( &$result ) {
					$key = Process::OUT === $type ? 'stdout' : 'stderr';
					$max = 'stdout' === $key ? self::STDOUT_MAX : self::STDERR_MAX;
					// One callback can carry everything a fast writer produced since the last read.
					$room = $max + 1 - strlen( $result[ $key ] );
					if ( $room > 0 ) {
						$result[ $key ] .= substr( $buffer, 0, $room );
					}
					if ( strlen( $result[ $key ] ) > $max ) {
						$result['overflow'] = true;
					}
				}
			);
			// wait() never dispatches signals, so poll: SIGTERM/SIGINT then reach the command's handlers, whose exit runs cleanup().
			for ( $tick = 0; $process->isRunning(); $tick++ ) {
				if ( ! $this->cleaning ) {
					$this->dispatch_signals();
				}
				// A cancelled scan stops whatever it runs (a pull, rm, the scan itself) within about 0.2 s; cleanup() always finishes.
				if ( ! $this->cleaning && 0 === $tick % 10 && $this->cancel_requested() ) {
					$process->stop( 0 );
					$result['cancelled'] = true;

					return $result;
				}
				$process->checkTimeout();
				if ( $bounded && $result['overflow'] ) {
					$process->stop( 0 );

					return $result;
				}
				usleep( 20000 );
			}
			$process->wait();
			$result['code'] = $process->getExitCode();
		} catch ( ProcessTimedOutException $e ) {
			$result['timed_out'] = true;
		} catch ( ProcessSignaledException $e ) {
			$result['signal'] = $e->getSignal();
		} catch ( \Exception $e ) {
			EE::debug( 'Could not run ' . $argv[0] . ': ' . self::clean( $e->getMessage() ) );
		}

		return $result;
	}

	/**
	 * @param string $stdout Container stdout.
	 *
	 * @return array|null One JSON object with v and status.
	 */
	protected function parse_document( $stdout ) {
		if ( strlen( $stdout ) > self::STDOUT_MAX ) {
			return null;
		}
		$doc = json_decode( trim( $stdout ), true, 32, self::JSON_UTF8 );
		if ( ! is_array( $doc ) || array_values( $doc ) === $doc || ! array_key_exists( 'v', $doc ) || ! array_key_exists( 'status', $doc ) ) {
			return null;
		}

		return $doc;
	}

	/**
	 * @param array $doc Container stdout document.
	 *
	 * @return bool
	 */
	protected function valid_scan_doc( array $doc ) {
		if ( 1 !== $doc['v'] || ! in_array( $doc['status'], self::SCAN_STATUSES, true ) ) {
			return false;
		}
		if ( 'tool_error' === $doc['status'] ) {
			return isset( $doc['detail'] ) && is_string( $doc['detail'] );
		}
		if ( 'ok' !== $doc['status'] ) {
			return true;
		}
		if ( ! isset( $doc['target'] ) || $this->target !== $doc['target'] || ! array_key_exists( 'safe_to_upgrade', $doc ) || ! isset( $doc['tool'] ) || ! is_array( $doc['tool'] ) ) {
			return false;
		}
		foreach ( self::SUMMARY_INTS as $key ) {
			if ( ! isset( $doc[ $key ] ) || ! is_int( $doc[ $key ] ) || $doc[ $key ] < 0 ) {
				return false;
			}
		}
		if ( $doc['errors'] > 0 ) {
			$verdict = false;
		} else {
			$verdict = $doc['files_not_checked'] > 0 ? null : true;
		}

		return $verdict === $doc['safe_to_upgrade'];
	}

	/**
	 * @param array $doc Container stdout document.
	 *
	 * @return bool
	 */
	protected function valid_estimate_doc( array $doc ) {
		if ( 1 !== $doc['v'] || ! in_array( $doc['status'], self::SCAN_STATUSES, true ) ) {
			return false;
		}
		if ( 'tool_error' === $doc['status'] ) {
			return isset( $doc['detail'] ) && is_string( $doc['detail'] );
		}
		if ( 'ok' !== $doc['status'] ) {
			return true;
		}
		if ( ! isset( $doc['estimate'], $doc['target'], $doc['inputs'] ) || true !== $doc['estimate'] || $this->target !== $doc['target'] || ! is_array( $doc['inputs'] ) ) {
			return false;
		}
		foreach ( self::ESTIMATE_INTS as $key ) {
			if ( ! isset( $doc[ $key ] ) || ! is_int( $doc[ $key ] ) || $doc[ $key ] < 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The report must carry the stdout summary, and its lists must add up to it.
	 *
	 * @param array $report Decoded /out/report.json.
	 * @param array $doc    Validated stdout summary.
	 *
	 * @return bool
	 */
	protected function valid_report( array $report, array $doc ) {
		foreach ( $doc as $key => $value ) {
			if ( ! array_key_exists( $key, $report ) || $report[ $key ] !== $value ) {
				return false;
			}
		}
		if ( ! isset( $report['components'], $report['not_checked'] ) || ! is_array( $report['components'] ) || ! is_array( $report['not_checked'] ) ) {
			return false;
		}
		if ( count( $report['components'] ) !== $doc['components_checked'] || count( $report['not_checked'] ) !== $doc['files_not_checked'] ) {
			return false;
		}
		if ( ! self::valid_installs_and_phars( $report ) ) {
			return false;
		}
		$errors      = 0;
		$warnings    = 0;
		$with_errors = 0;
		foreach ( $report['components'] as $component ) {
			if ( ! is_array( $component ) || ! self::valid_place( $component ) || ! isset( $component['errors'], $component['warnings'], $component['findings'] ) || ! is_int( $component['errors'] ) || ! is_int( $component['warnings'] ) || ! is_array( $component['findings'] ) ) {
				return false;
			}
			foreach ( [ 'name', 'version' ] as $key ) {
				if ( ! array_key_exists( $key, $component ) || ! ( null === $component[ $key ] || is_string( $component[ $key ] ) ) ) {
					return false;
				}
			}
			$counts = [
				'ERROR'   => 0,
				'WARNING' => 0,
			];
			foreach ( $component['findings'] as $finding ) {
				if ( ! is_array( $finding ) || ! isset( $finding['file'], $finding['line'], $finding['type'], $finding['source'], $finding['message'] ) || ! is_string( $finding['file'] ) || ! is_int( $finding['line'] ) || ! in_array( $finding['type'], [ 'ERROR', 'WARNING' ], true ) || ! is_string( $finding['source'] ) || ! is_string( $finding['message'] ) ) {
					return false;
				}
				++$counts[ $finding['type'] ];
			}
			// Every error and warning is listed, so the component's counts are its findings'.
			if ( $counts['ERROR'] !== $component['errors'] || $counts['WARNING'] !== $component['warnings'] ) {
				return false;
			}
			$errors      += $component['errors'];
			$warnings    += $component['warnings'];
			$with_errors += $component['errors'] > 0 ? 1 : 0;
		}
		foreach ( $report['not_checked'] as $entry ) {
			if ( ! is_array( $entry ) || ! self::valid_place( $entry ) || ! isset( $entry['file'], $entry['reason'] ) || ! is_string( $entry['file'] ) || ! is_string( $entry['reason'] ) ) {
				return false;
			}
		}

		return $errors === $doc['errors'] && $warnings === $doc['warnings'] && $with_errors === $doc['components_with_errors'];
	}

	/**
	 * A missing or malformed key here must never read as "no WordPress" or "no phar".
	 *
	 * @param array $report Decoded report.
	 *
	 * @return bool Whether wordpress_installs (at most one live), excluded_old_releases, phars and phar_named_by are well-formed.
	 */
	private static function valid_installs_and_phars( array $report ) {
		$keys = [ 'wordpress_installs', 'excluded_old_releases', 'phars', 'phar_named_by' ];
		if ( array_diff( $keys, array_keys( $report ) ) ) {
			return false;
		}
		$lists = [ $report['wordpress_installs'], $report['phars'], $report['excluded_old_releases'] ];
		if ( array_filter( $lists, 'is_array' ) !== $lists || ! self::all_strings( $report['excluded_old_releases'] ) ) {
			return false;
		}
		$live = 0;
		foreach ( $report['wordpress_installs'] as $install ) {
			$valid = isset( $install['dir'], $install['version'], $install['live'] ) && is_string( $install['dir'] );
			if ( ! $valid || ! is_string( $install['version'] ) || ! is_bool( $install['live'] ) ) {
				return false;
			}
			$live += $install['live'] ? 1 : 0;
		}
		$named = $report['phar_named_by'];
		if ( $live > 1 || ! self::all_strings( $report['phars'] ) ) {
			return false;
		}

		return null === $named || ( is_string( $named ) && [] === $report['phars'] );
	}

	/**
	 * @param array $list List.
	 *
	 * @return bool Whether every item is a string.
	 */
	private static function all_strings( array $list ) {
		return count( array_filter( $list, 'is_string' ) ) === count( $list );
	}

	/**
	 * @param array $item Component or not_checked entry.
	 *
	 * @return bool Whether type, slug and branch have the right types.
	 */
	private static function valid_place( array $item ) {
		return isset( $item['type'], $item['slug'] ) && is_string( $item['type'] ) && is_string( $item['slug'] ) && array_key_exists( 'branch', $item ) && ( null === $item['branch'] || is_string( $item['branch'] ) );
	}

	/**
	 * Upper bound of what json_decode() allocates: a non-empty array or object costs far more than its bytes.
	 *
	 * @param string $raw JSON.
	 *
	 * @return int Bytes.
	 */
	protected static function decode_cost( $raw ) {
		$nodes = substr_count( $raw, '{' ) + substr_count( $raw, '[' );

		// Measured on 7.4 and 8.5: up to ~450 per array or object, ~80 per element (hash growth), ~40 per string, plus the bytes.
		return strlen( $raw ) + $nodes * 450 + ( substr_count( $raw, ',' ) + $nodes ) * 80 + (int) ( substr_count( $raw, '"' ) / 2 ) * 40;
	}

	/**
	 * @return int Bytes the report may still take: what the limit leaves, or with no limit what the host has free.
	 */
	protected function memory_available() {
		$limit = self::bytes( ini_get( 'memory_limit' ) );
		if ( $limit > 0 ) {
			return $limit - memory_get_usage();
		}
		$free = $this->host_memory_available();

		return null === $free ? self::MEMORY_FLOOR - memory_get_usage() : $free;
	}

	/**
	 * Raise the limit so a report that needs more than MEMORY_FLOOR can be read when the host has the memory.
	 *
	 * @param int $cost decode_cost() of the report.
	 *
	 * @return bool Whether the report now fits.
	 */
	protected function make_room( $cost ) {
		$free = $this->host_memory_available();
		// Never past what the host has free, whatever PHP's limit allows.
		if ( null !== $free && $cost > $free ) {
			return false;
		}
		if ( $cost <= $this->memory_available() ) {
			return true;
		}
		if ( self::bytes( ini_get( 'memory_limit' ) ) <= 0 || null === $free ) {
			return false;
		}
		ini_set( 'memory_limit', (string) ( memory_get_usage( true ) + $cost + 67108864 ) );

		return $cost <= $this->memory_available();
	}

	/**
	 * @return int|null MemAvailable of the host, or null when it can't be read (not Linux).
	 */
	protected function host_memory_available() {
		$info = @file_get_contents( '/proc/meminfo' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- null when missing.

		return false !== $info && preg_match( '/^MemAvailable:\s+(\d+) kB$/m', $info, $m ) ? (int) $m[1] * 1024 : null;
	}

	/**
	 * Write the report as root, 0600, through a temporary file and a rename, into the directory validated at the start.
	 *
	 * @param string $path    --output.
	 * @param string $content Report, byte for byte.
	 *
	 * @return bool
	 */
	protected function write_report( $path, $content ) {
		$tmp = $this->output_dir . '/.ee-php-compat-' . bin2hex( random_bytes( 8 ) );
		// 'x' (O_EXCL) never follows or reuses an entry, and the file is written through this handle only, never reopened by name.
		$mask   = umask( 0077 );
		$handle = @fopen( $tmp, 'x' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		umask( $mask );
		if ( false === $handle ) {
			return false;
		}
		$this->output_tmp = $tmp;
		// A default ACL on the directory replaces the umask, so set the mode, and check it on the handle before writing anything.
		@chmod( $tmp, 0600 ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		$created = fstat( $handle );
		$ok      = $created && 0600 === ( $created['mode'] & 07777 );
		for ( $written = 0; $ok && $written < strlen( $content ); $written += $chunk ) {
			$chunk = fwrite( $handle, substr( $content, $written, 1048576 ) );
			$ok    = (bool) $chunk;
		}
		$ok = $ok && fflush( $handle );
		fclose( $handle );
		$target = $this->output_dir . '/' . basename( $path );
		// lchown/lchgrp never follow a link; a setgid directory gives the file its group.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
		$owned = 0 !== self::euid() || ( @lchown( $tmp, 0 ) && @lchgrp( $tmp, 0 ) );
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- reported as tool_error.
		$ok = $ok && $owned && self::replaceable( $target ) && @rename( $tmp, $target );
		if ( ! $ok ) {
			@unlink( $tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		}
		$this->output_tmp = null;

		return $ok;
	}

	/**
	 * @param string $path --output.
	 *
	 * @return bool Whether the report may replace what is there: nothing, a regular file, or a link to one or to nothing.
	 */
	protected static function replaceable( $path ) {
		clearstatcache( true, $path );
		$entry = @lstat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- checked below.
		if ( ! $entry ) {
			return true;
		}
		$type = $entry['mode'] & 0170000;
		if ( 0120000 === $type ) {
			$target = @stat( $path ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a dangling link is fine.
			return ! $target || 0100000 === ( $target['mode'] & 0170000 );
		}

		return 0100000 === $type;
	}

	/**
	 * The verdict from the scanner's result, this check's own not-checked entries and, for WordPress, whether the live
	 * install's core supports the target. Safe only with no error, nothing unchecked, and core supporting the target.
	 *
	 * @param array $doc    Validated scanner summary.
	 * @param array $report Validated report.
	 *
	 * @return array $doc with safe_to_upgrade, checked, not_checked, ignored and, for a live WordPress, its `wordpress` key.
	 */
	protected function scoped( array $doc, array $report ) {
		// Only EE sets these; the scanner can't.
		unset( $doc['checked'], $doc['not_checked'], $doc['wordpress'], $doc['ignored'] );
		$excluded = [];
		foreach ( array_slice( $this->old_releases, 0, self::NOT_CHECKED_STDOUT ) as $dir ) {
			$excluded[] = self::cut( $this->host_name( $dir ) );
		}
		$doc['checked']           = [
			'dir'                   => self::clean( rtrim( $this->app_path, '/' ) ),
			'components'            => $doc['components_checked'],
			'files'                 => $doc['files_checked'],
			'excluded_old_releases' => $excluded,
		];
		$doc['not_checked']       = array_map(
			function ( $entry ) {
				return [
					'type'   => self::clean( $entry['type'] ),
					'slug'   => self::clean( $entry['slug'] ),
					'branch' => null === $entry['branch'] ? null : self::clean( $entry['branch'] ),
					'file'   => self::clean( $entry['file'] ),
					'reason' => self::clean( $entry['reason'] ),
				];
			},
			array_merge( $report['not_checked'], $this->ee_not_checked() )
		);
		$doc['files_not_checked'] = count( $doc['not_checked'] );
		$doc['not_checked_more']  = 0;
		$core                     = $this->core_support();
		if ( null !== $core ) {
			$doc['wordpress'] = [
				'version'            => self::clean( $this->wordpress['version'] ),
				'supports'           => $core['supports'],
				'minimum'            => $core['minimum'],
				'table_newest'       => $core['table_newest'],
				'core_files_checked' => false,
			];
		}
		$doc['ignored'] = [];
		foreach ( array_keys( $this->predis_ignored ) as $file ) {
			$doc['ignored'][] = [
				'file'   => self::cut( $file ),
				'reason' => "nginx-helper's bundled Predis isn't loaded: the site's php image and config load the redis extension",
			];
		}
		foreach ( $this->phars as $phar ) {
			$doc['ignored'][] = [
				'file'   => $phar,
				'reason' => 'phar archive, not checked: no scanned code names a phar',
			];
		}
		if ( $doc['errors'] > 0 || ( null !== $core && false === $core['supports'] ) ) {
			$doc['safe_to_upgrade'] = false;
		} elseif ( $doc['files_not_checked'] > 0 || ( null !== $core && null === $core['supports'] ) ) {
			$doc['safe_to_upgrade'] = null;
		} else {
			$doc['safe_to_upgrade'] = true;
		}

		return $doc;
	}

	/**
	 * @return array[] This check's own not_checked entries in the scanner's shape; the table says why in words.
	 */
	protected function ee_not_checked() {
		return array_map(
			function ( $entry ) {
				return [
					'type'   => 'other',
					'slug'   => '',
					'branch' => null,
					'file'   => $entry[0],
					'reason' => 'unreadable',
				];
			},
			$this->ee_entries
		);
	}

	/**
	 * @return array|null wp_supports() for the live WordPress, or null when the scanner found none.
	 */
	protected function core_support() {
		if ( null === $this->wordpress ) {
			return null;
		}
		if ( null === $this->wordpress['branch'] ) {
			$why = "can't read the version of WordPress in " . self::clean( $this->wordpress['dir'] ) . '/wp-includes/version.php';
			return [
				'supports'     => null,
				'minimum'      => null,
				'table_newest' => null,
				'why'          => $why,
			];
		}

		return self::wp_supports( $this->wordpress['branch'], $this->target, $this->current_php() );
	}

	/**
	 * @param string $target PHP major.minor.
	 *
	 * @return string[] The WordPress branches whose row of WP_PHP_SUPPORT includes the target.
	 */
	public static function supported_branches( $target ) {
		$branches = [];
		foreach ( self::WP_PHP_SUPPORT as $wp => list( $low, $high ) ) {
			if ( self::in_range( $target, $low, $high ) ) {
				$branches[] = (string) $wp;
			}
		}

		return $branches;
	}

	/**
	 * Takes the live WordPress install, the other installs whose core the scanner left out, and the phars it kept as
	 * information, from a validated report.
	 *
	 * @param array $report Validated report.
	 */
	protected function read_installs( array $report ) {
		$this->wordpress      = null;
		$this->other_installs = [];
		$supported            = self::supported_branches( $this->target );
		foreach ( $report['wordpress_installs'] as $install ) {
			$branch = preg_match( '/^(\d+\.\d+)/', $install['version'], $m ) ? $m[1] : null;
			if ( $install['live'] ) {
				$this->wordpress = [
					'dir'     => $install['dir'],
					'version' => $install['version'],
					'branch'  => $branch,
				];
			} elseif ( in_array( $branch, $supported, true ) ) {
				$this->other_installs[] = $install;
			}
		}
		$this->phars = [];
		foreach ( $report['phars'] as $phar ) {
			$this->phars[] = self::cut( self::clean( $phar ) );
		}
		$this->phar_named_by = $report['phar_named_by'];
		$this->old_releases  = $report['excluded_old_releases'];
	}

	/**
	 * @param string $path Container path from the report.
	 *
	 * @return string The same place under the site's app/, cleaned; a path outside /var/www as it is.
	 */
	protected function host_name( $path ) {
		$name = 0 === strpos( $path, '/var/www/' ) ? $this->app_path . substr( $path, strlen( '/var/www/' ) ) : $path;

		return self::clean( $name );
	}

	/**
	 * Leaves the ERRORs of nginx-helper's bundled Predis (admin/predis.php) out of the verdict when the site's php image
	 * and its config load the redis extension: nginx-helper then never loads Predis. Its code isn't read.
	 *
	 * @param array $doc    Scanner summary (errors and components_with_errors are adjusted).
	 * @param array $report Scanner report (the component's findings and counts are adjusted).
	 */
	protected function ignore_unused_predis( array &$doc, array &$report ) {
		$this->predis_counts  = null;
		$this->predis_ignored = [];
		$copies               = [];
		foreach ( $report['components'] as $i => $component ) {
			foreach ( $component['findings'] as $j => $finding ) {
				if ( 'ERROR' === $finding['type'] && self::is_predis( $component, $finding['file'] ) ) {
					$copies[ $i ][ $finding['file'] ][] = $j;
				}
			}
		}
		if ( ! $copies ) {
			return;
		}
		// Every EasyEngine php image builds redis, and the config templates from PHP 7.0 up load it.
		if ( null === $this->php_image ) {
			$this->predis_counts = 'the site\'s php container isn\'t running, so its image can\'t be read';
		} elseif ( ! preg_match( '#^easyengine/php(?:\d+\.\d+)?:#', $this->php_image ) ) {
			$this->predis_counts = 'the site\'s php container doesn\'t run an EasyEngine image';
		} elseif ( version_compare( $this->target, '7.0', '<' ) ) {
			$this->predis_counts = "EasyEngine's PHP {$this->target} config doesn't load the redis extension";
		}
		if ( null !== $this->predis_counts ) {
			return;
		}
		foreach ( $copies as $i => $files ) {
			$component = $report['components'][ $i ];
			foreach ( $files as $file => $errors ) {
				foreach ( $errors as $j ) {
					// Kept in the report, marked: the --output file still shows what was ignored.
					$component['findings'][ $j ]['ignored'] = true;
				}
				$component['errors'] -= count( $errors );
				$doc['errors']       -= count( $errors );
				// Named as the table names a finding: the scanner reports no directory for a component.
				$this->predis_ignored[ self::place( $component ) . ': ' . self::clean( $file ) ] = count( $errors );
			}
			if ( 0 === $component['errors'] ) {
				--$doc['components_with_errors'];
			}
			$report['components'][ $i ] = $component;
		}
	}

	/**
	 * @param array  $component Report component.
	 * @param string $file      A finding's file, relative to the component.
	 *
	 * @return bool Whether it is nginx-helper's bundled Predis: in the plugin, or below a plugins/ directory of another
	 *              component (a branch root's or Bedrock's web/app/plugins scanned as a directory).
	 */
	protected static function is_predis( array $component, $file ) {
		if ( 'plugin' === $component['type'] && 'nginx-helper' === $component['slug'] ) {
			return 'admin/predis.php' === $file;
		}

		return 1 === preg_match( '#(?:^|/)plugins/nginx-helper/admin/predis\.php$#', $file );
	}

	/**
	 * @return string|null The PHP version the site runs now: only when its record and its running container agree.
	 */
	protected function current_php() {
		return null !== $this->current_php && $this->current_php === $this->container_php ? $this->current_php : null;
	}

	/**
	 * @param string $text Text.
	 *
	 * @return string At most NOT_CHECKED_CHARS characters of it, so stdout stays fixed-size.
	 */
	protected static function cut( $text ) {
		return preg_match( '/^.{' . self::NOT_CHECKED_CHARS . '}/us', $text, $m ) ? $m[0] : $text;
	}

	/**
	 * The summary as stdout prints it: fixed-size, so not_checked keeps its first NOT_CHECKED_STDOUT entries, each string
	 * cut to NOT_CHECKED_CHARS characters, and not_checked_more counts the rest (the --output report has them all).
	 *
	 * @param array $summary Full summary.
	 *
	 * @return array
	 */
	public static function capped( array $summary ) {
		if ( ! isset( $summary['not_checked'] ) ) {
			return $summary;
		}
		$all                         = $summary['not_checked'];
		$summary['not_checked']      = array_map(
			function ( $entry ) {
				foreach ( $entry as $key => $value ) {
					if ( is_string( $value ) && preg_match( '/^.{' . self::NOT_CHECKED_CHARS . '}/us', $value, $m ) && $m[0] !== $value ) {
						$entry[ $key ] = $m[0];
					}
				}
				return $entry;
			},
			array_slice( $all, 0, self::NOT_CHECKED_STDOUT )
		);
		$summary['not_checked_more'] = max( 0, count( $all ) - self::NOT_CHECKED_STDOUT );
		// Each ignored entry is already cut; a site with many leftover phars mustn't grow stdout.
		$summary['ignored'] = array_slice( $summary['ignored'], 0, self::NOT_CHECKED_STDOUT );

		return $summary;
	}

	/**
	 * @return bool Whether PHP version $target is from $low to $high.
	 */
	protected static function in_range( $target, $low, $high ) {
		return version_compare( $target, $low, '>=' ) && version_compare( $target, $high, '<=' );
	}

	/**
	 * Whether a WordPress branch supports a PHP version, by WP_PHP_SUPPORT.
	 *
	 * @param string $branch WordPress major.minor.
	 * @param string      $target  PHP major.minor.
	 * @param string|null $current PHP the site runs now, major.minor (only used for a branch newer than the table).
	 *
	 * @return array supports (true, false, or null when WordPress hasn't said), minimum (lowest WordPress branch supporting
	 *               the target, or null), table_newest (the newest row, when the branch is newer than the table) and why
	 *               (the reason to print, or null).
	 */
	public static function wp_supports( $branch, $target, $current = null ) {
		$current = null !== $current && preg_match( '/^\d+\.\d+$/D', (string) $current ) ? (string) $current : null;
		$minimum = null;
		$highest = '0';
		foreach ( self::WP_PHP_SUPPORT as $wp => list( $low, $high ) ) {
			if ( self::in_range( $target, $low, $high ) ) {
				$minimum = (string) $wp;
			}
			$highest = version_compare( $high, $highest, '>' ) ? $high : $highest;
		}
		$result = function ( $supports, $why, $min = null, $newest = null ) {
			return [ 'supports' => $supports, 'minimum' => $min, 'table_newest' => $newest, 'why' => $why ];
		};
		if ( version_compare( $target, $highest, '>' ) ) {
			return $result( null, "WordPress hasn't declared support for PHP $target yet" );
		}
		$branches = array_map( 'strval', array_keys( self::WP_PHP_SUPPORT ) );
		$newest   = $branches[0];
		$oldest   = end( $branches );
		if ( version_compare( $branch, $newest, '>' ) ) {
			// WordPress doesn't drop a newer PHP within its support window, but may raise its floor: a newer branch supports
			// what the newest row does only when moving up from the PHP the site runs now.
			list( $low, $high ) = self::WP_PHP_SUPPORT[ $newest ];
			if ( self::in_range( $target, $low, $high ) ) {
				if ( null !== $current && version_compare( $target, $current, '>=' ) ) {
					return $result( true, "WordPress $branch is newer than this check's table; WordPress $newest supports PHP $target", $minimum, $newest );
				}
				return $result( null, "WordPress $branch is newer than this check's table, which can't tell whether it still supports PHP $target" . ( null === $current ? '' : ", below the PHP $current the site runs now" ), $minimum, $newest );
			}
			// Below the newest row's floor nothing says a newer WordPress still runs it: no minimum to name.
			return $result( null, "WordPress $branch is newer than this check's table, and WordPress $newest doesn't support PHP $target", version_compare( $target, $low, '<' ) ? null : $minimum, $newest );
		}
		if ( ! isset( self::WP_PHP_SUPPORT[ $branch ] ) ) {
			// Older than every row: it can't support a PHP newer than the oldest row does.
			if ( version_compare( $branch, $oldest, '<' ) && version_compare( $target, self::WP_PHP_SUPPORT[ $oldest ][1], '>' ) ) {
				$why = "WordPress $branch doesn't support PHP $target";
				return $result( false, null === $minimum ? $why : "$why; update WordPress to at least $minimum first", $minimum );
			}
			return $result( null, "this check doesn't know which PHP versions WordPress $branch supports", $minimum );
		}
		list( $low, $high ) = self::WP_PHP_SUPPORT[ $branch ];
		if ( version_compare( $target, $low, '<' ) ) {
			return $result( false, "WordPress $branch needs PHP $low or newer, so it doesn't support PHP $target" );
		}
		if ( version_compare( $target, $high, '<=' ) ) {
			return $result( true, null, $minimum );
		}
		$why = "WordPress $branch doesn't support PHP $target";

		return $result( false, null === $minimum ? $why : "$why; update WordPress to at least $minimum first", $minimum );
	}


	/**
	 * Container scan summary to print: findings never, `site` always.
	 *
	 * @param array $doc Validated stdout summary.
	 *
	 * @return array
	 */
	protected function summary( array $doc ) {
		return $this->with_site( self::pick( $doc, self::SUMMARY_KEYS ) );
	}

	/**
	 * Only the documented keys, in their order; tool strings and estimate input numbers only.
	 *
	 * @param array    $doc  Validated document.
	 * @param string[] $keys Keys to keep.
	 *
	 * @return array
	 */
	protected static function pick( array $doc, array $keys ) {
		$picked = [];
		foreach ( $keys as $key ) {
			if ( array_key_exists( $key, $doc ) ) {
				$picked[ $key ] = $doc[ $key ];
			}
		}
		if ( isset( $picked['tool'] ) ) {
			$picked['tool'] = array_filter( array_intersect_key( $picked['tool'], array_flip( self::TOOL_KEYS ) ), 'is_string' );
		}
		if ( isset( $picked['inputs'] ) ) {
			$picked['inputs'] = array_filter(
				array_intersect_key( $picked['inputs'], array_flip( self::INPUT_KEYS ) ),
				function ( $value ) {
					// json_decode() reads 1e999 as INF, which json_encode() refuses.
					return is_int( $value ) || ( is_float( $value ) && is_finite( $value ) );
				}
			);
		}

		return $picked;
	}

	/**
	 * Put `site` after v, status and estimate, whatever the container sent.
	 *
	 * @param array $doc Document.
	 *
	 * @return array
	 */
	protected function with_site( array $doc ) {
		$head = [
			'v'      => $doc['v'],
			'status' => $doc['status'],
		];
		if ( array_key_exists( 'estimate', $doc ) ) {
			$head['estimate'] = $doc['estimate'];
		}
		$head['site'] = $this->site;

		return $head + $doc;
	}

	/**
	 * A document other than `ok`: only v, status, site, target, safe_to_upgrade and, for tool_error, detail.
	 *
	 * @param string      $status busy, no_code_found, cancelled or tool_error; with --cancel: cancelled, not_running or tool_error.
	 * @param string|null $detail tool_error only.
	 *
	 * @return array 'doc' and 'report'.
	 */
	protected function doc( $status, $detail = null ) {
		$doc = [
			'v'      => 1,
			'status' => $status,
		];
		if ( $this->cancel ) {
			// No target: a cancel stops whichever check runs.
			$doc += [
				'cancel' => true,
				'site'   => $this->site,
			];
			if ( 'tool_error' === $status ) {
				$doc['detail'] = (string) $detail;
			}

			return [
				'doc'    => $doc,
				'report' => null,
			];
		}
		if ( $this->estimate ) {
			$doc['estimate'] = true;
		}
		$doc += [
			'site'            => $this->site,
			'target'          => $this->target,
			'safe_to_upgrade' => null,
		];
		if ( 'tool_error' === $status ) {
			$doc['detail'] = (string) $detail;
		}

		return [
			'doc'    => $doc,
			'report' => null,
		];
	}

	/**
	 * Detail of a tool_error EE reports for the container: its exit code and, for Docker's own errors, Docker's message.
	 *
	 * @param array  $run  docker() result.
	 * @param string $what What was missing.
	 *
	 * @return string
	 */
	protected static function exit_detail( array $run, $what ) {
		if ( ! empty( $run['overflow'] ) ) {
			return 'the scanner printed more output than a result can have';
		}
		if ( ! empty( $run['signal'] ) ) {
			return "the scanner was interrupted by signal {$run['signal']}";
		}
		if ( null === $run['code'] ) {
			return 'the scanner could not be started';
		}
		$detail = "the scanner exited with code {$run['code']} $what";
		// 125-127 come from Docker itself, not from the scanned code.
		if ( in_array( $run['code'], [ 125, 126, 127 ], true ) && '' !== trim( $run['stderr'] ) ) {
			$line    = strtok( trim( $run['stderr'] ), "\n" );
			$detail .= ': ' . self::clean( strlen( $line ) > 300 ? substr( $line, 0, 300 ) . '...' : $line );
		}

		return $detail;
	}

	/**
	 * The table, for tests and callers that want one string.
	 *
	 * @param array      $doc    Summary.
	 * @param array|null $report Decoded report (ok only).
	 *
	 * @return string
	 */
	protected function table( array $doc, $report ) {
		return implode( "\n", iterator_to_array( $this->table_lines( $doc, $report ), false ) ) . "\n";
	}

	/**
	 * @param array      $doc    Summary.
	 * @param array|null $report Decoded report (ok only).
	 *
	 * @return \Generator Lines without newlines.
	 */
	protected function table_lines( array $doc, $report ) {
		$site   = $this->site;
		$target = $this->target;
		switch ( $doc['status'] ) {
			case 'busy':
				yield "A check or an estimate of $site is already running.";
				return;
			case 'no_code_found':
				yield $this->no_code_text();
				return;
			case 'cancelled':
				yield "The check of $site for PHP $target was cancelled.";
				return;
			case 'tool_error':
				yield "The check of $site for PHP $target didn't finish: " . self::clean( $doc['detail'] );
				return;
		}

		foreach ( $this->verdict_lines( $doc, $report ) as $line ) {
			yield $line;
		}
		foreach ( $this->note_lines() as $line ) {
			yield $line;
		}
		yield '';

		$warnings_only = [];
		foreach ( $report['components'] as $component ) {
			if ( $component['errors'] > 0 ) {
				yield sprintf( '%s: %s, %s', self::component_heading( $component ), self::count_of( $component['errors'], 'error' ), self::count_of( $component['warnings'], 'warning' ) );
				foreach ( $component['findings'] as $finding ) {
					if ( 'ERROR' !== $finding['type'] || ! empty( $finding['ignored'] ) ) {
						continue;
					}
					yield '  ' . self::clean( $finding['file'] ) . ':' . $finding['line'];
					yield '    ' . self::clean( $finding['message'] );
					yield '    ' . self::clean( $finding['source'] );
				}
				yield '';
			} elseif ( $component['warnings'] > 0 ) {
				$warnings_only[] = self::component_name( $component ) . ' (' . $component['warnings'] . ')';
			}
		}
		if ( $warnings_only ) {
			yield 'Warnings only: ' . implode( ', ', $warnings_only );
			yield '';
		}

		if ( $report['not_checked'] || $this->ee_entries ) {
			yield 'Not checked:';
			foreach ( $report['not_checked'] as $entry ) {
				yield '  ' . self::place( $entry ) . ': ' . self::clean( $entry['file'] ) . ' (' . self::clean( $entry['reason'] ) . ')';
			}
			foreach ( $this->ee_entries as list( $file, $why ) ) {
				yield '  ' . self::clean( $file ) . ' (' . self::clean( $why ) . ')';
			}
			if ( null !== $this->wordpress ) {
				yield '  WordPress core files (only its version is checked)';
			}
			yield '';
		} elseif ( null !== $this->wordpress ) {
			yield 'Not checked: WordPress core files (only its version is checked).';
			yield '';
		}

		if ( isset( $doc['report'] ) ) {
			yield 'Full report: ' . $doc['report'];
			yield '';
		}

		$tool = isset( $doc['tool']['phpcompatibility'] ) && is_string( $doc['tool']['phpcompatibility'] ) ? ' ' . self::clean( $doc['tool']['phpcompatibility'] ) : '';
		// Core's files are never scanned; its version is compared with the PHP versions WordPress says it supports.
		$scope = null !== $this->wordpress ? ' WordPress core is checked only by its version, against the PHP versions WordPress lists as supported.' : '';
		yield "This is static analysis with PHPCompatibility$tool.$scope Its coverage isn't complete and it can't see breakage that only shows at runtime.";
	}

	/**
	 * The verdict line, and WordPress's sentence when its core decides or adds to it.
	 *
	 * @param array $doc    Summary.
	 * @param array $report Decoded report.
	 *
	 * @return string[]
	 */
	protected function verdict_lines( array $doc, array $report ) {
		$site   = $this->site;
		$target = $this->target;
		$tail   = sprintf( '%s. %s checked.', self::count_of( $doc['warnings'], 'warning' ), self::count_of( $doc['files_checked'], 'file' ) );
		$core   = $this->core_support();
		$why    = null === $core || null === $core['why'] ? [] : [ ucfirst( $core['why'] ) . '.' ];
		if ( false === $doc['safe_to_upgrade'] && 0 === $doc['errors'] ) {
			return [ sprintf( 'Not safe to upgrade %s to PHP %s: %s. %s', $site, $target, $core['why'], $tail ) ];
		}
		if ( false === $doc['safe_to_upgrade'] ) {
			$errors = sprintf( '%s in %s', self::count_of( $doc['errors'], 'error' ), self::count_of( $doc['components_with_errors'], 'component' ) );
			return array_merge( [ "Not safe to upgrade $site to PHP $target: $errors. $tail" ], $why );
		}
		if ( true === $doc['safe_to_upgrade'] ) {
			// Only what was checked: never "safe" for the whole site.
			$found = 0 === $doc['warnings'] ? 'problems' : 'errors';
			$what  = null !== $this->wordpress ? 'all PHP except WordPress core' : 'all PHP';
			$app   = self::clean( rtrim( $this->app_path, '/' ) );
			$files = self::count_of( $doc['files_checked'], 'file' );
			$line  = "No PHP $target $found found in the $files checked in $site ($what in $app).";
			if ( null !== $core ) {
				$branch = $this->wordpress['branch'];
				$line  .= ' ' . ( $why ? $why[0] : "WordPress $branch supports PHP $target, by WordPress's own list." );
			}
			if ( $this->old_releases ) {
				$old   = implode( ', ', array_map( [ $this, 'host_name' ], $this->old_releases ) );
				$line .= " Old releases nginx doesn't serve aren't checked: $old.";
			}
			return [ $line . ( $doc['warnings'] > 0 ? ' ' . self::count_of( $doc['warnings'], 'warning' ) . '.' : '' ) ];
		}
		if ( 0 === $doc['files_not_checked'] ) {
			return [ sprintf( 'Can\'t confirm %s is safe for PHP %s: %s. %s', $site, $target, $core['why'], $tail ) ];
		}
		// An entry can be a whole directory (a nested install, a mount): count entries.
		$dirs  = (bool) preg_grep( '#/$#', array_column( $report['not_checked'], 'file' ) ) || (bool) $this->ee_entries;
		$noun  = $dirs ? 'files or directories' : 'files';
		if ( 1 === $doc['files_not_checked'] ) {
			$noun = $dirs ? 'file or directory' : 'file';
		}
		$line = "Can't confirm $site is safe for PHP $target: {$doc['files_not_checked']} $noun couldn't be checked. $tail";

		return array_merge( [ $line ], $why );
	}

	/**
	 * What the scan left out or ignored, and why; none of it changes the verdict line above.
	 *
	 * @return string[]
	 */
	protected function note_lines() {
		$lines = [];
		if ( $this->other_installs ) {
			$installs = array_map(
				function ( $install ) {
					return self::clean( $install['dir'] . ' (' . $install['version'] . ')' );
				},
				$this->other_installs
			);
			$lines[]  = 'Other WordPress installs judged by version: ' . implode( ', ', $installs ) . '.';
		}
		if ( $this->old_releases_served ) {
			$lines[] = 'Old releases nginx serves were scanned; recreate the site with --public-dir=current to leave them out.';
		}
		if ( $this->predis_ignored ) {
			$findings = self::count_of( array_sum( $this->predis_ignored ), 'finding' );
			$lines[]  = "Ignored $findings in nginx-helper's bundled Predis (admin/predis.php): "
				. "the redis extension is loaded, so nginx-helper doesn't load it.";
		}
		if ( null !== $this->predis_counts ) {
			$lines[] = "nginx-helper's bundled Predis error counts: " . $this->predis_counts . '.';
		}
		if ( $this->phars ) {
			$count   = self::count_of( count( $this->phars ), 'phar archive' );
			$lines[] = "$count not checked; no scanned code names a phar: " . implode( ', ', $this->phars ) . '.';
		}
		if ( null !== $this->phar_named_by ) {
			$lines[] = 'Phar archives count as not checked: ' . self::clean( $this->phar_named_by ) . ' names a phar.';
		}

		return $lines;
	}

	/**
	 * @param array $doc --cancel document.
	 *
	 * @return string
	 */
	protected function cancel_text( array $doc ) {
		switch ( $doc['status'] ) {
			case 'cancelled':
				return "Cancelled the check of {$this->site}.\n";
			case 'not_running':
				return "No check of {$this->site} is running.\n";
		}

		return "Couldn't cancel the check of {$this->site}: " . self::clean( $doc['detail'] ) . "\n";
	}

	/**
	 * Where the check looked, so a wrong root doesn't read as an empty site.
	 *
	 * @return string
	 */
	protected function no_code_text() {
		$app = self::clean( rtrim( $this->app_path, '/' ) );

		return "No PHP code found to check in {$this->site}. Looked for PHP in $app, except WordPress core and old releases nginx doesn't serve.";
	}

	/**
	 * @param array $doc Estimate document.
	 *
	 * @return string
	 */
	protected function estimate_text( array $doc ) {
		$site   = $this->site;
		$target = $this->target;
		switch ( $doc['status'] ) {
			case 'busy':
				return "A check or an estimate of $site is already running.\n";
			case 'cancelled':
				return "The estimate for $site was cancelled.\n";
			case 'no_code_found':
				return $this->no_code_text() . "\n";
			case 'tool_error':
				return "Couldn't estimate a check of $site for PHP $target: " . self::clean( $doc['detail'] ) . "\n";
		}

		return sprintf(
			"A check of %s for PHP %s should take %s: %s, %s, %s. This is an estimate.\n",
			$site,
			$target,
			self::duration( $doc['estimated_seconds'], $doc['estimated_seconds_low'], $doc['estimated_seconds_high'] ),
			self::count_of( $doc['php_files'], 'PHP file', true ),
			self::size( $doc['php_bytes'] ),
			self::count_of( $doc['components'], 'component', true )
		);
	}

	/**
	 * "about 3 minutes (2 to 4)", in one unit for all three.
	 *
	 * @return string
	 */
	public static function duration( $seconds, $low, $high ) {
		if ( $high < 120 ) {
			list( $unit, $name, $precision ) = [ 1, 'second', 0 ];
		} elseif ( $seconds < 5400 ) {
			list( $unit, $name, $precision ) = [ 60, 'minute', 0 ];
		} else {
			list( $unit, $name, $precision ) = [ 3600, 'hour', 1 ];
		}
		$value = function ( $s ) use ( $unit, $precision ) {
			return max( $precision ? 0.1 : 1, round( $s / $unit, $precision ) );
		};
		$main = $value( $seconds );

		return sprintf( 'about %s %s%s (%s to %s)', $main, $name, 1 == $main ? '' : 's', $value( $low ), $value( $high ) );
	}

	/**
	 * @return string "87 MB", "6.9 MB", "512 KB".
	 */
	public static function size( $bytes ) {
		$mb = $bytes / 1048576;
		if ( $mb >= 10 ) {
			return round( $mb ) . ' MB';
		}
		if ( $mb >= 1 ) {
			return round( $mb, 1 ) . ' MB';
		}

		return max( 1, (int) round( $bytes / 1024 ) ) . ' KB';
	}

	/**
	 * @return string "1 error", "3 errors", "14,614 PHP files".
	 */
	protected static function count_of( $n, $noun, $separators = false ) {
		return ( $separators ? number_format( $n ) : $n ) . " $noun" . ( 1 === $n ? '' : 's' );
	}

	/**
	 * @return string "WPForms Lite 1.9.2 (plugin wpforms-lite)", "mu-plugins (mu-plugin)".
	 */
	protected static function component_heading( array $component ) {
		$detail = $component['type'];
		if ( null !== $component['name'] && '' !== $component['slug'] ) {
			$detail .= ' ' . $component['slug'];
		}
		if ( null !== $component['branch'] ) {
			$detail .= ', branch ' . $component['branch'];
		}

		return self::component_name( $component ) . ' (' . self::clean( $detail ) . ')';
	}

	/**
	 * @return string Name and version, or the slug, or "other files".
	 */
	protected static function component_name( array $component ) {
		if ( null !== $component['name'] && '' !== $component['name'] ) {
			$name = $component['name'];
		} else {
			$name = '' !== $component['slug'] ? $component['slug'] : 'other files';
		}
		if ( null !== $component['version'] && '' !== $component['version'] ) {
			$name .= ' ' . $component['version'];
		}

		return self::clean( $name );
	}

	/**
	 * @return string "plugin huge-plugin", "other, branch main".
	 */
	protected static function place( array $entry ) {
		$place = $entry['type'] . ( '' !== $entry['slug'] ? ' ' . $entry['slug'] : '' ) . ( null !== $entry['branch'] ? ', branch ' . $entry['branch'] : '' );

		return self::clean( $place );
	}

	/**
	 * Text from the scanned code must not drive the terminal: control, bidi and zero-width characters become '?'.
	 *
	 * @param string $text Text.
	 *
	 * @return string
	 */
	public static function clean( $text ) {
		$text = (string) $text;
		// Invalid UTF-8 would make the /u pattern fail, and a raw 0x9B byte is a C1 CSI.
		if ( ! preg_match( '//u', $text ) ) {
			$text = preg_replace( '/[\x80-\xFF]/', '?', $text );
		}

		return preg_replace( '/[\x00-\x1F\x7F' . self::UNSAFE_CHARS . ']/u', '?', $text );
	}

	/**
	 * One line of JSON, with characters a terminal would act on escaped (the data stays the same).
	 *
	 * @return string
	 */
	public static function encode( array $doc ) {
		$json = json_encode( $doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | self::JSON_UTF8 );
		if ( false === $json ) {
			return '';
		}

		return preg_replace_callback(
			'/[' . self::UNSAFE_CHARS . ']/u',
			function ( $char ) {
				return substr( json_encode( $char[0] ), 1, -1 );
			},
			$json
		);
	}

	/**
	 * Command, target, duration, verdict and counts only; findings and details never reach ee.log.
	 *
	 * @param array $doc     Printed document.
	 * @param float $elapsed Seconds.
	 */
	protected function log_result( array $doc, $elapsed ) {
		if ( $this->cancel ) {
			EE::debug( sprintf( 'php-compat cancel of %s: status %s, %d s.', $this->site, $doc['status'], (int) round( $elapsed ) ) );

			return;
		}
		$counts = '';
		$keys   = $this->estimate ? [ 'php_files', 'php_bytes', 'components', 'estimated_seconds' ] : [ 'errors', 'warnings', 'files_checked', 'files_not_checked', 'components_with_errors' ];
		foreach ( $keys as $key ) {
			if ( isset( $doc[ $key ] ) && is_int( $doc[ $key ] ) ) {
				$counts .= ", $key {$doc[ $key ]}";
			}
		}
		EE::debug(
			sprintf(
				'php-compat %s of %s for PHP %s: status %s, safe_to_upgrade %s%s, %d s.',
				$this->estimate ? 'estimate' : 'scan',
				$this->site,
				$this->target,
				$doc['status'],
				var_export( isset( $doc['safe_to_upgrade'] ) ? $doc['safe_to_upgrade'] : null, true ),
				$counts,
				(int) round( $elapsed )
			)
		);
	}

	/**
	 * @param string|false $value php.ini size such as 128M.
	 *
	 * @return int Bytes; -1 for no limit.
	 */
	protected static function bytes( $value ) {
		$value = trim( (string) $value );
		if ( '-1' === $value || '' === $value ) {
			return -1;
		}
		$bytes = (int) $value;
		switch ( strtolower( substr( $value, -1 ) ) ) {
			case 'g':
				$bytes *= 1024;
				// Fall through.
			case 'm':
				$bytes *= 1024;
				// Fall through.
			case 'k':
				$bytes *= 1024;
		}

		return $bytes;
	}

	/**
	 * Raise PHP's memory limit to at least MEMORY_FLOOR before reading a report.
	 */
	protected static function raise_memory_limit() {
		$limit = self::bytes( ini_get( 'memory_limit' ) );
		if ( $limit > 0 && $limit < self::MEMORY_FLOOR ) {
			ini_set( 'memory_limit', (string) self::MEMORY_FLOOR );
		}
	}

	/**
	 * With --format=json stdout holds the document or nothing: a signal ends the run in rollback(), whose "Success:" line goes to stderr instead.
	 */
	protected static function keep_stdout_for_json() {
		$runner = EE::get_runner();
		if ( ! empty( $runner->config['quiet'] ) ) {
			return;
		}
		EE::set_logger(
			new class( $runner->in_color() ) extends \EE\Loggers\Regular {
				public function info( $message ) {
					$this->write( STDERR, $message . "\n" );
				}

				public function success( $message ) {
					$this->_line( $message, 'Success', '%G', STDERR );
				}
			}
		);
	}

	/**
	 * @return int|null Effective uid, null without the posix extension.
	 */
	protected static function euid() {
		return function_exists( 'posix_geteuid' ) ? posix_geteuid() : null;
	}

	protected function register_cleanup() {
		if ( ! $this->cleanup_registered ) {
			$this->cleanup_registered = true;
			// EE::error() exits without running finally blocks. EE_Site_Command::shut_down_function() also calls cleanup(), before its rollback() exits.
			register_shutdown_function( [ $this, 'cleanup' ] );
		}
	}

	/**
	 * For `ee site delete`: stop a running check or estimate of the site and keep the site lock until the delete ends.
	 *
	 * @param object        $site_data Site record.
	 * @param callable|null $dispatch  Signal dispatcher, as for run().
	 *
	 * @return string|null Why the delete can't go on now (a restore runs), or null.
	 */
	public function stop_for_delete( $site_data, $dispatch = null ) {
		$this->site     = $site_data->site_url;
		$this->dispatch = $dispatch;
		$site_dir       = $site_data->site_fs_path;
		if ( $this->restore_running( $site_dir ) ) {
			return 'a restore of the site is running';
		}
		$parent = rtrim( $this->temp_base(), '/' ) . '/' . self::TEMP_DIR;
		// Never checked on this host: no check runs without the temporary directory, so docker isn't asked.
		$checked = file_exists( $parent ) || is_link( $parent );
		if ( $checked ) {
			$usable = $this->temp_parent();
			$marker = null === $usable ? null : $this->cancel_path( $usable );
			if ( null !== $marker ) {
				@touch( $marker ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- the container is removed anyway.
			}
			$stopped = $this->stop_containers();
			// The scanner reads the code read-only, so a delete under it harms nothing.
			if ( is_string( $stopped ) ) {
				EE::warning( "Could not stop the php-compat check of {$this->site} ($stopped); the delete goes on." );
			} elseif ( $stopped ) {
				EE::warning( "Stopped the running php-compat check of {$this->site}; the delete goes on." );
			}
		}
		if ( ! $this->lock_site( $site_dir ) && ! $this->wait_for_site_lock( $site_dir, $this->cancel_wait() ) ) {
			if ( $this->restore_running( $site_dir ) ) {
				return 'a restore of the site is running';
			}
			EE::warning( "Another php-compat process of {$this->site} still holds the site's lock after " . $this->cancel_wait() . ' s; the delete goes on.' );
		}
		// Kept until this process ends, so no new check of the site starts while it is deleted.
		$this->delete_lock = $this->lock;
		$this->lock        = null;
		if ( $checked && null !== $this->temp_parent() ) {
			$files = [ "$parent/.cancel/{$this->site}", "$parent/.cancel-lock/{$this->site}", "$parent/.estimate-lock/{$this->site}" ];
			// Names site-command v3.11.0 used.
			array_push( $files, "$parent/.cancel-{$this->site}", "$parent/.cancel-{$this->site}.lock", "$parent/.estimate-{$this->site}.lock" );
			foreach ( $files as $file ) {
				@unlink( $file ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- usually missing.
			}
			$this->remove_site_temp( $parent );
		}

		return null;
	}

	/**
	 * Remove a scan container that may still exist, then, once it is gone, the temporary directory; release the lock. Safe to call twice.
	 */
	public function cleanup() {
		$this->cleaning = true;
		if ( null !== $this->active_container ) {
			$name                   = $this->active_container;
			$this->active_container = null;
			// /out is the container's until it is gone: never remove it under a running one.
			if ( ! $this->container_gone( $name ) && null !== $this->temp_dir ) {
				EE::warning( "Left {$this->temp_dir} in place: the container $name could not be removed." );
				$this->temp_dir = null;
			}
		}
		if ( null !== $this->estimate_label ) {
			$label                = 'label=ee-php-compat-estimate=' . $this->estimate_label;
			$this->estimate_label = null;
			$ids                  = $this->docker( [ 'docker', 'ps', '-aq', '--filter', $label ], 60 );
			foreach ( preg_split( '/\s+/', trim( $ids['stdout'] ), -1, PREG_SPLIT_NO_EMPTY ) as $id ) {
				$this->docker( [ 'docker', 'rm', '-f', $id ], 60 );
			}
		}
		if ( null !== $this->temp_dir ) {
			$dir            = $this->temp_dir;
			$this->temp_dir = null;
			if ( $this->remove_tree( $dir ) ) {
				// The per-site directory, once empty.
				@rmdir( dirname( $dir ) ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- a leftover in it keeps it.
			} else {
				EE::warning( "Could not remove $dir." );
			}
		}
		if ( null !== $this->output_tmp ) {
			@unlink( $this->output_tmp ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			$this->output_tmp = null;
		}
		// Still holding the lock: a --cancel that came during cleanup gets the same answer as this scan prints. An ok result
		// stands; its request is then left for --cancel to read as not_running.
		if ( null !== $this->result_status && ! in_array( $this->result_status, [ 'ok', 'cancelled' ], true ) && $this->cancel_requested() ) {
			$this->take_request();
			$this->late_cancel = true;
		}
		if ( null !== $this->lock ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock );
			$this->lock = null;
		}
		if ( null !== $this->image_lock ) {
			fclose( $this->image_lock );
			$this->image_lock = null;
		}
		// Without the lock, a request that comes now is for another scan: a second cleanup() leaves it.
		$this->cancel_marker = null;
		$this->cleaning      = false;
	}
}
