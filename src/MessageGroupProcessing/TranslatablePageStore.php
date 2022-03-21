<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\MessageGroupProcessing;

use AggregateMessageGroup;
use InvalidArgumentException;
use JobQueueGroup;
use MediaWiki\Revision\RevisionRecord;
use MessageGroups;
use MessageIndex;
use Title;
use TranslatablePage;
use TranslateMetadata;
use TranslationsUpdateJob;

/**
 * @author Abijeet Patro
 * @author Niklas Laxström
 * @since 2022.03
 * @license GPL-2.0-or-later
 */
class TranslatablePageStore implements TranslatableBundleStore {
	/** @var MessageIndex */
	private $messageIndex;
	/** @var JobQueueGroup */
	private $jobQueue;
	/** @var RevTagStore */
	private $revTagStore;

	public function __construct( MessageIndex $messageIndex, JobQueueGroup $jobQueue, RevTagStore $revTagStore ) {
		$this->messageIndex = $messageIndex;
		$this->jobQueue = $jobQueue;
		$this->revTagStore = $revTagStore;
	}

	public function move( Title $oldName, Title $newName ): void {
		$oldTranslatablePage = TranslatablePage::newFromTitle( $oldName );
		$newTranslatablePage = TranslatablePage::newFromTitle( $newName );

		$this->moveMetadata(
			$oldTranslatablePage->getMessageGroupId(),
			$newTranslatablePage->getMessageGroupId()
		);

		TranslatablePage::clearSourcePageCache();

		// Re-render the pages to get everything in sync
		MessageGroups::singleton()->recache();
		// Update message index now so that, when after this job the MoveTranslationUnits hook
		// runs in deferred updates, it will not run MessageIndexRebuildJob (T175834).
		$this->messageIndex->rebuild();

		$job = TranslationsUpdateJob::newFromPage( TranslatablePage::newFromTitle( $newName ) );
		$this->jobQueue->push( $job );
	}

	public function handleNullRevisionInsert( TranslatableBundle $bundle, RevisionRecord $revision ): void {
		if ( !$bundle instanceof TranslatablePage ) {
			throw new InvalidArgumentException(
				'Expected $bundle to be of type TranslatablePage, got ' . get_class( $bundle )
			);
		}

		$this->revTagStore->addTag( $bundle->getTitle(), 'tp:tag', $revision->getId() );
		TranslatablePage::clearSourcePageCache();
	}

	private function moveMetadata( string $oldGroupId, string $newGroupId ): void {
		TranslateMetadata::preloadGroups( [ $oldGroupId, $newGroupId ], __METHOD__ );
		foreach ( TranslatablePage::METADATA_KEYS as $type ) {
			$value = TranslateMetadata::get( $oldGroupId, $type );
			if ( $value !== false ) {
				TranslateMetadata::set( $oldGroupId, $type, false );
				TranslateMetadata::set( $newGroupId, $type, $value );
			}
		}

		// Make the changes in aggregate groups metadata, if present in any of them.
		$aggregateGroups = MessageGroups::getGroupsByType( AggregateMessageGroup::class );
		TranslateMetadata::preloadGroups( array_keys( $aggregateGroups ), __METHOD__ );

		foreach ( $aggregateGroups as $id => $group ) {
			$subgroups = TranslateMetadata::get( $id, 'subgroups' );
			if ( $subgroups === false ) {
				continue;
			}

			$subgroups = explode( ',', $subgroups );
			$subgroups = array_flip( $subgroups );
			if ( isset( $subgroups[$oldGroupId] ) ) {
				$subgroups[$newGroupId] = $subgroups[$oldGroupId];
				unset( $subgroups[$oldGroupId] );
				$subgroups = array_flip( $subgroups );
				TranslateMetadata::set(
					$group->getId(),
					'subgroups',
					implode( ',', $subgroups )
				);
			}
		}

		// Move discouraged status
		$priority = MessageGroups::getPriority( $oldGroupId );
		if ( $priority !== '' ) {
			MessageGroups::setPriority( $newGroupId, $priority );
			MessageGroups::setPriority( $oldGroupId, '' );
		}
	}
}
