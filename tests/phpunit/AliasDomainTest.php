<?php

use EE\Model\Site;
use EE\Site\Tests\SiteTestCase;
use function EE\Site\Utils\check_alias_in_db;
use function EE\Site\Utils\get_all_alias_domains;
use function EE\Site\Utils\get_domains_of_site;
use function EE\Site\Utils\get_parent_of_alias;
use function EE\Site\Utils\is_valid_alias_domain;
use function EE\Site\Utils\split_alias_domains;
use function EE\Site\Utils\validate_alias_domains;

/**
 * Alias domain parsing, validation, ownership lookup and `ee site update --add/--delete-alias-domains`.
 */
class AliasDomainTest extends SiteTestCase {

	public function test_split_alias_domains_trims_and_drops_blanks() {
		$this->assertSame( [ 'a.test', '*.a.test', 'b.test' ], split_alias_domains( ' a.test, *.a.test ,,b.test, ' ) );
		$this->assertSame( [], split_alias_domains( '' ) );
		$this->assertSame( [], split_alias_domains( ' , ' ) );
		$this->assertSame( [], split_alias_domains( true ), 'A bare flag must not become the alias `1`.' );
		$this->assertSame( [], split_alias_domains( null ) );
	}

	/**
	 * @dataProvider alias_validity
	 */
	public function test_is_valid_alias_domain( $domain, $valid ) {
		$this->assertSame( $valid, is_valid_alias_domain( $domain ), var_export( $domain, true ) );
	}

	public function alias_validity() {
		return [
			'hostname'              => [ 'example.com', true ],
			'wildcard'              => [ '*.example.com', true ],
			'single label'          => [ 'localhost', true ],
			'uppercase'             => [ 'WWW.Example.COM', true ],
			'inner underscore'      => [ 'a_b.test', true ],
			'punycode'              => [ 'xn--bcher-kva.test', true ],
			'leading underscore'    => [ '_wildcard.a.test', false ],
			'leading hyphen'        => [ '-a.test', false ],
			'trailing hyphen'       => [ 'a-.test', false ],
			'empty label'           => [ 'a..test', false ],
			'trailing dot'          => [ 'a.test.', false ],
			'inner wildcard'        => [ 'a.*.test', false ],
			'double wildcard'       => [ '*.*.a.test', false ],
			'bare wildcard'         => [ '*', false ],
			'space'                 => [ 'a b.test', false ],
			'slash'                 => [ '../a.test', false ],
			'shell'                 => [ 'a$(id).test', false ],
			'newline'               => [ "a.test\nb.test", false ],
			'reserved default'      => [ 'default', false ],
			'reserved any case'     => [ 'Default_Admin_Tools', false ],
			'empty'                 => [ '', false ],
			'bool from a bare flag' => [ true, false ],
		];
	}

	public function test_validate_alias_domains_lists_every_invalid_domain() {
		validate_alias_domains( [ 'a.test', '*.a.test' ] );
		$this->assertSame( '', $this->stderr() );

		$this->assert_ee_error(
			function () {
				validate_alias_domains( [ 'ok.test', '-bad.test', 'default' ] );
			},
			'Invalid alias domain(s): -bad.test, default.'
		);
	}

	public function test_get_all_alias_domains_excludes_site_names() {
		$this->create_site( 'one.test', [ 'alias_domains' => 'one.test,*.one.test,alias-one.test' ] );
		$this->create_site( 'two.test', [ 'alias_domains' => 'two.test,,one-more.test' ] );
		$this->create_site( 'three.test', [ 'alias_domains' => null ] );

		$this->assertSame( [ '*.one.test', 'alias-one.test', 'one-more.test' ], array_values( get_all_alias_domains() ) );
	}

	public function test_get_parent_of_alias_finds_the_owner_of_an_exact_alias() {
		$this->create_site( 'owner.test', [ 'alias_domains' => 'owner.test,shop.test' ] );
		$this->create_site( 'other.test', [ 'alias_domains' => 'other.test' ] );

		$this->assertSame( 'owner.test', get_parent_of_alias( 'shop.test' ) );
		$this->assertSame( '', get_parent_of_alias( 'unknown.test' ) );
		$this->assertSame( '', get_parent_of_alias( 'other.test' ), 'A site name is not an alias.' );
	}

	/**
	 * SC-2: the owner is looked up with `LIKE '%alias%'`, so a substring of (or `_`-wildcard match on) another site's alias wins.
	 *
	 * @group known-bug
	 * @group SC-2
	 * @dataProvider overlapping_aliases
	 */
	public function test_get_parent_of_alias_matches_the_alias_exactly( $first_alias, $alias ) {
		$this->create_site( 'first.test', [ 'alias_domains' => "first.test,$first_alias" ] );
		$this->create_site( 'second.test', [ 'alias_domains' => "second.test,$alias" ] );

		$this->assertSame( 'second.test', get_parent_of_alias( $alias ) );
	}

	public function overlapping_aliases() {
		return [
			'substring'           => [ 'ab.x.test', 'b.x.test' ],
			'underscore wildcard' => [ 'abc.test', 'a_c.test' ],
		];
	}

	public function test_check_alias_in_db_names_the_owning_site() {
		$this->create_site( 'owner.test', [ 'alias_domains' => 'owner.test,shop.test' ] );

		check_alias_in_db( [ 'new.test', 'fresh.test' ] );
		$this->assert_ee_error(
			function () {
				check_alias_in_db( [ 'new.test', ' shop.test ' ] );
			},
			'Site  shop.test  already exists as an alias domain for site: owner.test.'
		);
	}

	public function test_get_domains_of_site_includes_the_site_once() {
		$this->create_site( 'dom.test', [ 'alias_domains' => 'dom.test,*.dom.test,x.test' ] );
		$this->assertSame( [ 'dom.test', '*.dom.test', 'x.test' ], array_values( get_domains_of_site( 'dom.test' ) ) );
	}

	/**
	 * @dataProvider rejected_alias_updates
	 */
	public function test_update_alias_domains_rejects_invalid_requests( $assoc_args, $message ) {
		$this->create_site( 'main.test', [ 'alias_domains' => 'main.test,b.test' ] );
		$this->create_site( 'taken.test', [ 'alias_domains' => 'taken.test,taken-alias.test' ] );
		$failed = $this->capture_hook( 'site_alias_domains_update_failed' );
		$before = $this->capture_hook( 'site_alias_domains_before_update' );

		$this->assert_ee_error(
			function () use ( $assoc_args ) {
				$this->update_aliases( 'main.test', $assoc_args );
			},
			$message
		);
		$this->assertSame( 'main.test,b.test', Site::find( 'main.test' )->alias_domains );
		$this->assertSame( [], $this->runner->commands() );
		$this->assertCount( 0, $before );
		$this->assertCount( 0, $failed, 'Nothing was set up, so there is nothing to undo.' );
	}

	public function rejected_alias_updates() {
		return [
			'blank list'           => [ [ 'add-alias-domains' => ' , ' ], 'Please provide at least one alias domain to add or delete.' ],
			'bare flag'            => [ [ 'add-alias-domains' => true ], 'Please provide at least one alias domain to add or delete.' ],
			'invalid domain'       => [ [ 'add-alias-domains' => 'ok.test,_wildcard.main.test' ], 'Invalid alias domain(s): _wildcard.main.test.' ],
			'already present'      => [ [ 'add-alias-domains' => 'b.test' ], 'Alias domains: b.test is/are already present on the site.' ],
			'primary deleted'      => [ [ 'delete-alias-domains' => 'main.test' ], 'Primary site domain: `main.test` can not be deleted.' ],
			'delete not an alias'  => [ [ 'delete-alias-domains' => 'nope.test' ], 'Domains to delete is/are not a subset of already existing alias domains.' ],
			'another site'         => [ [ 'add-alias-domains' => 'taken.test' ], 'taken.test already exists as a site.' ],
			'another sites alias'  => [ [ 'add-alias-domains' => 'taken-alias.test' ], 'Site taken-alias.test already exists as an alias domain for site: taken.test.' ],
		];
	}

	public function test_update_alias_domains_fires_the_failure_hook_when_a_before_hook_throws() {
		$this->create_site( 'main.test', [ 'alias_domains' => 'main.test' ] );
		$this->capture_hook(
			'site_alias_domains_before_update',
			function () {
				throw new \Exception( 'auth setup failed' );
			}
		);
		$failed = $this->capture_hook( 'site_alias_domains_update_failed' );

		$this->assert_ee_error(
			function () {
				$this->update_aliases( 'main.test', [ 'add-alias-domains' => 'new.test' ] );
			},
			'auth setup failed'
		);
		$this->assertSame( [ [ 'main.test', [ 'new.test' ] ] ], $failed->getArrayCopy() );
		$this->assertSame( 'main.test', Site::find( 'main.test' )->alias_domains );
	}

	public function test_update_alias_domains_adds_and_deletes_and_rewrites_the_compose_file() {
		$site = $this->create_site( 'main.test', [ 'alias_domains' => 'main.test,old.test,keep.test' ] );
		$this->seed_acme_account_key();
		$this->set_config( [ 'custom-compose' => null ] );
		$this->runner->on( '/^docker volume ls /', "maintest_htdocs\n" );
		$before  = $this->capture_hook( 'site_alias_domains_before_update' );
		$updated = $this->capture_hook( 'site_alias_domains_updated' );

		$this->update_aliases( 'main.test', [ 'add-alias-domains' => 'new.test, *.new.test,keep.test', 'delete-alias-domains' => 'old.test' ] );

		$this->assertSame( 'main.test,keep.test,new.test,*.new.test', Site::find( 'main.test' )->alias_domains );
		$this->assertSame( [ [ 'main.test', [ 'new.test', '*.new.test' ] ] ], $before->getArrayCopy() );
		$this->assertSame( [ [ 'main.test', [ 'new.test', '*.new.test' ], [ 'old.test' ] ] ], $updated->getArrayCopy() );
		$this->assertStringContainsString( 'Following domains: keep.test is/are already present on site, skipping addition of those.', $this->stderr() );
		$this->assertStringContainsString( 'Alias domains updated on site main.test.', $this->stdout() );

		$compose = file_get_contents( $site->site_fs_path . '/docker-compose.yml' );
		$this->assertStringContainsString( '- VIRTUAL_HOST=${VIRTUAL_HOST},keep.test,new.test,*.new.test', $compose );
		$this->assertStringContainsString( '- CERT_NAME=${VIRTUAL_HOST}', $compose );
		$this->assertStringContainsString( 'HTTPS_METHOD=nohttps', $compose, 'A site without SSL stays HTTP-only after the change (WPT-2).' );
		$this->assertSame( [ 'docker-compose -f docker-compose.yml  up -d nginx', 'docker-compose -f docker-compose.yml  up -d nginx' ], $this->runner->matching( '/up -d/' ) );
	}

	private function update_aliases( $site_url, array $assoc_args ) {
		$command = $this->html_command( Site::find( $site_url ) );
		self::call_private( $command, 'update_alias_domains', [ $site_url ], $assoc_args );

		return $command;
	}
}
