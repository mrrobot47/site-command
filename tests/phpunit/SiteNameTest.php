<?php

use EE\Site\Tests\SiteTestCase;
use function EE\Site\Utils\auto_site_name;
use function EE\Site\Utils\get_site_info;
use function EE\Site\Utils\get_site_name;

/**
 * Site name resolution (src/helper/site-utils.php) and the `ee site` router (src/Site_Command.php).
 */
class SiteNameTest extends SiteTestCase {

	public function test_get_site_info_returns_the_row_as_array_or_model() {
		$this->create_site( 'info.test', [ 'site_type' => 'wp', 'php_version' => '8.2' ] );

		$row = get_site_info( [ 'info.test/' ] );
		$this->assertSame( 'info.test', $row['site_url'] );
		$this->assertSame( 'wp', $row['site_type'] );
		$this->assertSame( '8.2', $row['php_version'] );

		$model = get_site_info( [ 'info.test' ], true, true, false );
		$this->assertInstanceOf( EE\Model\Site::class, $model );
		$this->assertSame( WEBROOT . 'info.test', $model->site_fs_path );
	}

	public function test_get_site_info_errors_or_returns_false_for_a_missing_site() {
		$this->assertFalse( get_site_info( [ 'missing.test' ], true, false ) );
		$this->assert_ee_error(
			function () {
				get_site_info( [ 'missing.test' ] );
			},
			'Site missing.test does not exist.'
		);
	}

	public function test_get_site_info_enforces_the_enabled_check_only_when_asked() {
		$this->create_site( 'off.test', [ 'site_enabled' => 0 ] );

		$this->assertSame( 'off.test', get_site_info( [ 'off.test' ], false )['site_url'] );
		$this->assert_ee_error(
			function () {
				get_site_info( [ 'off.test' ] );
			},
			'Site off.test is not enabled. Use `ee site enable off.test` to enable it.'
		);
	}

	public function test_auto_site_name_keeps_an_existing_site_argument() {
		$this->create_site( 'auto.test' );
		$this->assertSame( [ 'auto.test', 'x' ], auto_site_name( [ 'auto.test', 'x' ], 'site', 'info' ) );
		$this->assertSame( [], $this->runner->commands(), 'No cwd lookup is needed for a known site.' );
	}

	public function test_auto_site_name_inserts_the_site_of_the_current_directory() {
		$this->create_site( 'cwd.test' );
		$this->runner->on( '/^pwd$/', WEBROOT . "cwd.test/app/htdocs\n" );

		$this->assertSame( [ 'cwd.test' ], auto_site_name( [], 'site', 'info' ) );
		$this->assertSame( [ 'x', 'cwd.test' ], auto_site_name( [ 'x' ], 'shell', '', 1 ) );
	}

	public function test_auto_site_name_rejects_an_unknown_name_inside_a_site_directory() {
		$this->create_site( 'cwd.test' );
		$this->runner->on( '/^pwd$/', WEBROOT . 'cwd.test' );

		$this->assert_ee_error(
			function () {
				auto_site_name( [ 'typo.test' ], 'site', 'info' );
			},
			'typo.test is not a valid site-name. Did you mean `ee site info cwd.test`?'
		);
	}

	public function test_auto_site_name_errors_outside_any_site_directory() {
		$this->create_site( 'cwd.test' );
		$this->runner->on( '/^pwd$/', '/root' );

		$this->assert_ee_error(
			function () {
				auto_site_name( [], 'site', 'info' );
			},
			'Could not find the site you wish to run site info command on.'
		);
	}

	/**
	 * get_site_name() needs the cwd to be inside the site root, not just to contain a path segment named like a site.
	 */
	public function test_get_site_name_matches_only_inside_the_site_root() {
		$this->create_site( 'a.test' );
		$this->create_site( 'b.test' );

		$this->runner->on( '/^pwd$/', WEBROOT . 'b.test/app', 0, '', 1 );
		$this->assertSame( 'b.test', get_site_name() );

		$this->runner->on( '/^pwd$/', '/srv/a.test/app', 0, '', 1 );
		$this->assertFalse( get_site_name() );

		$this->runner->on( '/^pwd$/', WEBROOT . 'a.test/b.test', 0, '', 1 );
		$this->assertFalse( get_site_name(), 'Two site names in the path are ambiguous.' );
	}

	public function test_get_site_name_skips_the_lookup_without_sites() {
		$this->assertFalse( get_site_name() );
		$this->assertSame( [], $this->runner->commands() );
	}

	/**
	 * LOGCMD-8/MAILHOG-2/SHELL-3/ATC-2: auto_site_name() strips http(s):// only for its own lookup, so get_site_info() looks up the URL.
	 *
	 * @group known-bug
	 * @group LOGCMD-8
	 * @group MAILHOG-2
	 * @group SHELL-3
	 * @group ATC-2
	 * @dataProvider url_arguments
	 */
	public function test_auto_site_name_normalises_a_url_argument( $argument ) {
		$this->create_site( 'url.test' );

		try {
			$row = get_site_info( auto_site_name( [ $argument ], 'site', 'info' ) );
		} catch ( EE\ExitException $e ) {
			$this->fail( "`$argument` did not resolve to url.test: " . trim( $this->stderr() ) );
		}
		$this->assertSame( 'url.test', $row['site_url'] );
	}

	public function url_arguments() {
		return [
			'http'           => [ 'http://url.test' ],
			'https'          => [ 'https://url.test' ],
			'trailing slash' => [ 'https://url.test/' ],
		];
	}

	public function test_name_checks_add_the_test_tld_to_a_bare_name() {
		$this->assertSame( [ 'create', 'blog.test' ], $this->name_checks( [ 'create', 'blog' ] ) );
		$this->assertSame( [ 'create' ], $this->name_checks( [ 'create' ] ) );
	}

	/**
	 * Legit names pass the create-time checks unchanged (the site types lowercase them later).
	 *
	 * @dataProvider legit_site_names
	 */
	public function test_name_checks_accept_legit_site_names( $name ) {
		$this->assertSame( [ 'create', $name ], $this->name_checks( [ 'create', $name ] ) );
		$this->assertSame( '', $this->stderr() );
	}

	public function legit_site_names() {
		return [
			'test tld'         => [ 'example.test' ],
			'public domain'    => [ 'example.com' ],
			'subdomain'        => [ 'a.b.example.com' ],
			'hyphen and digit' => [ 'my-site2.example.org' ],
			'uppercase'        => [ 'Sec1-Upper.TEST' ],
			'punycode'         => [ 'xn--bcher-kva.test' ],
			'63-char label'    => [ str_repeat( 'a', 63 ) . '.test' ],
			'numeric label'    => [ '123.example.com' ],
		];
	}

	/**
	 * SEC-1: `ee site create` accepts any site name, so shell metacharacters reach the unescaped sinks.
	 *
	 * @group known-bug
	 * @group SEC-1
	 * @dataProvider hostile_site_names
	 */
	public function test_name_checks_reject_invalid_site_names( $name ) {
		$this->assert_ee_error(
			function () use ( $name ) {
				$args = $this->name_checks( [ 'create', $name ] );
				$this->fail( 'Accepted ' . json_encode( $name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ' as ' . json_encode( $args[1], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			},
			'Invalid site name'
		);
	}

	public function hostile_site_names() {
		return [
			'command substitution' => [ 'a$(id).test' ],
			'backticks'            => [ 'a`id`.test' ],
			'semicolon'            => [ 'a;id.test' ],
			'newline'              => [ "a\nid.test" ],
			'single quote'         => [ "a'b.test" ],
			'double quote'         => [ 'a"b.test' ],
			'pipe'                 => [ 'a|id.test' ],
			'and-and'              => [ 'a&&id.test' ],
			'IFS'                  => [ 'a${IFS}b.test' ],
			'uppercase payload'    => [ 'A$(ID).TEST' ],
			'space'                => [ 'a b.test' ],
			'64-char label'        => [ str_repeat( 'a', 64 ) . '.test' ],
			'over 253 chars'       => [ implode( '.', array_fill( 0, 5, str_repeat( 'a', 60 ) ) ) . '.test' ],
			'underscore'           => [ 'a_b.test' ],
			'dot-dot-slash'        => [ '../etc.test' ],
			'raw IDN'              => [ 'bücher.test' ],
			'leading hyphen'       => [ '-a.test' ],
			'trailing hyphen'      => [ 'a-.test' ],
			'empty label'          => [ 'a..test' ],
			'trailing dot'         => [ 'a.test.' ],
			'bare payload'         => [ 'a$(id)' ],
		];
	}

	public function test_old_ee3_flags_map_to_the_new_ones() {
		$this->assertSame(
			[ 'type' => 'wp', 'mu' => 'subdom' ],
			$this->convert_old_args( [ 'create', 'a.test' ], [ 'wpsubdom' => true ] )
		);
		$this->assertSame(
			[ 'type' => 'wp', 'admin-user' => 'u', 'admin-pass' => 'p', 'admin-email' => 'e@x.test' ],
			$this->convert_old_args( [ 'create', 'a.test' ], [ 'wp' => true, 'user' => 'u', 'pass' => 'p', 'email' => 'e@x.test' ] )
		);
		$this->assertSame( [ 'type' => 'php', 'with-db' => true, 'ssl' => 'le' ], $this->convert_old_args( [ 'create', 'a.test' ], [ 'mysql' => true, 'letsencrypt' => true ] ) );
	}

	public function test_old_flags_are_left_alone_outside_create_or_with_an_explicit_type() {
		$this->assertSame( [ 'wp' => true ], $this->convert_old_args( [ 'update', 'a.test' ], [ 'wp' => true ] ) );
		$this->assertSame( [ 'type' => 'php', 'wp' => true ], $this->convert_old_args( [ 'create', 'a.test' ], [ 'type' => 'php', 'wp' => true ] ) );
	}

	/**
	 * SC-8: the "no longer supported" error tests `$args[1] === 'create'`, but `$args[1]` is the site name.
	 *
	 * @group known-bug
	 * @group SC-8
	 */
	public function test_removed_ee3_flags_are_rejected_on_create() {
		$this->assert_ee_error(
			function () {
				$this->fail( 'Returned ' . json_encode( $this->convert_old_args( [ 'create', 'a.test' ], [ 'w3tc' => true ] ) ) );
			},
			'--w3tc flag/s is/are no longer supported in EE v4'
		);
	}

	public function test_determine_type_uses_the_stored_site_type() {
		$this->create_site( 'typed.test', [ 'site_type' => 'php' ] );
		$command = new Site_Command();

		$this->assertSame( 'php', self::call_private( $command, 'determine_type', 'html', [ 'info', 'typed.test' ] ) );

		$this->runner->on( '/^pwd$/', '/root' );
		$this->assertSame( 'html', self::call_private( $command, 'determine_type', 'html', [ 'info', 'other.test' ] ) );
	}

	public function test_determine_type_falls_back_to_the_site_of_the_current_directory() {
		$this->create_site( 'here.test', [ 'site_type' => 'wp' ] );
		$this->runner->on( '/^pwd$/', WEBROOT . 'here.test' );
		$command = new Site_Command();

		$this->assertSame( 'wp', self::call_private( $command, 'determine_type', 'html', [ 'info' ] ) );
		$this->assert_ee_error(
			function () use ( $command ) {
				self::call_private( $command, 'determine_type', 'html', [ 'info', 'there.test' ] );
			},
			'there.test is not a valid site-name. Did you mean `ee site info here.test`?'
		);
	}

	public function test_html_is_the_registered_default_site_type() {
		$this->assertSame( 'EE\Site\Type\HTML', Site_Command::get_site_types()['html'] );
	}

	private function name_checks( array $args ) {
		return self::call_private( new Site_Command(), 'name_checks_and_updates', $args );
	}

	private function convert_old_args( array $args, array $assoc_args ) {
		return self::call_private( new Site_Command(), 'convert_old_args_to_new_args', $args, $assoc_args );
	}
}
