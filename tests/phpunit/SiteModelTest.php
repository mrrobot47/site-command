<?php

use EE\Model\Option;
use EE\Model\Site;
use EE\Model\Site_Meta;
use EE\Site\Tests\SiteTestCase;
use EE\Tests\TestDB;
use function EE\Site\Utils\get_available_subnet;
use function EE\Site\Utils\get_public_dir;
use function EE\Site\Utils\get_subnet_range;
use function EE\Site\Utils\get_webroot;
use function EE\Site\Utils\ip_in_subnet;
use function EE\Site\Utils\subnet_mask_int2long;
use function EE\Site\Utils\update_site_db_entry;

/**
 * The site models on the fixture DB, site paths and subnet allocation (src/db/, src/helper/site-utils.php).
 */
class SiteModelTest extends SiteTestCase {

	public function test_site_save_updates_the_row_and_modified_on() {
		$this->insert_site( [ 'site_url' => 'm.test', 'modified_on' => '2020-01-01 00:00:00' ] );
		$site              = Site::find( 'm.test' );
		$site->site_ssl    = 'le';
		$site->admin_tools = true;
		$site->save();

		$row = TestDB::rows( 'sites' )[0];
		$this->assertSame( 'le', $row['site_ssl'] );
		$this->assertSame( '1', (string) $row['admin_tools'], 'Booleans are stored as integers.' );
		$this->assertNotSame( '2020-01-01 00:00:00', $row['modified_on'] );
		$this->assertCount( 1, TestDB::rows( 'sites' ) );
	}

	public function test_site_enabled() {
		$this->insert_site( [ 'site_url' => 'on.test' ] );
		$this->insert_site( [ 'site_url' => 'off.test', 'site_enabled' => 0 ] );

		$this->assertTrue( Site::enabled( 'on.test' ) );
		$this->assertFalse( Site::enabled( 'off.test' ) );
		$this->assertFalse( Site::enabled( 'missing.test' ) );
	}

	public function test_site_meta_set_get_and_find_by_key() {
		$a = $this->insert_site( [ 'site_url' => 'a.test' ] );
		$b = $this->insert_site( [ 'site_url' => 'b.test' ] );
		Site_Meta::set( $a, 'redis_prefix', 'a_' );
		Site_Meta::set( $b, 'redis_prefix', 'b_' );
		Site_Meta::set( $b, 'other', '' );

		$this->assertSame( 'a_', Site_Meta::get( $a, 'redis_prefix' ) );
		$this->assertSame( 'b_', Site_Meta::get( $b, 'redis_prefix' ) );
		$this->assertFalse( Site_Meta::get( $a, 'other' ) );
		$this->assertFalse( Site_Meta::get( $b, 'other' ), 'An empty value reads as false.' );
		$this->assertSame( [ '1', '2' ], array_map( 'strval', Site_Meta::find_ids_from_key( 'redis_prefix' ) ) );
		$this->assertFalse( Site_Meta::find_ids_from_key( 'nothing' ) );
	}

	public function test_update_site_db_entry_writes_the_fields() {
		$this->insert_site( [ 'site_url' => 'u.test' ] );
		update_site_db_entry( 'u.test', [ 'alias_domains' => 'u.test,x.test', 'site_ssl' => 'self' ] );

		$site = Site::find( 'u.test' );
		$this->assertSame( [ 'u.test,x.test', 'self' ], [ $site->alias_domains, $site->site_ssl ] );
	}

	public function test_update_site_db_entry_touches_only_the_named_site() {
		$this->insert_site( [ 'site_url' => 'u.test' ] );
		$this->insert_site( [ 'site_url' => 'v.test', 'site_ssl' => 'le' ] );
		update_site_db_entry( 'u.test', [ 'site_ssl' => 'self' ] );

		$this->assertSame( [ 'self', 'le' ], array_column( TestDB::rows( 'sites' ), 'site_ssl' ) );
	}

	/**
	 * @dataProvider public_dirs
	 */
	public function test_get_public_dir( $flag, $expected ) {
		$this->assertSame( $expected, get_public_dir( null === $flag ? [] : [ 'public-dir' => $flag ] ) );
	}

	public function public_dirs() {
		return [
			'none'            => [ null, '/var/www/htdocs' ],
			'relative'        => [ 'public', '/var/www/htdocs/public' ],
			'nested'          => [ 'current/public/', '/var/www/htdocs/current/public' ],
			'container path'  => [ '/var/www/htdocs/web/', '/var/www/htdocs/web' ],
			'traversal'       => [ '../../etc', '/var/www/htdocs/etc' ],
			'shell chars'     => [ 'a;b$(c)', '/var/www/htdocs/abc' ],
			'empty'           => [ '', '/var/www/htdocs' ],
		];
	}

	/**
	 * PHPT-12/WPT-6: a bare `--public-dir` arrives as `true` and becomes the document root /var/www/htdocs/1.
	 *
	 * @group known-bug
	 * @group PHPT-12
	 * @group WPT-6
	 */
	public function test_get_public_dir_does_not_turn_a_bare_flag_into_1() {
		try {
			$this->assertNotSame( '/var/www/htdocs/1', get_public_dir( [ 'public-dir' => true ] ) );
		} catch ( EE\ExitException $e ) {
			$this->assertStringContainsString( 'public-dir', $this->stderr() );
		}
	}

	public function test_get_webroot_appends_the_public_dir() {
		$this->assertSame( '/s/app/htdocs', get_webroot( '/s/app/htdocs', '/var/www/htdocs' ) );
		$this->assertSame( '/s/app/htdocs/public', get_webroot( '/s/app/htdocs', '/var/www/htdocs/public' ) );
		$this->assertSame( '/s/app/htdocs/a/b', get_webroot( '/s/app/htdocs', '/var/www/htdocs/a/b/' ) );
	}

	public function test_subnet_math() {
		$this->assertSame( 0xFFFFFF00, subnet_mask_int2long( 24 ) & 0xFFFFFFFF );
		$this->assertSame( [ '10.0.4.0', '10.0.4.255' ], array_map( 'long2ip', get_subnet_range( '10.0.4.77', 24 ) ) );
		$this->assertSame( [ '10.16.0.0', '10.31.255.255' ], array_map( 'long2ip', get_subnet_range( '10.20.1.1', 12 ) ) );
		$this->assertTrue( ip_in_subnet( '10.1.200.3', '10.1.0.0/16' ) );
		$this->assertFalse( ip_in_subnet( '10.2.0.1', '10.1.0.0/16' ) );
		$this->assertTrue( ip_in_subnet( '10.0.0.255', '10.0.0.128/25' ) );
		$this->assertFalse( ip_in_subnet( '10.0.0.127', '10.0.0.128/25' ) );
	}

	public function test_available_subnet_skips_host_routes_and_ee_networks() {
		$this->runner->on( '/^ip route show /', "10.0.0.0/24\n10.0.1.0/24\n\n" );
		Option::set( 'frontend_subnet_ip', '10.0.2.0/24' );
		Option::set( 'backend_subnet_ip', '10.0.3.0/24' );

		$this->assertSame( '10.0.4.0/24', get_available_subnet() );
		$this->assertSame( [ "ip route show | cut -d ' ' -f1 | grep ^10" ], $this->runner->commands() );
	}

	public function test_available_subnet_skips_a_whole_larger_route() {
		$this->runner->on( '/^ip route show /', "10.0.0.0/16\n" );
		$this->assertSame( '10.1.0.0/24', get_available_subnet() );
		$this->assertSame( '10.1.0.0/16', get_available_subnet( 16 ) );
	}

	public function test_available_subnet_errors_when_10_slash_8_is_used_up() {
		$this->runner->on( '/^ip route show /', "10.0.0.0/8\n" );
		$this->assert_ee_error(
			function () {
				get_available_subnet();
			},
			'It seems you have run out of your private IP adress space.'
		);
	}

	/**
	 * SC-23 (new): the loop stops only at 10.255.255.0, which /16 steps never hit, so a routed 10.0.0.0/8 (a VPN) yields the public 11.0.0.0/16.
	 *
	 * @group known-bug
	 * @group SC-23
	 */
	public function test_available_subnet_stays_inside_10_slash_8_for_16_bit_masks() {
		$this->runner->on( '/^ip route show /', "10.0.0.0/8\n" );
		$this->assert_ee_error(
			function () {
				$this->fail( 'Allocated ' . get_available_subnet( 16 ) );
			},
			'It seems you have run out of your private IP adress space.'
		);
	}

	/**
	 * SC-21 (new): only the candidate's first and last address are checked, so a smaller route inside it (a /26 VPN) is missed.
	 *
	 * @group known-bug
	 * @group SC-21
	 */
	public function test_available_subnet_does_not_overlap_a_smaller_route() {
		$this->runner->on( '/^ip route show /', "10.0.0.64/26\n" );
		$this->assertSame( '10.0.1.0/24', get_available_subnet() );
	}

	/**
	 * SC-22 (new): a host route (`10.0.5.9`, no mask) raises "Undefined array key 1" and is treated as a /16.
	 *
	 * @group known-bug
	 * @group SC-22
	 */
	public function test_available_subnet_treats_a_host_route_as_one_address() {
		$this->runner->on( '/^ip route show /', "10.0.0.9\n" );
		$this->assertSame( '10.0.1.0/24', get_available_subnet() );
	}
}
