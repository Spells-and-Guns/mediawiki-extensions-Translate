<?php
/**
 * This file contains an unmanaged message group implementation.
 *
 * @file
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @copyright Copyright © 2008-2013, Niklas Laxström, Siebrand Mazeland
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\MessageGroupProcessing\MessageGroups;

/**
 * @since 2011-11-28
 * @ingroup MessageGroup
 */
class RecentMessageGroup extends WikiMessageGroup {
	/*
	 * Yes this is very ugly hack and should not be removed.
	 * @see \MediaWiki\Extension\Translate\MessageLoading\MessageCollection::getPages()
	 */
	protected $namespace = false;
	protected $language;

	/**
	 * These groups are always generated for one language. Method setLanguage
	 * must be called before calling getDefinitions.
	 */
	public function __construct() {
	}

	public function setLanguage( $code ) {
		$this->language = $code;
	}

	public function getId() {
		return '!recent';
	}

	public function getLabel( IContextSource $context = null ) {
		$msg = wfMessage( 'translate-dynagroup-recent-label' );
		$msg = self::addContext( $msg, $context );

		return $msg->plain();
	}

	public function getDescription( IContextSource $context = null ) {
		$msg = wfMessage( 'translate-dynagroup-recent-desc' );
		$msg = self::addContext( $msg, $context );

		return $msg->plain();
	}

	protected function getRCCutoff() {
		$db = wfGetDB( DB_REPLICA );
		$tables = 'recentchanges';
		$max = $db->selectField( $tables, 'MAX(rc_id)', [], __METHOD__ );

		return max( 0, $max - 50000 );
	}

	/**
	 * Allows subclasses to partially customize the query.
	 * @return array
	 */
	protected function getQueryConditions() {
		global $wgTranslateMessageNamespaces;
		$db = wfGetDB( DB_REPLICA );
		$conds = [
			'rc_title ' . $db->buildLike( $db->anyString(), '/' . $this->language ),
			'rc_namespace' => $wgTranslateMessageNamespaces,
			'rc_type != ' . RC_LOG,
			'rc_id > ' . $this->getRCCutoff(),
		];

		return $conds;
	}

	/**
	 * Filters out messages that should not be displayed here
	 * as they are not displayed in other places.
	 *
	 * @param MessageHandle $handle
	 * @return bool
	 */
	protected function matchingMessage( MessageHandle $handle ): bool {
		return MessageGroups::isTranslatableMessage( $handle, $this->language );
	}

	public function getDefinitions() {
		if ( !$this->language ) {
				throw new MWException( 'Language not set' );
		}

		$db = wfGetDB( DB_REPLICA );

		$rcQuery = RecentChange::getQueryInfo();
		$tables = $rcQuery['tables'];
		$joins = $rcQuery['joins'];

		$fields = [ 'rc_namespace', 'rc_title' ];
		$conds = $this->getQueryConditions();
		$options = [
			'ORDER BY' => 'rc_id DESC',
			'LIMIT' => 5000
		];
		$res = $db->select( $tables, $fields, $conds, __METHOD__, $options, $joins );

		$defs = [];
		foreach ( $res as $row ) {
			$title = Title::makeTitle( $row->rc_namespace, $row->rc_title );
			$handle = new MessageHandle( $title );

			if ( !$this->matchingMessage( $handle ) ) {
				continue;
			}

			$messageKey = $handle->getKey();
			$fullKey = $row->rc_namespace . ':' . $messageKey;

			/* Note: due to bugs, getMessage might return null even for
			 * known messages. These negatives are not cached, but that
			 * should be rare enough case to not affect performance. */
			if ( !isset( $defs[$fullKey] ) ) {
				$group = $handle->getGroup();
				$msg = $group->getMessage( $messageKey, $group->getSourceLanguage() );

				if ( $msg !== null ) {
					$defs[$fullKey] = $msg;
				}
			}
		}

		return $defs;
	}

	public function getValidator() {
		return null;
	}

	/**
	 * Subpage language code, if any in the title, is ignored.
	 * @param MessageHandle $handle
	 * @return null|string
	 * @throws MWException
	 */
	public function getMessageContent( MessageHandle $handle ) {
		$groupId = MessageIndex::getPrimaryGroupId( $handle );
		if ( $groupId ) {
			$group = MessageGroups::getGroup( $groupId );
			if ( $group ) {
				return $group->getMessage( $handle->getKey(), $group->getSourceLanguage() );
			}
		}

		throw new MWException( 'Could not find group for ' . $handle->getKey() );
	}
}
