<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\TranslatorInterface\Aid;

use Exception;
use IContextSource;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\Extension\Translate\TranslatorInterface\TranslationHelperException;
use MediaWiki\Extension\Translate\TtmServer\ReadableTtmServer;
use MediaWiki\Extension\Translate\TtmServer\TtmServerFactory;
use MediaWiki\Extension\Translate\TtmServer\WritableTtmServer;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\Extension\Translate\WebService\RemoteTTMServerWebService;
use MediaWiki\Extension\Translate\WebService\TranslationWebService;
use MessageGroup;
use MessageHandle;
use Title;
use TTMServer;

/**
 * Translation aid that provides suggestion from translation memory.
 * @ingroup TranslationAids
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @since 2013-01-01 | 2015.02 extends QueryAggregatorAwareTranslationAid
 */
class TTMServerAid extends QueryAggregatorAwareTranslationAid {
	/** @var array[] */
	private $services;
	/** @var TtmServerFactory */
	private $ttmServerFactory;

	public function __construct(
		MessageGroup $group,
		MessageHandle $handle,
		IContextSource $context,
		TranslationAidDataProvider $dataProvider
	) {
		parent::__construct( $group, $handle, $context, $dataProvider );
		$this->ttmServerFactory = Services::getInstance()->getTtmServerFactory();
	}

	public function populateQueries(): void {
		$text = $this->dataProvider->getDefinition();
		$from = $this->group->getSourceLanguage();
		$to = $this->handle->getCode();

		if ( trim( $text ) === '' ) {
			return;
		}

		foreach ( $this->getWebServices() as $service ) {
			$this->storeQuery( $service, $from, $to, $text );
		}
	}

	public function getData(): array {
		$text = $this->dataProvider->getDefinition();
		if ( trim( $text ) === '' ) {
			return [];
		}

		$suggestions = [];
		$from = $this->group->getSourceLanguage();
		$to = $this->handle->getCode();

		foreach ( $this->getInternalServices() as $name => $service ) {
			try {
				$queryData = $service->query( $from, $to, $text );
			} catch ( TranslationHelperException $e ) {
				throw $e;
			} catch ( Exception $e ) {
				// Not ideal to catch all exceptions
				continue;
			}

			$sugs = $this->formatInternalSuggestions( $queryData, $service, $name, $from );
			$suggestions = array_merge( $suggestions, $sugs );
		}

		// Results from web services
		foreach ( $this->getQueryData() as $queryData ) {
			$sugs = $this->formatWebSuggestions( $queryData );
			$suggestions = array_merge( $suggestions, $sugs );
		}

		$suggestions = TTMServer::sortSuggestions( $suggestions );
		// Must be here to not mess up the sorting function
		$suggestions['**'] = 'suggestion';

		return $suggestions;
	}

	protected function formatWebSuggestions( array $queryData ): array {
		$service = $queryData['service'];
		$response = $queryData['response'];
		$sourceLanguage = $queryData['language'];
		$sourceText = $queryData['text'];

		// getResultData returns a null on failure instead of throwing an exception
		$items = $service->getResultData( $response );
		if ( $items === null ) {
			return [];
		}

		$localPrefix = Title::makeTitle( NS_MAIN, '' )->getFullURL( '', false, PROTO_CANONICAL );
		$localPrefixLength = strlen( $localPrefix );

		foreach ( $items as &$item ) {
			$local = strncmp( $item['uri'], $localPrefix, $localPrefixLength ) === 0;
			$item = array_merge( $item, [
				'service' => $service->getName(),
				'source_language' => $sourceLanguage,
				'source' => $sourceText,
				'local' => $local,
			] );

			// TtmServerActionApi expands this... need to fix it again to be the bare name
			if ( $local ) {
				$pagename = urldecode( substr( $item['location'], $localPrefixLength ) );
				$item['location'] = $pagename;
				$handle = new MessageHandle( Title::newFromText( $pagename ) );
				$item['editorUrl'] = Utilities::getEditorUrl( $handle );
			}
		}
		return $items;
	}

	protected function formatInternalSuggestions(
		array $queryData,
		ReadableTtmServer $s,
		string $serviceName,
		string $sourceLanguage
	): array {
		$items = [];

		foreach ( $queryData as $item ) {
			$local = $s->isLocalSuggestion( $item );

			$item['service'] = $serviceName;
			$item['source_language'] = $sourceLanguage;
			$item['local'] = $local;
			// Likely only needed for non-public DatabaseTtmServer
			$item['uri'] = $item['uri'] ?? $s->expandLocation( $item );
			if ( $local ) {
				$handle = new MessageHandle( Title::newFromText( $item[ 'location' ] ) );
				$item['editorUrl'] = Utilities::getEditorUrl( $handle );
			}
			$items[] = $item;
		}

		return $items;
	}

	/** @return ReadableTtmServer[] */
	private function getInternalServices(): array {
		$services = $this->getQueryableServices();
		foreach ( $services as $name => $config ) {
			if ( $config['type'] === 'ttmserver' ) {
				$services[$name] = $this->ttmServerFactory->create( $name );
			} else {
				unset( $services[$name] );
			}
		}

		return $services;
	}

	/** @return RemoteTTMServerWebService[] */
	private function getWebServices(): array {
		$services = $this->getQueryableServices();
		foreach ( $services as $name => $config ) {
			if ( $config['type'] === 'remote-ttmserver' ) {
				$services[$name] = TranslationWebService::factory( $name, $config );
			} else {
				unset( $services[$name] );
			}
		}

		return $services;
	}

	private function getQueryableServices(): array {
		if ( !$this->services ) {
			global $wgTranslateTranslationServices;
			$this->services = $this->getQueryableServicesUncached(
				$wgTranslateTranslationServices );
		}

		return $this->services;
	}

	private function getQueryableServicesUncached( array $services ): array {
		// First remove mirrors of the default service
		$primary = $this->ttmServerFactory->getDefaultForQuerying();
		if ( $primary instanceof WritableTtmServer ) {
			$mirrors = $primary->getMirrors();
			foreach ( $mirrors as $mirrorName ) {
				unset( $services[$mirrorName] );
			}
		}

		// Remove writable services
		$writableServices = $this->ttmServerFactory->getWriteOnly();
		foreach ( array_keys( $writableServices ) as $serviceId ) {
			unset( $services[ $serviceId ] );
		}

		// Then remove non-ttmservers
		foreach ( $services as $name => $config ) {
			$type = $config['type'];
			if ( $type !== 'ttmserver' && $type !== 'remote-ttmserver' ) {
				unset( $services[$name] );
			}
		}

		// Then determine the query method. Prefer HTTP queries that can be run parallel.
		foreach ( $services as $name => &$config ) {
			$public = $config['public'] ?? false;
			if ( $config['type'] === 'ttmserver' && $public ) {
				$config['type'] = 'remote-ttmserver';
				$config['service'] = $name;
				$config['url'] = wfExpandUrl( wfScript( 'api' ), PROTO_CANONICAL );
			}
		}

		return $services;
	}
}
