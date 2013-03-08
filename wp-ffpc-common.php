<?php
/**
 * part of WordPress plugin WP-FFPC
 */

/**
 * function to test if selected backend is available & alive
 */
global $wp_ffpc_backend;
global $wp_nmc_redirect;

/* wp ffpc prefic */
if (!defined('WP_FFPC_PARAM'))
	define ( 'WP_FFPC_PARAM' , 'wp-ffpc' );
/* log level */
define ('WP_FFPC_LOG_LEVEL' , LOG_WARNING );
/* define log ending message */
define ('WP_FFPC_LOG_TYPE_MSG' , '; cache type: '. $wp_ffpc_config['cache_type'] );


/* safety rules if file has been already included */
if ( function_exists('wp_ffpc_init') || function_exists('wp_ffpc_clear') || function_exists('wp_ffpc_set') || function_exists('wp_ffpc_get') )
	return false;

/**
 * init and backend alive check function
 *
 * @param $type [optional] if set, alive will be tested against this
 * 		  if false, backend will be globally initiated
 * 		  when set, backend will not become global, just tested if alive
 */
function wp_ffpc_init( $wp_ffpc_config ) {
	global $wp_ffpc_backend;
	$wp_ffpc_backend_status = false;

	if ( empty ( $wp_ffpc_config ))
		global $wp_ffpc_config;

	/* verify selected storage is available */
	switch ( $wp_ffpc_config['cache_type'] )
	{
		/* in case of apc */
		case 'apc':
			/* verify apc functions exist, apc ext is loaded */
			if (!function_exists('apc_sma_info'))
				return false;
			/* verify apc is working */
			if ( !apc_sma_info() )
				return false;
			$wp_ffpc_backend_status = true;
			break;

		/* in case of Memcache */
		case 'memcache':
			/* Memcache class does not exist, Memcache extension is not available */
			if (!class_exists('Memcache'))
				return false;
			if ( $wp_ffpc_backend == NULL )
				$wp_ffpc_backend = new Memcache();

			foreach ( $wp_ffpc_config['servers'] as $server_id => $server ) {
				$wp_ffpc_backend_status[$server_id] = $wp_ffpc_backend->connect( $server['host'] , $server['port'] );

				$wp_ffpc_config['persistent'] = ( $wp_ffpc_config['persistent'] == '1' ) ? true : false;
				if ( $wp_ffpc_backend_status[$server_id] )
				{
					$wp_ffpc_backend_status[$server_id] = true;
					$wp_ffpc_backend->addServer( $server['host'] , $server['port'], $wp_ffpc_config['persistent'] );
					wp_ffpc_log ( "server " . $server_id . " added, persistent mode: " . $wp_ffpc_config['persistent'] );
				}
			}
			return $wp_ffpc_backend_status;
			break;

		/* in case of Memcached */
		case 'memcached':
			/* Memcached class does not exist, Memcached extension is not available */
			if (!class_exists('Memcached'))
				return false;
			/* check is there's no backend connection yet */
			if ( $wp_ffpc_backend == NULL )
			{
				/* persistent backend needs an identifier */
				if ( $wp_ffpc_config['persistent'] == '1' )
					$wp_ffpc_backend = new Memcached( WP_FFPC_PARAM );
				else
					$wp_ffpc_backend = new Memcached();

				/* use binary and not compressed format, good for nginx and still fast */
				$wp_ffpc_backend->setOption( Memcached::OPT_COMPRESSION , false );
				$wp_ffpc_backend->setOption( Memcached::OPT_BINARY_PROTOCOL , true );
			}

			/* check if we already have list of servers, only add server if it's not already connected */
			$wp_ffpc_serverlist = $wp_ffpc_backend->getServerList();

			/* create check array if backend servers are already connected */
			if ( !empty ( $wp_ffpc_serverlist ) )
				foreach ( $wp_ffpc_serverlist as $server )
					$wp_ffpc_serverlist_[ $server['host'] . ":" . $server['port'] ] = true;

			/* reset all configured server status to unknown */
			foreach ( $wp_ffpc_config['servers'] as $server_id => $server )
				$wp_ffpc_backend_status[$server_id] = -1;

			/* if there's no server to add, don't add them */
			if ( empty ( $wp_ffpc_config['servers'] ) )
			{
				wp_ffpc_log ( "not adding empty set of servers, please check your settings!" );
			}
			else
			{
				foreach ( $wp_ffpc_config['servers'] as $server_id => $server ) {
					if (!@array_key_exists($server_id , $wp_ffpc_serverlist_ ))
					{
						$wp_ffpc_backend->addServer( $server['host'], $server['port'] );
						wp_ffpc_log ( "server ". $server_id ." added, persistent mode: " . $wp_ffpc_config['persistent'] );
					}
				}
			}

			/* server status will be calculated by getting server stats */
			$wp_ffpc_backend_report =  $wp_ffpc_backend->getStats();
			foreach ( $wp_ffpc_backend_report as $server_id => $server ) {
				$wp_ffpc_backend_status[$server_id] = false;
				/* if server uptime is not empty, it's most probably up & running */
				if ( !empty($server['uptime']) )
					$wp_ffpc_backend_status[$server_id] = true;
			}

			break;

		/* cache type is invalid */
		default:
			break;
	}

	return ( empty ( $wp_ffpc_backend_status ) ? false : $wp_ffpc_backend_status );
}

/**
 * clear cache element or flush cache
 *
 * @param $post_id [optional] : if registered with invalidation hook, post_id will be passed
 */
function wp_ffpc_clear ( $post_id = false ) {
	global $wp_ffpc_config;
	global $post;

	$post_only = ( $post_id === 'system_flush' ) ? false : $wp_ffpc_config['invalidation_method'];

	/* post invalidation enabled */
	if ( $post_only )
	{
		$path = substr ( get_permalink($post_id) , 7 );
		if (empty($path))
			return false;
		$wp_ffpc_data_key_protocol = empty ( $_SERVER['HTTPS'] ) ? 'http://' : 'https://';
		$meta = $wp_ffpc_config['prefix-meta'] . $wp_ffpc_data_key_protocol . $path;
		$data = $wp_ffpc_config['prefix-data'] . $wp_ffpc_data_key_protocol . $path;
	}

	switch ($wp_ffpc_config['cache_type'])
	{
		/* in case of apc */
		case 'apc':
			if ( $post_only )
			{
				apc_delete ( $meta );
				wp_ffpc_log (  ' clearing key: "'. $meta . '"' );
				apc_delete ( $data );
				wp_ffpc_log ( ' clearing key: "'. $data . '"' );
			}
			else
			{
				apc_clear_cache('user');
				wp_ffpc_log ( ' flushing user cache' );
				apc_clear_cache('system');
				wp_ffpc_log ( ' flushing system cache' );
			}
			break;

		/* in case of Memcache */
		case 'memcache':
		case 'memcached':
			global $wp_ffpc_backend;
			if ( $post_only )
			{
				$wp_ffpc_backend->delete( $meta );
				wp_ffpc_log ( ' clearing key: "'. $meta . '"' );
				$wp_ffpc_backend->delete( $data );
				wp_ffpc_log ( ' clearing key: "'. $data . '"' );
			}
			else
			{
				$wp_ffpc_backend->flush();
				wp_ffpc_log ( ' flushing cache' );
			}
			break;

		/* cache type is invalid */
		default:
			return false;
	}
	return true;
}

/**
 * sets a key-value pair in backend
 *
 * @param &$key		store key, passed by reference for speed
 * @param &$data	store value, passed by reference for speed
 *
 */
function wp_ffpc_set ( &$key, &$data, $compress = false ) {
	global $wp_ffpc_config;
	global $wp_ffpc_backend;

	$exp = $wp_ffpc_config['user_logged_in'] ? $wp_ffpc_config['expire_member'] : $wp_ffpc_config['expire_visitor'];

	/* syslog */
	if ($wp_ffpc_config['syslog'])
	{
		if ( @is_array( $data ) )
			$string = serialize($data);
		else //if ( @is_string( $data ) || @ )
			$string = $data;

		$size = @strlen($string);
		wp_ffpc_log ( ' set key: "'. $key . '", size: '. $size . ' byte(s)' );
	}

	switch ($wp_ffpc_config['cache_type'])
	{
		case 'apc':
			/* use apc_store to overwrite data is existed */
			if ( $compress )
				$data = gzdeflate ( $data , 1 );
			return apc_store( $key , $data , $exp );
			break;
		case 'memcache':
			if ( $wp_ffpc_backend != NULL )
				/* false to disable compression, vital for nginx */
				$wp_ffpc_backend->set ( $key, $data , false, $exp );
			else
				return false;
			break;
		case 'memcached':
			if ( $wp_ffpc_backend != NULL )
				$wp_ffpc_backend->set ( $key, $data , $exp );
			else
				return false;
			break;
	}
}

/**
 * gets cached element by key
 *
 * @param &$key: key of needed cache element
 *
 */
function wp_ffpc_get( &$key , $uncompress = false ) {
	global $wp_ffpc_config;
	global $wp_ffpc_backend;

	/* syslog */
	wp_ffpc_log ( ' get key: "'.$key . '"' );

	switch ($wp_ffpc_config['cache_type'])
	{
		case 'apc':
			$value = apc_fetch($key);
			if ( $uncompress )
				$value = gzinflate ( $value );
			return $value;
		case 'memcache':
		case 'memcached':
			if ( $wp_ffpc_backend != NULL )
				return $wp_ffpc_backend->get($key);
			else
				return false;
		default:
			return false;
	}
}


/**
 * handles log messages
 *
 * @param $string log messagr
 */
function wp_ffpc_log ( $string ) {
	global $wp_ffpc_config;

	/* syslog */
	if ($wp_ffpc_config['syslog'] && function_exists('syslog') )
		syslog( WP_FFPC_LOG_LEVEL , WP_FFPC_PARAM .": " . $string . WP_FFPC_LOG_TYPE_MSG );

}
?>
