<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\TtmServer;

use DatabaseTTMServer;
use FakeTTMServer;
use InvalidArgumentException;
use RemoteTTMServer;
use TTMServer;

/**
 * @since 2021.01
 * @license GPL-2.0-or-later
 * @author Niklas Laxström
 */
class TtmServerFactory {
	private array $configs;
	private ?string $default;
	private const TTMSERVER_CLASSES = [
		ReadableTtmServer::class,
		WritableTtmServer::class,
		SearchableTtmServer::class
	];

	/** @see https://www.mediawiki.org/wiki/Help:Extension:Translate/Translation_memories#Configuration */
	public function __construct( array $configs, ?string $default = null ) {
		$this->configs = $configs;
		$this->default = $default;
	}

	/** @return string[] */
	public function getNames(): array {
		$ttmServersIds = [];
		foreach ( $this->configs as $serviceId => $config ) {
			$type = $config['type'] ?? '';
			if ( $type === 'ttmserver' || $type === 'remote-ttmserver' ) {
				$ttmServersIds[] = $serviceId;
			}

			// Translation memory configuration may not define a type, in such
			// cases we determine whether the service is a TTM server using the
			// interfaces it implements.
			$serviceClass = $config['class'] ?? null;
			if ( $serviceClass !== null ) {
				foreach ( self::TTMSERVER_CLASSES as $ttmClass ) {
					if ( $serviceClass instanceof $ttmClass ) {
						$ttmServersIds[] = $serviceId;
						break;
					}
				}
			}
		}
		return $ttmServersIds;
	}

	public function has( string $name ): bool {
		$ttmServersIds = $this->getNames();
		return in_array( $name, $ttmServersIds );
	}

	public function create( string $name ): TTMServer {
		if ( !$this->has( $name ) ) {
			throw new ServiceCreationFailure( "No configuration for name '$name'" );
		}

		$config = $this->configs[$name];
		if ( !is_array( $config ) ) {
			throw new ServiceCreationFailure( "Invalid configuration for name '$name'" );
		}

		if ( isset( $config['class'] ) ) {
			$class = $config['class'];
			return new $class( $config );
		} elseif ( isset( $config['type'] ) ) {
			$type = $config['type'];
			switch ( $type ) {
				case 'ttmserver':
					return new DatabaseTTMServer( $config );
				case 'remote-ttmserver':
					return new RemoteTTMServer( $config );
				default:
					throw new ServiceCreationFailure( "Unknown type for name '$name': $type" );
			}
		}

		throw new ServiceCreationFailure( "Invalid configuration for name '$name': type not specified" );
	}

	/** Return the primary service or a no-op fallback if primary cannot be constructed. */
	public function getDefault(): WritableTtmServer {
		$service = null;

		try {
			if ( $this->default !== null ) {
				$service = $this->create( $this->default );
			}
		} catch ( ServiceCreationFailure $e ) {
		}

		if ( $service instanceof WritableTtmServer ) {
			return $service;
		}

		return new FakeTTMServer();
	}

	/**
	 * Returns writable servers if configured, else returns the default TtmServer with its mirrors,
	 * else returns null.
	 * @return array [ serverId => WritableTtmServer ]
	 */
	public function getWritable(): array {
		$writableServers = $readOnlyServers = [];
		$ttmServerIds = $this->getNames();

		foreach ( $ttmServerIds as $serverId ) {
			$isWritable = $this->configs[ $serverId ][ 'writable' ] ?? null;
			$mirrors = $this->configs[ $serverId ][ 'mirrors' ] ?? null;

			if ( $mirrors !== null ) {
				if ( $isWritable !== null || $writableServers !== [] ) {
					throw new InvalidArgumentException(
						"TTM server configurations cannot use both writable and mirrors parameter."
					);
				}
			}

			if ( $isWritable ) {
				if ( $serverId === $this->default ) {
					throw new InvalidArgumentException(
						"Default TTM server {$this->default} cannot be writable"
					);
				}

				$server = $this->create( $serverId );
				if ( !$server instanceof WritableTtmServer ) {
					throw new InvalidArgumentException(
						"Server '$serverId' marked writable does not implement WritableTtmServer interface"
					);
				}
				$writableServers[ $serverId ] = $server;
			} elseif ( $isWritable === false ) {
				$readOnlyServers[] = $serverId;
			}
		}

		if ( $writableServers ) {
			return $writableServers;
		}

		// If there are no writable server, check and use the default server and its mirrors
		if ( $this->default ) {
			$mirrorIds = [];
			$defaultTtmServer = $this->create( $this->default );

			if ( $defaultTtmServer instanceof WritableTtmServer ) {
				if ( !in_array( $this->default, $readOnlyServers ) ) {
					$writableServers[ $this->default ] = $defaultTtmServer;
				}
				$mirrorIds = $defaultTtmServer->getMirrors();
			}

			foreach ( $mirrorIds as $id ) {
				if ( !in_array( $id, $readOnlyServers ) ) {
					$writableServers[ $id ] = $writableServers[ $id ] ?? $this->create( $id );
				}
			}

			if ( $writableServers ) {
				return $writableServers;
			}
		}

		// Did not find any writable servers.
		return [];
	}
}
