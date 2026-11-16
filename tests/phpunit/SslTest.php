<?php

use AcmePhp\Ssl\Certificate;
use AcmePhp\Ssl\DistinguishedName;
use AcmePhp\Ssl\KeyPair;
use AcmePhp\Ssl\PrivateKey;
use AcmePhp\Ssl\PublicKey;
use EE\Site\Tests\SiteTestCase;
use EE\Site\Type\Site_Letsencrypt;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use function EE\Site\Utils\ssl_needs_creation;

/**
 * Certificate expiry checks and the Let's Encrypt client (src/helper/Site_Letsencrypt.php) against an in-process ACME stub.
 */
class SslTest extends SiteTestCase {

	protected $reset_db = false;

	const DIRECTORY = 'https://acme-v02.api.letsencrypt.org/directory';

	/** @var array[] Requests the ACME stub received: [ method, url ]. */
	private $acme_requests = [];

	protected function setUp(): void {
		parent::setUp();
		$this->track( EE_SERVICE_DIR . '/nginx-proxy' );
	}

	/**
	 * A self-signed certificate for $sans, with a subject CN only when $common_name is given (LE's newer profiles omit it).
	 */
	private function certificate_pem( $common_name, array $sans, $days ) {
		$config = $this->sandbox() . '/openssl.cnf';
		file_put_contents( $config, "[req]\ndistinguished_name = dn\n[dn]\n[v3]\nsubjectAltName = " . implode( ',', array_map( function ( $san ) {
			return "DNS:$san";
		}, $sans ) ) . "\n" );
		$options = [ 'config' => $config, 'digest_alg' => 'sha256', 'x509_extensions' => 'v3' ];
		$key     = openssl_pkey_get_private( self::rsa_key_pem() );
		$dn      = $common_name ? [ 'commonName' => $common_name ] : [ 'organizationName' => 'EE test' ];
		$cert    = openssl_csr_sign( openssl_csr_new( $dn, $key, $options ), null, $key, $days, $options, random_int( 1, PHP_INT_MAX ) );
		openssl_x509_export( $cert, $pem );

		return $pem;
	}

	private function install_proxy_cert( $site_url, $pem ) {
		$dir = EE_SERVICE_DIR . '/nginx-proxy/certs';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0700, true );
		}
		file_put_contents( "$dir/$site_url.crt", $pem );
	}

	public function test_ssl_needs_creation_without_a_certificate() {
		$this->assertTrue( ssl_needs_creation( 'none.test' ) );
	}

	public function test_ssl_needs_creation_depends_on_35_days_of_validity() {
		$this->install_proxy_cert( 'fresh.test', $this->certificate_pem( 'fresh.test', [ 'fresh.test' ], 90 ) );
		$this->install_proxy_cert( 'expiring.test', $this->certificate_pem( 'expiring.test', [ 'expiring.test' ], 20 ) );

		$this->assertFalse( ssl_needs_creation( 'fresh.test' ) );
		$this->assertStringContainsString( 'Current certificate is valid until ' . gmdate( 'Y-m-d', time() + 90 * 86400 ), $this->stdout() );
		$this->assertTrue( ssl_needs_creation( 'expiring.test' ) );
	}

	/**
	 * SSL-11 (fixed in v3.8.1): a SAN-only certificate (LE tlsserver/shortlived profiles, no subject CN) can be checked.
	 *
	 * @group SSL-11
	 */
	public function test_ssl_needs_creation_reads_a_san_only_certificate() {
		$this->install_proxy_cert( 'sanonly.test', $this->certificate_pem( null, [ 'sanonly.test', 'www.sanonly.test' ], 90 ) );

		try {
			$this->assertFalse( ssl_needs_creation( 'sanonly.test' ) );
		} catch ( \AcmePhp\Ssl\Exception\CertificateParsingException $e ) {
			$this->fail( $e->getMessage() );
		}
	}

	public function test_renewal_checks_read_the_stored_certificate() {
		$client = $this->letsencrypt();
		$this->store_certificate( $client, 'renew.test', $this->certificate_pem( 'renew.test', [ 'renew.test' ], 20 ) );
		$this->store_certificate( $client, 'keep.test', $this->certificate_pem( 'keep.test', [ 'keep.test' ], 80 ) );

		$this->assertTrue( $client->isRenewalNecessary( 'renew.test' ) );
		$this->assertFalse( $client->isRenewalNecessary( 'keep.test' ) );
		$this->assertFalse( $client->isAlreadyExpired( 'keep.test' ) );
		$this->assertSame( '', $this->stderr() );
	}

	/**
	 * SSL-11 (fixed in v3.8.1): renewal and expiry checks read a SAN-only certificate without "Missing expected key subject.cn".
	 *
	 * @group SSL-11
	 */
	public function test_renewal_checks_read_a_san_only_certificate() {
		$client = $this->letsencrypt();
		$this->store_certificate( $client, 'sanonly.test', $this->certificate_pem( null, [ 'sanonly.test' ], 80 ) );

		$this->assertFalse( $client->isAlreadyExpired( 'sanonly.test' ) );
		$this->assertStringNotContainsString( 'subject.cn', $this->stderr() );
		try {
			$this->assertFalse( $client->isRenewalNecessary( 'sanonly.test' ) );
		} catch ( \AcmePhp\Ssl\Exception\CertificateParsingException $e ) {
			$this->fail( $e->getMessage() );
		}
	}

	public function test_valid_certificate_needs_matching_alternative_names() {
		$client = $this->letsencrypt();
		$this->store_certificate( $client, 'a.test', $this->certificate_pem( 'a.test', [ 'a.test', 'www.a.test' ], 80 ) );
		$repository = self::get_property( $client, 'repository' );

		$this->assertFalse( self::call_private( $client, 'hasValidCertificate', 'a.test', [ 'www.a.test' ] ), 'No stored distinguished name yet.' );

		$repository->storeDomainDistinguishedName( 'a.test', new DistinguishedName( 'a.test', null, null, null, null, null, null, [ 'www.a.test' ] ) );
		$this->assertTrue( self::call_private( $client, 'hasValidCertificate', 'a.test', [ 'www.a.test' ] ) );
		$this->assertFalse( self::call_private( $client, 'hasValidCertificate', 'a.test', [] ) );
		$this->assertFalse( self::call_private( $client, 'hasValidCertificate', 'b.test', [ 'www.a.test' ] ) );
	}

	public function test_distinguished_name_keeps_stored_fields_and_updates_the_names() {
		$client = $this->letsencrypt();

		$new = self::call_private( $client, 'getOrCreateDistinguishedName', 'dn.test', [ 'www.dn.test' ], 'ops@dn.test' );
		$this->assertSame( [ 'dn.test', 'US', 'CA', 'Mountain View', "Let's Encrypt", 'ops@dn.test', [ 'www.dn.test' ] ], [ $new->getCommonName(), $new->getCountryName(), $new->getStateOrProvinceName(), $new->getLocalityName(), $new->getOrganizationName(), $new->getEmailAddress(), $new->getSubjectAlternativeNames() ] );

		self::get_property( $client, 'repository' )->storeDomainDistinguishedName( 'dn.test', new DistinguishedName( 'dn.test', 'IN', 'MH', 'Pune', 'Org', 'Unit', 'old@dn.test', [] ) );
		$updated = self::call_private( $client, 'getOrCreateDistinguishedName', 'dn.test', [ 'alias.test' ], 'new@dn.test' );
		// The current le-mail replaces the stored one (#497); without one, the stored email is kept.
		$this->assertSame( [ 'IN', 'Pune', 'new@dn.test', [ 'alias.test' ] ], [ $updated->getCountryName(), $updated->getLocalityName(), $updated->getEmailAddress(), $updated->getSubjectAlternativeNames() ] );
		$this->assertSame( [ 'alias.test' ], self::get_property( $client, 'repository' )->loadDomainDistinguishedName( 'dn.test' )->getSubjectAlternativeNames() );
		$this->assertSame( 'new@dn.test', self::call_private( $client, 'getOrCreateDistinguishedName', 'dn.test', [ 'alias.test' ], '' )->getEmailAddress() );
	}

	public function test_renewal_is_skipped_while_the_certificate_is_fresh() {
		$client = $this->letsencrypt();
		$this->store_certificate( $client, 'fresh.test', $this->certificate_pem( 'fresh.test', [ 'fresh.test' ], 80 ) );

		$this->assertTrue( self::call_private( $client, 'executeRenewal', 'fresh.test', [], false ) );
		$this->assertStringContainsString( 'renewal is not necessary', $this->stdout() );
		$this->assertSame( [], $this->acme_requests );
	}

	public function test_http_authorization_writes_the_challenge_file_and_stores_the_order() {
		$client = $this->letsencrypt( [ $this->challenge( 'http-01', 'tok-http' ), $this->challenge( 'dns-01', 'tok-dns' ) ] );

		$this->assertTrue( $client->authorize( [ 'a.test' ] ), $this->stderr() );

		$file = EE_SERVICE_DIR . '/nginx-proxy/html/.well-known/acme-challenge/tok-http';
		$this->assertFileExists( $file );
		$this->assertStringStartsWith( 'tok-http.', file_get_contents( $file ) );
		$this->assertFileEquals( SITE_TEMPLATE_ROOT . '/vhost.d_default_letsencrypt.mustache', EE_SERVICE_DIR . '/nginx-proxy/vhost.d/default' );
		$this->assertTrue( $client->hasDomainAuthorizationChallenge( 'a.test' ) );
		$this->assertSame( 'http-01', $client->loadDomainAuthorizationChallenge( 'a.test' )->getType() );
		$this->assertTrue( self::get_property( $client, 'repository' )->hasCertificateOrder( [ 'a.test' ] ) );
		$this->assertCount( 1, $this->runner->matching( '/nginx -s reload/' ) );
		$this->assertContains( [ 'POST', 'https://acme.test/order' ], $this->acme_requests );
	}

	public function test_authorization_skips_already_valid_domains() {
		$client = $this->letsencrypt( [ $this->challenge( 'http-01', 'tok', 'valid' ) ] );

		$this->assertTrue( $client->authorize( [ 'a.test' ] ) );
		$this->assertFalse( $client->hasDomainAuthorizationChallenge( 'a.test' ) );
		$this->assertSame( [], $this->runner->commands() );
	}

	public function test_authorization_fails_without_a_supported_challenge() {
		$client = $this->letsencrypt( [ $this->challenge( 'dns-01', 'tok' ) ] );

		$this->assertFalse( $client->authorize( [ 'a.test' ] ) );
		$this->assertStringContainsString( 'Challenge not supported for domain a.test', $this->stderr() );
	}

	public function test_authorization_fails_when_the_order_is_refused() {
		$client = $this->letsencrypt( [], 400 );

		$this->assertFalse( $client->authorize( [ 'a.test' ] ) );
		$this->assertStringContainsString( "It seems you're in local environment or using non-public domain", $this->stderr() );
	}

	/**
	 * SSL-10 (fixed in v3.8.1): a token-less challenge type (dns-persist-01) is skipped instead of failing every order.
	 *
	 * @group SSL-10
	 */
	public function test_authorization_ignores_a_challenge_without_a_token() {
		$client = $this->letsencrypt( [ $this->challenge( 'http-01', 'tok-http' ), $this->challenge( 'dns-persist-01', null ) ] );

		// Production PHP only prints the "Undefined array key" warning; the Assert in AuthorizationChallenge is what fails.
		set_error_handler( function () {
			return true;
		}, E_WARNING | E_NOTICE );
		try {
			$authorized = $client->authorize( [ 'a.test' ] );
		} finally {
			restore_error_handler();
		}
		$this->assertTrue( $authorized, 'authorize() failed: ' . trim( $this->stderr() ) );
	}

	/**
	 * The acme-conf store keeps flysystem 1.x's modes (dirs 0755 whatever the umask, private files 0600), overwrites on renewal and deletes on removal.
	 */
	public function test_certificate_store_keeps_modes_and_overwrites_on_renewal() {
		$client = $this->letsencrypt();
		$conf   = EE_SERVICE_DIR . '/nginx-proxy/acme-conf';
		$umask  = umask( 077 );
		try {
			$this->store_certificate( $client, 'm.test', $first = $this->certificate_pem( 'm.test', [ 'm.test' ], 90 ) );
			$this->store_certificate( $client, 'm.test', $second = $this->certificate_pem( 'm.test', [ 'm.test' ], 60 ) );
		} finally {
			umask( $umask );
		}
		clearstatcache();

		foreach ( [ 'certs', 'certs/m.test', 'certs/m.test/private', 'certs/m.test/public' ] as $dir ) {
			$this->assertSame( '0755', sprintf( '%04o', fileperms( "$conf/$dir" ) & 07777 ), $dir );
		}
		foreach ( [ 'private/key.private.pem', 'private/key.public.pem', 'private/combined.pem', 'public/cert.pem', 'public/chain.pem', 'public/fullchain.pem' ] as $file ) {
			$this->assertSame( '0600', sprintf( '%04o', fileperms( "$conf/certs/m.test/$file" ) & 07777 ), $file );
		}
		$this->assertNotSame( $first, $second );
		$this->assertSame( trim( $second ), trim( file_get_contents( "$conf/certs/m.test/public/cert.pem" ) ) );
		$this->assertSame( trim( $second ), $client->loadDomainCertificates( [ 'm.test' ] )['m.test']->getPEM() );

		self::get_property( $client, 'repository' )->removeDomain( [ 'm.test' ] );
		clearstatcache();
		$this->assertFileDoesNotExist( "$conf/certs/m.test/public/cert.pem" );
		$this->assertFileDoesNotExist( "$conf/certs/m.test/private/key.private.pem" );
	}

	/** A Site_Letsencrypt whose HTTP client talks to an in-process ACME stub. */
	private function letsencrypt( array $challenges = [], $order_status = 201 ) {
		$this->seed_acme_account_key();
		$client = ( new \ReflectionClass( Site_Letsencrypt::class ) )->newInstanceWithoutConstructor();
		self::set_property( $client, 'conf_dir', EE_SERVICE_DIR . '/nginx-proxy/acme-conf' );
		self::set_property( $client, 'httpClient', new \GuzzleHttp\Client( [ 'handler' => \GuzzleHttp\HandlerStack::create( $this->acme_stub( $challenges, $order_status ) ) ] ) );
		self::call_private( $client, 'setRepository' );
		self::call_private( $client, 'setAcmeClient' );

		return $client;
	}

	private function challenge( $type, $token, $status = 'pending' ) {
		$challenge = [ 'type' => $type, 'status' => $status, 'url' => "https://acme.test/chall/$type" ];
		if ( null !== $token ) {
			$challenge['token'] = $token;
		}

		return $challenge;
	}

	private function acme_stub( array $challenges, $order_status ) {
		return function ( RequestInterface $request ) use ( $challenges, $order_status ) {
			$url                   = (string) $request->getUri();
			$this->acme_requests[] = [ $request->getMethod(), $url ];
			$headers               = [ 'Replay-Nonce' => 'nonce-' . count( $this->acme_requests ), 'Content-Type' => 'application/json' ];
			$json                  = function ( $status, $body, $extra = [] ) use ( $headers ) {
				return \GuzzleHttp\Promise\Create::promiseFor( new Response( $status, $extra + $headers, json_encode( $body ) ) );
			};

			switch ( $url ) {
				case self::DIRECTORY:
					return $json( 200, [ 'newNonce' => 'https://acme.test/nonce', 'newAccount' => 'https://acme.test/account', 'newOrder' => 'https://acme.test/order', 'revokeCert' => 'https://acme.test/revoke' ] );
				case 'https://acme.test/nonce':
					return $json( 200, [] );
				case 'https://acme.test/account':
					return $json( 200, [ 'status' => 'valid' ], [ 'Location' => 'https://acme.test/account/1' ] );
				case 'https://acme.test/order':
					if ( 201 !== $order_status ) {
						return $json( $order_status, [ 'type' => 'urn:ietf:params:acme:error:rejectedIdentifier', 'detail' => 'refused' ] );
					}

					return $json( 201, [ 'status' => 'pending', 'authorizations' => [ 'https://acme.test/authz/1' ], 'finalize' => 'https://acme.test/order/1/finalize' ], [ 'Location' => 'https://acme.test/order/1' ] );
				case 'https://acme.test/authz/1':
					return $json( 200, [ 'identifier' => [ 'type' => 'dns', 'value' => 'a.test' ], 'status' => 'pending', 'challenges' => $challenges ] );
			}

			return $json( 404, [ 'type' => 'urn:ietf:params:acme:error:malformed', 'detail' => "no route for $url" ] );
		};
	}

	private function store_certificate( Site_Letsencrypt $client, $domain, $pem ) {
		$private    = self::rsa_key_pem();
		$repository = self::get_property( $client, 'repository' );
		$repository->storeDomainKeyPair( $domain, new KeyPair( new PublicKey( openssl_pkey_get_details( openssl_pkey_get_private( $private ) )['key'] ), new PrivateKey( $private ) ) );
		$repository->storeDomainCertificate( $domain, new Certificate( $pem ) );
	}
}
