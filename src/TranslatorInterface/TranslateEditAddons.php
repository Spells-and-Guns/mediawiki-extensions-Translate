<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\TranslatorInterface;

use DeferredUpdates;
use MediaWiki\Extension\Translate\MessageGroupProcessing\RevTagStore;
use MediaWiki\Extension\Translate\MessageLoading\FatMessage;
use MediaWiki\Extension\Translate\PageTranslation\Hooks as PageTranslationHooks;
use MediaWiki\Extension\Translate\Services;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\User\UserIdentity;
use MessageGroupStatesUpdaterJob;
use MessageGroupStats;
use MessageHandle;
use ParserOptions;
use TextContent;
use Title;
use TTMServer;
use User;
use WikiPage;

/**
 * Various editing enhancements to the edit page interface.
 * Partly succeeded by the new ajax-enhanced editor but kept for compatibility.
 * Also has code that is still relevant, like the hooks on save.
 *
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @license GPL-2.0-or-later
 */
class TranslateEditAddons {
	/**
	 * Prevent translations to non-translatable languages for the group
	 * Hook: getUserPermissionsErrorsExpensive
	 *
	 * @param Title $title
	 * @param User $user
	 * @param string $action
	 * @param mixed &$result
	 */
	public static function disallowLangTranslations(
		Title $title,
		User $user,
		string $action,
		&$result
	): bool {
		if ( $action !== 'edit' ) {
			return true;
		}

		$handle = new MessageHandle( $title );
		if ( !$handle->isValid() ) {
			return true;
		}

		if ( $user->isAllowed( 'translate-manage' ) ) {
			return true;
		}

		$group = $handle->getGroup();
		$languages = $group->getTranslatableLanguages();
		$langCode = $handle->getCode();
		if ( $languages !== null && $langCode && !isset( $languages[$langCode] ) ) {
			$result = [ 'translate-language-disabled' ];
			return false;
		}

		$groupId = $group->getId();
		$checks = [
			$groupId,
			strtok( $groupId, '-' ),
			'*'
		];

		$disabledLanguages = Services::getInstance()->getConfigHelper()->getDisabledTargetLanguages();
		foreach ( $checks as $check ) {
			if ( isset( $disabledLanguages[$check][$langCode] ) ) {
				$reason = $disabledLanguages[$check][$langCode];
				$result = [ 'translate-page-disabled', $reason ];
				return false;
			}
		}

		return true;
	}

	/**
	 * Runs message checks, adds tp:transver tags and updates statistics.
	 * Hook: PageSaveComplete
	 */
	public static function onSaveComplete(
		WikiPage $wikiPage,
		UserIdentity $userIdentity,
		string $summary,
		int $flags,
		RevisionRecord $revisionRecord
	): void {
		global $wgEnablePageTranslation;

		$content = $wikiPage->getContent();

		if ( !$content instanceof TextContent ) {
			// Screw it, not interested
			return;
		}

		$text = $content->getText();
		$title = $wikiPage->getTitle();
		$handle = new MessageHandle( $title );

		if ( !$handle->isValid() ) {
			return;
		}

		// Update it.
		$revId = $revisionRecord->getId();

		$fuzzy = self::checkNeedsFuzzy( $handle, $text );
		self::updateFuzzyTag( $title, $revId, $fuzzy );

		$group = $handle->getGroup();
		// Update translation stats - source language should always be up to date
		if ( $handle->getCode() !== $group->getSourceLanguage() ) {
			// This will update in-process cache immediately, but the value is saved
			// to the database in a deferred update. See MessageGroupStats::queueUpdates.
			// In case an error happens before that, the stats may be stale, but that
			// would be fixed by the next update or purge.
			MessageGroupStats::clear( $handle );
		}

		// This job asks for stats, however the updated stats are written in a deferred update.
		// To make it less likely that the job would be executed before the updated stats are
		// written, create the job inside a deferred update too.
		DeferredUpdates::addCallableUpdate(
			static function () use ( $handle ) {
				MessageGroupStatesUpdaterJob::onChange( $handle );
			}
		);
		$mwServices = MediaWikiServices::getInstance();
		$user = $mwServices->getUserFactory()
			->newFromId( $userIdentity->getId() );

		if ( !$fuzzy ) {
			$mwServices->getHookContainer()
			->run( 'Translate:newTranslation', [ $handle, $revId, $text, $user ] );
		}

		TTMServer::onChange( $handle );

		if ( $wgEnablePageTranslation && $handle->isPageTranslation() ) {
			// Updates for translatable pages only
			$minor = (bool)( $flags & EDIT_MINOR );
			PageTranslationHooks::onSectionSave( $wikiPage, $user, $content,
				$summary, $minor, $flags, $handle );
		}
	}

	/** Returns true if message is fuzzy, OR fails checks OR fails validations (error OR warning). */
	private static function checkNeedsFuzzy( MessageHandle $handle, string $text ): bool {
		// Docs are exempt for checks
		if ( $handle->isDoc() ) {
			return false;
		}

		// Check for explicit tag.
		if ( MessageHandle::hasFuzzyString( $text ) ) {
			return true;
		}

		// Not all groups have validators
		$group = $handle->getGroup();
		$validator = $group->getValidator();

		// no validator set
		if ( !$validator ) {
			return false;
		}

		$code = $handle->getCode();
		$key = $handle->getKey();
		$en = $group->getMessage( $key, $group->getSourceLanguage() );
		$message = new FatMessage( $key, $en );
		// Take the contents from edit field as a translation.
		$message->setTranslation( $text );
		if ( $message->definition() === null ) {
			// This should NOT happen, but add a check since it seems to be happening
			// See: https://phabricator.wikimedia.org/T255669
			LoggerFactory::getInstance( 'Translate' )->warning(
				'Message definition is empty! Title: {title}, group: {group}, key: {key}',
				[
					'title' => $handle->getTitle()->getPrefixedText(),
					'group' => $group->getId(),
					'key' => $key
				]
			);
			return false;
		}

		$validationResult = $validator->quickValidate( $message, $code );
		return $validationResult->hasIssues();
	}

	/**
	 * @param Title $title
	 * @param int $revision
	 * @param bool $fuzzy Whether to fuzzy or not
	 */
	private static function updateFuzzyTag( Title $title, int $revision, bool $fuzzy ): void {
		$dbw = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection( DB_PRIMARY );

		$conds = [
			'rt_page' => $title->getArticleID(),
			'rt_type' => RevTagStore::FUZZY_TAG,
			'rt_revision' => $revision
		];

		// Replace the existing fuzzy tag, if any
		if ( $fuzzy ) {
			$index = array_keys( $conds );
			$dbw->replace( 'revtag', [ $index ], $conds, __METHOD__ );
		} else {
			$dbw->delete( 'revtag', $conds, __METHOD__ );
		}
	}

	/**
	 * Adds tag which identifies the revision of source message at that time.
	 * This is used to show diff against current version of source message
	 * when updating a translation.
	 * Hook: Translate:newTranslation
	 */
	public static function updateTransverTag(
		MessageHandle $handle,
		int $revision,
		string $text,
		User $user
	): bool {
		if ( $user->isAllowed( 'bot' ) ) {
			return false;
		}

		$group = $handle->getGroup();

		$title = $handle->getTitle();
		$name = $handle->getKey() . '/' . $group->getSourceLanguage();
		$definitionTitle = Title::makeTitleSafe( $title->getNamespace(), $name );
		if ( !$definitionTitle || !$definitionTitle->exists() ) {
			return true;
		}

		$definitionRevision = $definitionTitle->getLatestRevID();
		$dbw = MediaWikiServices::getInstance()
			->getDBLoadBalancer()
			->getConnection( DB_PRIMARY );

		$conds = [
			'rt_page' => $title->getArticleID(),
			'rt_type' => RevTagStore::TRANSVER_PROP,
			'rt_revision' => $revision,
			'rt_value' => $definitionRevision,
		];
		$index = [ 'rt_type', 'rt_page', 'rt_revision' ];
		$dbw->replace( 'revtag', [ $index ], $conds, __METHOD__ );

		return true;
	}

	/** Hook: ArticlePrepareTextForEdit */
	public static function disablePreSaveTransform(
		WikiPage $wikiPage,
		ParserOptions $popts
	): void {
		global $wgTranslateUsePreSaveTransform;

		if ( !$wgTranslateUsePreSaveTransform ) {
			$handle = new MessageHandle( $wikiPage->getTitle() );
			if ( $handle->isMessageNamespace() && !$handle->isDoc() ) {
				$popts->setPreSaveTransform( false );
			}
		}
	}
}
