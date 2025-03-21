<?php
/**
 * TTMServer - The Translate extension translation memory interface
 *
 * @file
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 * @ingroup TTMServer
 */

use Elastica\Aggregation\Terms;
use Elastica\Client;
use Elastica\Document;
use Elastica\Exception\ExceptionInterface;
use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\FunctionScore;
use Elastica\Query\MatchQuery;
use Elastica\Query\Term;
use Elastica\ResultSet;
use MediaWiki\Extension\Elastica\MWElasticUtils;
use MediaWiki\Extension\Translate\TranslatorInterface\TranslationHelperException;
use MediaWiki\Extension\Translate\TtmServer\ReadableTtmServer;
use MediaWiki\Extension\Translate\TtmServer\SearchableTtmServer;
use MediaWiki\Extension\Translate\TtmServer\WritableTtmServer;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;

/**
 * TTMServer backed based on ElasticSearch. Depends on Elastica.
 * @since 2014.04
 * @ingroup TTMServer
 */
class ElasticSearchTTMServer
	extends TTMServer
	implements ReadableTtmServer, WritableTtmServer, SearchableTtmServer
{
	/**
	 * @const int in case a write operation fails during a batch process
	 * this constant controls the number of times we will retry the same
	 * operation.
	 */
	private const BULK_INDEX_RETRY_ATTEMPTS = 5;

	/**
	 * @const int time (seconds) to wait for the index to ready before
	 * starting to index. Since we wait for index status it can be relatively
	 * long especially if some nodes are restarted.
	 */
	private const WAIT_UNTIL_READY_TIMEOUT = 3600;

	/** @var Client */
	protected $client;
	/**
	 * Reference to the maintenance script to relay logging output.
	 */
	protected $logger;
	/**
	 * Used for Reindex
	 */
	protected $updateMapping = false;

	public function isLocalSuggestion( array $suggestion ): bool {
		return $suggestion['wiki'] === WikiMap::getCurrentWikiId();
	}

	public function expandLocation( array $suggestion ): string {
		return $suggestion['uri'];
	}

	public function query( string $sourceLanguage, string $targetLanguage, string $text ): array {
		try {
			return $this->doQuery( $sourceLanguage, $targetLanguage, $text );
		} catch ( Exception $e ) {
			throw new TranslationHelperException( 'Elastica exception: ' . $e );
		}
	}

	protected function doQuery( $sourceLanguage, $targetLanguage, $text ) {
		if ( !$this->useWikimediaExtraPlugin() ) {
			// ElasticTTM is currently not compatible with elasticsearch 2.x/5.x
			// It needs FuzzyLikeThis ported via the wmf extra plugin
			throw new RuntimeException( 'The wikimedia extra plugin is mandatory.' );
		}
		/* Two query system:
		 * 1) Find all strings in source language that match text
		 * 2) Do another query for translations for those strings
		 */
		$connection = $this->getClient()->getConnection();
		$oldTimeout = $connection->getTimeout();
		$connection->setTimeout( 10 );

		$fuzzyQuery = new FuzzyLikeThis();
		$fuzzyQuery->setLikeText( $text );
		$fuzzyQuery->addFields( [ 'content' ] );

		$boostQuery = new FunctionScore();
		$boostQuery->addFunction(
			'levenshtein_distance_score',
			[
				'text' => $text,
				'field' => 'content'
			]
		);
		$boostQuery->setBoostMode( FunctionScore::BOOST_MODE_REPLACE );

		// Wrap the fuzzy query so it can be used as a filter.
		// This is slightly faster, as ES can throw away the scores by this query.
		$bool = new BoolQuery();
		$bool->addFilter( $fuzzyQuery );
		$bool->addMust( $boostQuery );

		$languageFilter = new Term();
		$languageFilter->setTerm( 'language', $sourceLanguage );
		$bool->addFilter( $languageFilter );

		// The whole query
		$query = new Query();
		$query->setQuery( $bool );

		// The interface usually displays three best candidates. These might
		// come from more than three source things, if the translations are
		// the same. In other words suggestions are grouped by the suggested
		// translation. This algorithm might not find all suggestions, if the
		// top N best matching source texts don't have equivalent translations
		// in the target language, but worse matches which we did not fetch do.
		// This code tries to balance between doing too many or too big queries
		// and not fetching enough results to show all possible suggestions.
		$sizeFirst = 100;
		$sizeSecond = $sizeFirst * 5;

		$query->setFrom( 0 );
		$query->setSize( $sizeFirst );
		$query->setParam( '_source', [ 'content' ] );
		$cutoff = $this->config['cutoff'] ?? 0.65;
		$query->setParam( 'min_score', $cutoff );
		$query->setSort( [ '_score', 'wiki', 'localid' ] );

		/* This query is doing two unrelated things:
		 * 1) Collect the message contents and scores so that they can
		 *    be accessed later for the translations we found.
		 * 2) Build the query string for the query that fetches the translations.
		 */
		$contents = $scores = $terms = [];
		do {
			$resultset = $this->getIndex()->search( $query );

			if ( count( $resultset ) === 0 ) {
				break;
			}

			foreach ( $resultset->getResults() as $result ) {
				$data = $result->getData();
				$score = $result->getScore();

				$sourceId = preg_replace( '~/[^/]+$~', '', $result->getId() );
				$contents[$sourceId] = $data['content'];
				$scores[$sourceId] = $score;
				$terms[] = "$sourceId/$targetLanguage";
			}

			// Check if it looks like that we are hitting the long tail already.
			// Otherwise, we'll do a query to fetch some more to reach a "sane"
			// breaking point, i.e. include all suggestions with same content
			// for reliable used X times statistics.
			if ( count( array_unique( $scores ) ) > 5 ) {
				break;
			}

			// Okay, We are now in second iteration of the loop. We already got
			// lots of suggestions. We will give up for now even if it means we
			// return in some sense incomplete results.
			if ( count( $resultset ) === $sizeSecond ) {
				break;
			}

			// After the first query, the smallest score is the new threshold.
			// @phan-suppress-next-line PhanPossiblyUndeclaredVariable
			$query->setParam( 'min_score', $score );
			$query->setFrom( $query->getParam( 'size' ) + $query->getParam( 'from' ) );
			$query->setSize( $sizeSecond );

			// Break if we already got all hits
		} while ( $resultset->getTotalHits() > count( $contents ) );

		$suggestions = [];

		// Skip second query if first query found nothing. Keeping only one return
		// statement in this method to avoid forgetting to reset connection timeout
		if ( $terms !== [] ) {
			$idQuery = new Query\Terms( '_id', $terms );

			$query = new Query( $idQuery );
			$query->setSize( 25 );
			$query->setParam( '_source', [ 'wiki', 'uri', 'content', 'localid' ] );
			$resultset = $this->getIndex()->search( $query );

			foreach ( $resultset->getResults() as $result ) {
				$data = $result->getData();

				// Construct the matching source id
				$sourceId = preg_replace( '~/[^/]+$~', '', $result->getId() );

				$suggestions[] = [
					'source' => $contents[$sourceId],
					'target' => $data['content'],
					'context' => $data['localid'],
					'quality' => $scores[$sourceId],
					'wiki' => $data['wiki'],
					'location' => $data['localid'] . '/' . $targetLanguage,
					'uri' => $data['uri'],
				];
			}

			// Ensure results are in quality order
			uasort( $suggestions, static function ( $a, $b ) {
				if ( $a['quality'] === $b['quality'] ) {
					return 0;
				}

				return ( $a['quality'] < $b['quality'] ) ? 1 : -1;
			} );
		}

		$connection->setTimeout( $oldTimeout );

		return $suggestions;
	}

	/* Write functions */

	public function update( MessageHandle $handle, ?string $targetText ): bool {
		if ( !$handle->isValid() || $handle->getCode() === '' ) {
			return false;
		}

		/* There are various different cases here:
		 * [new or updated] [fuzzy|non-fuzzy] [translation|definition]
		 * 1) We don't distinguish between new or updated here.
		 * 2) Delete old translation, but not definition
		 * 3) Insert new translation or definition, if non-fuzzy
		 * The definition should never be fuzzied anyway.
		 *
		 * These only apply to known messages.
		 */

		$sourceLanguage = $handle->getGroup()->getSourceLanguage();

		// Do not delete definitions, because the translations are attached to that
		if ( $handle->getCode() !== $sourceLanguage ) {
			$localid = $handle->getTitleForBase()->getPrefixedText();
			$this->deleteByQuery( $this->getIndex(), Query::create(
				( new BoolQuery() )
				->addFilter( new Term( [ 'wiki' => WikiMap::getCurrentWikiId() ] ) )
				->addFilter( new Term( [ 'language' => $handle->getCode() ] ) )
				->addFilter( new Term( [ 'localid' => $localid ] ) ) ) );
		}

		// If translation was made fuzzy, we do not need to add anything
		if ( $targetText === null ) {
			return true;
		}

		// source language is null, skip doing rest of the stuff
		if ( $sourceLanguage === null ) {
			return true;
		}

		$revId = $handle->getTitleForLanguage( $sourceLanguage )->getLatestRevID();
		$doc = $this->createDocument( $handle, $targetText, $revId );
		$fname = __METHOD__;

		MWElasticUtils::withRetry( self::BULK_INDEX_RETRY_ATTEMPTS,
			function () use ( $doc ) {
				$this->getIndex()->addDocuments( [ $doc ] );
			},
			static function ( $e, $errors ) use ( $fname ) {
				$c = get_class( $e );
				$msg = $e->getMessage();
				error_log( $fname . ": update failed ($c: $msg); retrying." );
				sleep( 10 );
			}
		);

		return true;
	}

	/**
	 * @param MessageHandle $handle
	 * @param string $text
	 * @param int $revId
	 * @return Document
	 */
	protected function createDocument( MessageHandle $handle, $text, $revId ) {
		$language = $handle->getCode();

		$localid = $handle->getTitleForBase()->getPrefixedText();
		$wiki = WikiMap::getCurrentWikiId();
		$globalid = "$wiki-$localid-$revId/$language";

		$data = [
			'wiki' => $wiki,
			'uri' => $handle->getTitle()->getCanonicalURL(),
			'localid' => $localid,
			'language' => $language,
			'content' => $text,
			'group' => $handle->getGroupIds(),
		];

		return new Document( $globalid, $data, '_doc' );
	}

	/**
	 * Create index
	 * @param bool $rebuild Deletes index first if already exists
	 */
	public function createIndex( $rebuild ) {
		$indexSettings = [
			'settings' => [
				'index' => [
					'number_of_shards' => $this->getShardCount(),
					'analysis' => [
						'filter' => [
							'prefix_filter' => [
								'type' => 'edge_ngram',
								'min_gram' => 2,
								'max_gram' => 20
							]
						],
						'analyzer' => [
							'prefix' => [
								'type' => 'custom',
								'tokenizer' => 'standard',
								'filter' => [ 'lowercase', 'prefix_filter' ]
							],
							'casesensitive' => [
								'tokenizer' => 'standard'
							]
						]
					]
				],
			],
		];
		$replicas = $this->getReplicaCount();
		if ( strpos( $replicas, '-' ) === false ) {
			$indexSettings['settings']['index']['number_of_replicas'] = $replicas;
		} else {
			$indexSettings['settings']['index']['auto_expand_replicas'] = $replicas;
		}

		$this->getIndex()->create( $indexSettings, $rebuild );
	}

	/**
	 * Begin the bootstrap process.
	 *
	 * @throws RuntimeException
	 */
	public function beginBootstrap(): void {
		$this->checkElasticsearchVersion();
		$index = $this->getIndex();
		if ( $this->updateMapping ) {
			$this->logOutput( 'Updating the index mappings...' );
			$this->createIndex( true );
		} elseif ( !$index->exists() ) {
			$this->createIndex( false );
		}

		$settings = $index->getSettings();
		$settings->setRefreshInterval( '-1' );

		$this->deleteByQuery( $this->getIndex(), Query::create(
			( new Term() )->setTerm( 'wiki', WikiMap::getCurrentWikiId() ) ) );

		$properties = [
			'wiki' => [ 'type' => 'keyword' ],
			'localid' => [ 'type' => 'keyword' ],
			'uri' => [ 'type' => 'keyword' ],
			'language' => [ 'type' => 'keyword' ],
			'group' => [ 'type' => 'keyword' ],
			'content' => [
				'type' => 'text',
				'fields' => [
					'content' => [
						'type' => 'text',
						'term_vector' => 'yes'
					],
					'prefix_complete' => [
						'type' => 'text',
						'analyzer' => 'prefix',
						'search_analyzer' => 'standard',
						'term_vector' => 'yes'
					],
					'case_sensitive' => [
						'type' => 'text',
						'analyzer' => 'casesensitive',
						'term_vector' => 'yes'
					]
				]
			],
		];
		if ( $this->useElastica6() ) {
			// Elastica 6 support
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			$mapping = new \Elastica\Type\Mapping();
			// @phan-suppress-next-line PhanUndeclaredMethod, PhanUndeclaredClassMethod
			$mapping->setType( $index->getType( '_doc' ) );
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			$mapping->setProperties( $properties );
			// @phan-suppress-next-line PhanUndeclaredClassMethod
			$mapping->send( [ 'include_type_name' => 'true' ] );
		} else {
			// Elastica 7
			$mapping = new \Elastica\Mapping( $properties );
			$mapping->send( $index, [ 'include_type_name' => 'false' ] );
		}

		$this->waitUntilReady();
	}

	public function beginBatch(): void {
	}

	/**
	 * @param array[] $batch
	 * @phan-param array<int,array{0:MessageHandle,1:string,2:string}> $batch
	 */
	public function batchInsertDefinitions( array $batch ): void {
		$lb = MediaWikiServices::getInstance()->getLinkBatchFactory()->newLinkBatch();
		foreach ( $batch as $data ) {
			$lb->addObj( $data[0]->getTitle() );
		}
		$lb->execute();

		$this->batchInsertTranslations( $batch );
	}

	public function batchInsertTranslations( array $batch ): void {
		$docs = [];
		foreach ( $batch as $data ) {
			[ $handle, $sourceLanguage, $text ] = $data;
			$revId = $handle->getTitleForLanguage( $sourceLanguage )->getLatestRevID();
			$docs[] = $this->createDocument( $handle, $text, $revId );
		}

		MWElasticUtils::withRetry( self::BULK_INDEX_RETRY_ATTEMPTS,
			function () use ( $docs ) {
				$this->getIndex()->addDocuments( $docs );
			},
			function ( $e, $errors ) {
				$c = get_class( $e );
				$msg = $e->getMessage();
				$this->logOutput( "Batch failed ($c: $msg), trying again in 10 seconds" );
				sleep( 10 );
			}
		);
	}

	public function endBatch(): void {
	}

	public function endBootstrap(): void {
		$index = $this->getIndex();
		$index->refresh();
		$index->forcemerge();
		$index->getSettings()->setRefreshInterval( '5s' );
	}

	public function getClient() {
		if ( !$this->client ) {
			if ( isset( $this->config['config'] ) ) {
				$this->client = new Client( $this->config['config'] );
			} else {
				$this->client = new Client();
			}
		}
		return $this->client;
	}

	/** @return true if the backend is configured with the wikimedia extra plugin */
	public function useWikimediaExtraPlugin() {
		return isset( $this->config['use_wikimedia_extra'] ) && $this->config['use_wikimedia_extra'];
	}

	/** @return string */
	private function getIndexName() {
		return $this->config['index'] ?? 'ttmserver';
	}

	public function getIndex() {
		return $this->getClient()
			->getIndex( $this->getIndexName() );
	}

	protected function getShardCount() {
		return $this->config['shards'] ?? 1;
	}

	protected function getReplicaCount() {
		return $this->config['replicas'] ?? '0-2';
	}

	/**
	 * Get index health
	 * TODO: Remove this code in the future as we drop support for
	 * older versions of the Elastica extension.
	 *
	 * @param string $indexName
	 * @return array the index health status
	 */
	protected function getIndexHealth( $indexName ) {
		$path = "_cluster/health/$indexName";
		$response = $this->getClient()->request( $path );
		if ( $response->hasError() ) {
			throw new Exception( "Error while fetching index health status: " . $response->getError() );
		}
		return $response->getData();
	}

	/**
	 * Wait for the index to go green
	 *
	 * NOTE: This method has been copied and adjusted from
	 * CirrusSearch/includes/Maintenance/ConfigUtils.php. Ideally we'd
	 * like to make these utility methods available in the Elastica
	 * extension, but this one requires some refactoring in cirrus first.
	 * TODO: Remove this code in the future as we drop support for
	 * older versions of the Elastica extension.
	 *
	 * @param string $indexName
	 * @param int $timeout
	 * @return bool true if the index is green false otherwise.
	 */
	protected function waitForGreen( $indexName, $timeout ) {
		$startTime = time();
		while ( ( $startTime + $timeout ) > time() ) {
			try {
				$response = $this->getIndexHealth( $indexName );
				$status = $response['status'] ?? 'unknown';
				if ( $status === 'green' ) {
					$this->logOutput( "\tGreen!" );
					return true;
				}
				$this->logOutput( "\tIndex is $status retrying..." );
				sleep( 5 );
			} catch ( Exception $e ) {
				$this->logOutput( "Error while waiting for green ({$e->getMessage()}), retrying..." );
			}
		}
		return false;
	}

	protected function waitUntilReady() {
		$statuses = MWElasticUtils::waitForGreen(
			$this->getClient(),
			$this->getIndexName(),
			self::WAIT_UNTIL_READY_TIMEOUT );
		$this->logOutput( "Waiting for the index to go green..." );
		foreach ( $statuses as $message ) {
			$this->logOutput( $message );
		}

		if ( !$statuses->getReturn() ) {
			die( "Timeout! Please check server logs for {$this->getIndexName()}." );
		}
	}

	public function setLogger( $logger ) {
		$this->logger = $logger;
	}

	// Can it get any uglier?
	protected function logOutput( $text ) {
		if ( $this->logger ) {
			$this->logger->statusLine( "$text\n" );
		}
	}

	public function setDoReIndex(): void {
		$this->updateMapping = true;
	}

	/**
	 * Parse query string and build the search query
	 * @param string $queryString
	 * @param array $opts
	 * @return array
	 */
	protected function parseQueryString( $queryString, array $opts ) {
		$fields = $highlights = [];
		$terms = preg_split( '/\s+/', $queryString );
		$match = $opts['match'];
		$case = $opts['case'];

		// Map each word in the query string with its corresponding field
		foreach ( $terms as $term ) {
			$prefix = strstr( $term, '*', true );
			if ( $prefix ) {
				// For wildcard search
				$fields['content.prefix_complete'][] = $prefix;
			} elseif ( $case === '1' ) {
				// For case sensitive search
				$fields['content.case_sensitive'][] = $term;
			} else {
				$fields['content'][] = $term;
			}
		}

		// Allow searching either by message content or message id (page name
		// without language subpage) with exact match only.
		$searchQuery = new BoolQuery();
		foreach ( $fields as $analyzer => $words ) {
			foreach ( $words as $word ) {
				$boolQuery = new BoolQuery();
				$contentQuery = new MatchQuery();
				$contentQuery->setFieldQuery( $analyzer, $word );
				$boolQuery->addShould( $contentQuery );
				$messageQuery = new Term();
				$messageQuery->setTerm( 'localid', $word );
				$boolQuery->addShould( $messageQuery );

				if ( $match === 'all' ) {
					$searchQuery->addMust( $boolQuery );
				} else {
					$searchQuery->addShould( $boolQuery );
				}

				// Fields for highlighting
				$highlights[$analyzer] = [
					'number_of_fragments' => 0
				];

				// Allow searching by exact message title (page name with
				// language subpage).
				$title = Title::newFromText( $word );
				if ( !$title ) {
					continue;
				}
				$handle = new MessageHandle( $title );
				if ( $handle->isValid() && $handle->getCode() !== '' ) {
					$localid = $handle->getTitleForBase()->getPrefixedText();
					$boolQuery = new BoolQuery();
					$messageId = new Term();
					$messageId->setTerm( 'localid', $localid );
					$boolQuery->addMust( $messageId );
					$searchQuery->addShould( $boolQuery );
				}
			}
		}

		return [ $searchQuery, $highlights ];
	}

	/**
	 * Search interface
	 * @param string $queryString
	 * @param array $opts
	 * @param array $highlight
	 * @return \Elastica\Search
	 */
	public function createSearch( $queryString, $opts, $highlight ) {
		$query = new Query();

		[ $searchQuery, $highlights ] = $this->parseQueryString( $queryString, $opts );
		$query->setQuery( $searchQuery );

		$language = new Terms( 'language' );
		$language->setField( 'language' );
		$language->setSize( 500 );
		$query->addAggregation( $language );

		$group = new Terms( 'group' );
		$group->setField( 'group' );
		// Would like to prioritize the top level groups and not show subgroups
		// if the top group has only few hits, but that doesn't seem to be possile.
		$group->setSize( 500 );
		$query->addAggregation( $group );

		$query->setSize( $opts['limit'] );
		$query->setFrom( $opts['offset'] );

		// BoolAnd filters are executed in sequence per document. Bool filters with
		// multiple must clauses are executed by converting each filter into a bit
		// field then anding them together. The latter is normally faster if either
		// of the subfilters are reused. May not make a difference in this context.
		$filters = new BoolQuery();

		$language = $opts['language'];
		if ( $language !== '' ) {
			$languageFilter = new Term();
			$languageFilter->setTerm( 'language', $language );
			$filters->addFilter( $languageFilter );
		}

		$group = $opts['group'];
		if ( $group !== '' ) {
			$groupFilter = new Term();
			$groupFilter->setTerm( 'group', $group );
			$filters->addFilter( $groupFilter );
		}

		// Check that we have at least one filter to avoid invalid query errors.
		if ( $language !== '' || $group !== '' ) {
			// TODO: This seems wrong, but perhaps for aggregation purposes?
			// should make $search a must clause and use the bool query
			// as main.
			$query->setPostFilter( $filters );
		}

		[ $pre, $post ] = $highlight;
		$query->setHighlight( [
			// The value must be an object
			'pre_tags' => [ $pre ],
			'post_tags' => [ $post ],
			'fields' => $highlights,
		] );

		return $this->getIndex()->createSearch( $query );
	}

	/**
	 * Search interface
	 * @param string $queryString
	 * @param array $opts
	 * @param array $highlight
	 * @throws TTMServerException
	 * @return ResultSet
	 */
	public function search( $queryString, $opts, $highlight ) {
		$search = $this->createSearch( $queryString, $opts, $highlight );

		try {
			return $search->search();
		} catch ( ExceptionInterface $e ) {
			throw new TTMServerException( $e->getMessage() );
		}
	}

	/** @inheritDoc */
	public function getFacets( $resultset ): array {
		$this->assertResultSetInstance( $resultset );
		$aggs = $resultset->getAggregations();
		'@phan-var array[][][] $aggs';

		$ret = [
			'language' => [],
			'group' => []
		];

		foreach ( $aggs as $type => $info ) {
			foreach ( $info['buckets'] as $row ) {
				$ret[$type][$row['key']] = $row['doc_count'];
			}
		}

		return $ret;
	}

	/** @inheritDoc */
	public function getTotalHits( $resultset ): int {
		$this->assertResultSetInstance( $resultset );
		return $resultset->getTotalHits();
	}

	/** @inheritDoc */
	public function getDocuments( $resultset ): array {
		$this->assertResultSetInstance( $resultset );
		$ret = [];
		foreach ( $resultset->getResults() as $document ) {
			$data = $document->getData();
			$hl = $document->getHighlights();
			if ( isset( $hl['content.prefix_complete'][0] ) ) {
				$data['content'] = $hl['content.prefix_complete'][0];
			} elseif ( isset( $hl['content.case_sensitive'][0] ) ) {
				$data['content'] = $hl['content.case_sensitive'][0];
			} elseif ( isset( $hl['content'][0] ) ) {
				$data['content'] = $hl['content'][0];
			}
			$ret[] = $data;
		}

		return $ret;
	}

	/**
	 * Delete docs by query by using the scroll API.
	 * TODO: Elastica\Index::deleteByQuery() ? was removed
	 *  in 2.x and returned in 5.x.
	 *
	 * @param \Elastica\Index $index the source index
	 * @param Query $query
	 * @throws RuntimeException
	 */
	private function deleteByQuery( \Elastica\Index $index, Query $query ) {
		try {
			MWElasticUtils::deleteByQuery( $index, $query, /* $allowConflicts = */ true );
		} catch ( Exception $e ) {
			LoggerFactory::getInstance( 'ElasticSearchTTMServer' )->error(
				'Problem encountered during deletion.',
				[ 'exception' => $e ]
			);

			throw new RuntimeException( "Problem encountered during deletion.\n" . $e );
		}
	}

	/* @throws RuntimeException */
	private function getElasticsearchVersion(): string {
		$response = $this->getClient()->request( '' );
		if ( !$response->isOK() ) {
			throw new \RuntimeException( "Cannot fetch elasticsearch version: " . $response->getError() );
		}

		$result = $response->getData();
		if ( !isset( $result['version']['number'] ) ) {
			throw new \RuntimeException( 'Unable to determine elasticsearch version, aborting.' );
		}

		return $result[ 'version' ][ 'number' ];
	}

	private function checkElasticsearchVersion() {
		$version = $this->getElasticsearchVersion();
		if ( strpos( $version, '6.8' ) !== 0 && strpos( $version, '7.' ) !== 0 ) {
			throw new \RuntimeException( "Only Elasticsearch 6.8.x and 7.x are supported. Your version: $version." );
		}
	}

	private function useElastica6(): bool {
		return class_exists( '\Elastica\Type' );
	}

	private function assertResultSetInstance( $resultset ): void {
		if ( $resultset instanceof ResultSet ) {
			return;
		}

		throw new RuntimeException(
			"Expected resultset to be an instance of " . ResultSet::class
		);
	}
}
