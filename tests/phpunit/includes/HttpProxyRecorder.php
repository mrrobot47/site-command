<?php

namespace EE\Site\Tests;

/**
 * A PHP built-in server on 127.0.0.1 used as `http_proxy`, so code that curls a site name can run without DNS or network.
 *
 * It records the Host of every request and answers `/check.html` from $docroot for the hosts in $serve, 404 otherwise.
 */
class HttpProxyRecorder {

	private $process;
	private $dir;
	private $old_proxy;

	public function __construct( $dir, $docroot, array $serve ) {
		$this->dir = $dir;
		file_put_contents( "$dir/serve.json", json_encode( [ 'docroot' => $docroot, 'hosts' => $serve ] ) );
		file_put_contents( "$dir/router.php", <<<'PHP'
<?php
$conf = json_decode( file_get_contents( __DIR__ . '/serve.json' ), true );
$host = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
file_put_contents( __DIR__ . '/hosts.log', $host . "\n", FILE_APPEND );
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( in_array( $host, $conf['hosts'], true ) && is_file( $conf['docroot'] . $path ) ) {
	echo file_get_contents( $conf['docroot'] . $path );
	return true;
}
http_response_code( 404 );
echo 'not here';
return true;
PHP
		);

		$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
		$port  = (int) substr( strrchr( stream_socket_get_name( $probe, false ), ':' ), 1 );
		fclose( $probe );

		$null          = [ 'file', '/dev/null', 'w' ];
		$this->process = proc_open( [ PHP_BINARY, '-S', "127.0.0.1:$port", "$dir/router.php" ], [ [ 'file', '/dev/null', 'r' ], $null, $null ], $pipes );
		for ( $i = 0; $i < 200; $i++ ) {
			$socket = @fsockopen( '127.0.0.1', $port );
			if ( $socket ) {
				fclose( $socket );
				break;
			}
			usleep( 10000 );
		}
		$this->old_proxy = getenv( 'http_proxy' );
		putenv( "http_proxy=http://127.0.0.1:$port" );
	}

	/** @return string[] Host headers of the requests so far. */
	public function hosts() {
		$log = $this->dir . '/hosts.log';

		return file_exists( $log ) ? array_values( array_filter( explode( "\n", file_get_contents( $log ) ) ) ) : [];
	}

	public function stop() {
		putenv( false === $this->old_proxy ? 'http_proxy' : 'http_proxy=' . $this->old_proxy );
		if ( is_resource( $this->process ) ) {
			proc_terminate( $this->process );
			proc_close( $this->process );
		}
	}
}
