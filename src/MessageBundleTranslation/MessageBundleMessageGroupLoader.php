<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\MessageBundleTranslation;

use CachedMessageGroupLoader;
use MediaWiki\Extension\Translate\MessageGroupProcessing\RevTagStore;
use MessageGroupLoader;
use MessageGroupWANCache;
use Title;
use TranslateMetadata;
use Wikimedia\Rdbms\IDatabase;

/**
 * @since 2021.12
 * @author Niklas Laxström
 * @copyright GPL-2.0-or-later
 */
class MessageBundleMessageGroupLoader extends MessageGroupLoader implements CachedMessageGroupLoader {
	private const CACHE_KEY = 'messageBundle';
	private const CACHE_VERSION = 1;

	protected MessageGroupWANCache $cache;
	protected IDatabase $db;
	/** List of groups */
	protected ?array $groups = null;

	public function __construct( IDatabase $db, MessageGroupWANCache $cache ) {
		$this->db = $db;
		$this->cache = $cache;
		$this->cache->configure(
			[
				'key' => self::CACHE_KEY,
				'version' => self::CACHE_VERSION,
				'regenerator' => function () {
					return $this->getCacheData();
				}
			]
		);
	}

	/** @return MessageBundleMessageGroup[] */
	public function getGroups(): array {
		if ( !isset( $this->groups ) ) {
			$cacheData = $this->cache->getValue();
			$this->groups = $this->initGroupsFromConf( $cacheData );
		}

		return $this->groups;
	}

	public function getCacheData(): array {
		$cacheData = [];
		$tables = [ 'page', 'revtag' ];
		$vars = [ 'page_id', 'page_namespace', 'page_title', 'rt_revision' => 'MAX(rt_revision)' ];
		$conds = [ 'page_id=rt_page', 'rt_type' => RevTagStore::MB_VALID_TAG ];
		$options = [
			'GROUP BY' => 'page_id,page_namespace,page_title'
		];
		$res = $this->db->select( $tables, $vars, $conds, __METHOD__, $options );

		foreach ( $res as $r ) {
			$title = Title::newFromRow( $r );
			$cacheData[] = [
				$title->getPrefixedText(),
				(int)$r->page_id,
				(int)$r->rt_revision,
			];
		}

		return $cacheData;
	}

	/** @return MessageBundleMessageGroup[] */
	private function initGroupsFromConf( array $cacheData ): array {
		$groups = [];
		$groupIds = [];

		// First get all the group ids
		foreach ( $cacheData as $conf ) {
			$groupIds[] = MessageBundleMessageGroup::getGroupId( $conf[0] );
		}

		// Preload all the metadata
		TranslateMetadata::preloadGroups( $groupIds, __METHOD__ );

		// Loop over all the group ids and create the MessageBundleMessageGroup
		foreach ( $groupIds as $index => $groupId ) {
			$conf = $cacheData[$index];
			$description = TranslateMetadata::get( $groupId, 'description' );
			$description = $description !== false ? $description : null;
			$groups[$groupId] = new MessageBundleMessageGroup( $groupId, $conf[0], $conf[1], $conf[2], $description );
		}

		return $groups;
	}

	/** @inheritDoc */
	public function recache(): void {
		$this->groups = null;
		$this->cache->touchKey();

		$cacheData = $this->cache->getValue( 'recache' );
		$this->groups = $this->initGroupsFromConf( $cacheData );
	}

	/** @inheritDoc */
	public function clearCache(): void {
		$this->groups = null;
		$this->cache->delete();
	}
}
