<?php
declare( strict_types=1 );

namespace MediaWiki\Extension\Translate;

use ALItem;
use ALTree;
use ApiRawMessage;
use Config;
use Content;
use DatabaseUpdater;
use IContextSource;
use Language;
use MediaWiki\ChangeTags\Hook\ChangeTagsListActiveHook;
use MediaWiki\ChangeTags\Hook\ListDefinedTagsHook;
use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\AbuseFilter\Variables\VariableHolder;
use MediaWiki\Extension\Translate\Diagnostics\SyncTranslatableBundleStatusMaintenanceScript;
use MediaWiki\Extension\Translate\MessageGroupProcessing\DeleteTranslatableBundleJob;
use MediaWiki\Extension\Translate\MessageGroupProcessing\MoveTranslatableBundleJob;
use MediaWiki\Extension\Translate\MessageGroupProcessing\RevTagStore;
use MediaWiki\Extension\Translate\MessageGroupProcessing\TranslatableBundleLogFormatter;
use MediaWiki\Extension\Translate\MessageLoading\FatMessage;
use MediaWiki\Extension\Translate\PageTranslation\DeleteTranslatableBundleSpecialPage;
use MediaWiki\Extension\Translate\PageTranslation\Hooks;
use MediaWiki\Extension\Translate\PageTranslation\MigrateTranslatablePageSpecialPage;
use MediaWiki\Extension\Translate\PageTranslation\PageTranslationSpecialPage;
use MediaWiki\Extension\Translate\PageTranslation\PrepareTranslatablePageSpecialPage;
use MediaWiki\Extension\Translate\PageTranslation\RenderTranslationPageJob;
use MediaWiki\Extension\Translate\PageTranslation\UpdateTranslatablePageJob;
use MediaWiki\Extension\Translate\SystemUsers\FuzzyBot;
use MediaWiki\Extension\Translate\SystemUsers\TranslateUserManager;
use MediaWiki\Extension\Translate\TranslatorInterface\TranslateEditAddons;
use MediaWiki\Extension\Translate\TranslatorSandbox\ManageTranslatorSandboxSpecialPage;
use MediaWiki\Extension\Translate\TranslatorSandbox\TranslationStashActionApi;
use MediaWiki\Extension\Translate\TranslatorSandbox\TranslationStashSpecialPage;
use MediaWiki\Extension\Translate\TranslatorSandbox\TranslatorSandboxActionApi;
use MediaWiki\Extension\Translate\TtmServer\SearchableTtmServer;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\Html\Html;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\Hook\RevisionRecordInsertedHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\StubObject\StubUserLang;
use MessageHandle;
use OutputPage;
use Parser;
use ParserOutput;
use RecentChange;
use SearchEngine;
use SpecialPage;
use SpecialSearch;
use Status;
use TextContent;
use Title;
use TitleValue;
use TranslateSandbox;
use TranslateToolbox;
use User;
use Wikimedia\Rdbms\ILoadBalancer;
use Xml;
use XmlSelect;

/**
 * Hooks for Translate extension.
 * Contains class with basic non-feature specific hooks.
 * Main subsystems, like page translation, should have their own hook handler. *
 * Most of the hooks on this class are still old style static functions, but new new hooks should
 * use the new style hook handlers with interfaces.
 *
 * @author Niklas Laxström
 * @license GPL-2.0-or-later
 */
class HookHandler implements RevisionRecordInsertedHook, ListDefinedTagsHook, ChangeTagsListActiveHook {
	/**
	 * Any user of this list should make sure that the tables
	 * actually exist, since they may be optional
	 */
	private const USER_MERGE_TABLES = [
		'translate_stash' => 'ts_user',
		'translate_reviews' => 'trr_user',
	];
	private RevisionLookup $revisionLookup;
	private ILoadBalancer $loadBalancer;
	private Config $config;

	public function __construct(
		RevisionLookup $revisionLookup,
		ILoadBalancer $loadBalancer,
		Config $config
	) {
		$this->revisionLookup = $revisionLookup;
		$this->loadBalancer = $loadBalancer;
		$this->config = $config;
	}

	/** Do late setup that depends on configuration. */
	public static function setupTranslate(): void {
		global $wgTranslateYamlLibrary;
		$hooks = [];

		/*
		 * Text that will be shown in translations if the translation is outdated.
		 * Must be something that does not conflict with actual content.
		 */
		if ( !defined( 'TRANSLATE_FUZZY' ) ) {
			define( 'TRANSLATE_FUZZY', '!!FUZZY!!' );
		}

		if ( $wgTranslateYamlLibrary === null ) {
			$wgTranslateYamlLibrary = function_exists( 'yaml_parse' ) ? 'phpyaml' : 'spyc';
		}

		$hooks['PageSaveComplete'][] = [ TranslateEditAddons::class, 'onSaveComplete' ];

		// Page translation setup check and init if enabled.
		global $wgEnablePageTranslation;
		if ( $wgEnablePageTranslation ) {
			// Special page and the right to use it
			global $wgSpecialPages, $wgAvailableRights;
			$wgSpecialPages['PageTranslation'] = [
				'class' => PageTranslationSpecialPage::class,
				'services' => [
					'LanguageNameUtils',
					'LanguageFactory',
					'Translate:TranslationUnitStoreFactory',
					'Translate:TranslatablePageParser',
					'LinkBatchFactory',
					'JobQueueGroup',
					'DBLoadBalancer',
					'Translate:MessageIndex'
				]
			];
			$wgSpecialPages['PageTranslationDeletePage'] = [
				'class' => DeleteTranslatableBundleSpecialPage::class,
				'services' => [
					'MainObjectStash',
					'PermissionManager',
					'Translate:TranslatableBundleFactory',
					'Translate:SubpageListBuilder',
					'JobQueueGroup',
				]
			];

			// right-pagetranslation action-pagetranslation
			$wgAvailableRights[] = 'pagetranslation';

			$wgSpecialPages['PageMigration'] = MigrateTranslatablePageSpecialPage::class;
			$wgSpecialPages['PagePreparation'] = PrepareTranslatablePageSpecialPage::class;

			global $wgActionFilteredLogs, $wgLogActionsHandlers, $wgLogTypes;

			// log-description-pagetranslation log-name-pagetranslation logentry-pagetranslation-mark
			// logentry-pagetranslation-unmark logentry-pagetranslation-moveok
			// logentry-pagetranslation-movenok logentry-pagetranslation-deletefok
			// logentry-pagetranslation-deletefnok logentry-pagetranslation-deletelok
			// logentry-pagetranslation-deletelnok logentry-pagetranslation-encourage
			// logentry-pagetranslation-discourage logentry-pagetranslation-prioritylanguages
			// logentry-pagetranslation-associate logentry-pagetranslation-dissociate
			$wgLogTypes[] = 'pagetranslation';
			$wgLogActionsHandlers['pagetranslation/mark'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/unmark'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/moveok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/movenok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/deletelok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/deletefok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/deletelnok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/deletefnok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/encourage'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/discourage'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/prioritylanguages'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/associate'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['pagetranslation/dissociate'] = TranslatableBundleLogFormatter::class;
			$wgActionFilteredLogs['pagetranslation'] = [
				'mark' => [ 'mark' ],
				'unmark' => [ 'unmark' ],
				'move' => [ 'moveok', 'movenok' ],
				'delete' => [ 'deletefok', 'deletefnok', 'deletelok', 'deletelnok' ],
				'encourage' => [ 'encourage' ],
				'discourage' => [ 'discourage' ],
				'prioritylanguages' => [ 'prioritylanguages' ],
				'aggregategroups' => [ 'associate', 'dissociate' ],
			];

			$wgLogTypes[] = 'messagebundle';
			$wgLogActionsHandlers['messagebundle/moveok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['messagebundle/movenok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['messagebundle/deletefok'] = TranslatableBundleLogFormatter::class;
			$wgLogActionsHandlers['messagebundle/deletefnok'] = TranslatableBundleLogFormatter::class;
			$wgActionFilteredLogs['messagebundle'] = [
				'move' => [ 'moveok', 'movenok' ],
				'delete' => [ 'deletefok', 'deletefnok' ],
			];

			global $wgJobClasses;
			$wgJobClasses['RenderTranslationPageJob'] = RenderTranslationPageJob::class;
			// Remove after MLEB 2022.10 release
			$wgJobClasses['TranslateRenderJob'] = RenderTranslationPageJob::class;
			// Remove after MLEB 2022.07 release
			$wgJobClasses['TranslatableBundleMoveJob'] = MoveTranslatableBundleJob::class;
			$wgJobClasses['MoveTranslatableBundleJob'] = MoveTranslatableBundleJob::class;
			// Remove after MLEB 2022.07 release
			$wgJobClasses['TranslatableBundleDeleteJob'] = DeleteTranslatableBundleJob::class;
			$wgJobClasses['DeleteTranslatableBundleJob'] = DeleteTranslatableBundleJob::class;

			$wgJobClasses['UpdateTranslatablePageJob'] = UpdateTranslatablePageJob::class;
			// Remove after MLEB 2022.10 release
			$wgJobClasses['TranslationsUpdateJob'] = UpdateTranslatablePageJob::class;

			// Namespaces
			global $wgNamespacesWithSubpages, $wgNamespaceProtection;
			global $wgTranslateMessageNamespaces;

			$wgNamespacesWithSubpages[NS_TRANSLATIONS] = true;
			$wgNamespacesWithSubpages[NS_TRANSLATIONS_TALK] = true;

			// Standard protection and register it for filtering
			$wgNamespaceProtection[NS_TRANSLATIONS] = [ 'translate' ];
			$wgTranslateMessageNamespaces[] = NS_TRANSLATIONS;

			/// Page translation hooks

			/// Register our CSS and metadata
			$hooks['BeforePageDisplay'][] = [ Hooks::class, 'onBeforePageDisplay' ];

			// Disable VE
			$hooks['VisualEditorBeforeEditor'][] = [ Hooks::class, 'onVisualEditorBeforeEditor' ];

			// Check syntax for \<translate>
			$hooks['MultiContentSave'][] = [ Hooks::class, 'tpSyntaxCheck' ];
			$hooks['EditFilterMergedContent'][] =
				[ Hooks::class, 'tpSyntaxCheckForEditContent' ];

			// Add transtag to page props for discovery
			$hooks['PageSaveComplete'][] = [ Hooks::class, 'addTranstagAfterSave' ];

			$hooks['RevisionRecordInserted'][] = [ Hooks::class, 'updateTranstagOnNullRevisions' ];

			// Register different ways to show language links
			$hooks['ParserFirstCallInit'][] = [ self::class, 'setupParserHooks' ];
			$hooks['LanguageLinks'][] = [ Hooks::class, 'addLanguageLinks' ];
			$hooks['SkinTemplateGetLanguageLink'][] = [ Hooks::class, 'formatLanguageLink' ];

			// Strip \<translate> tags etc. from source pages when rendering
			$hooks['ParserBeforeInternalParse'][] = [ Hooks::class, 'renderTagPage' ];
			// Strip \<translate> tags etc. from source pages when preprocessing
			$hooks['ParserBeforePreprocess'][] = [ Hooks::class, 'preprocessTagPage' ];
			$hooks['ParserOutputPostCacheTransform'][] =
				[ Hooks::class, 'onParserOutputPostCacheTransform' ];

			$hooks['BeforeParserFetchTemplateRevisionRecord'][] =
				[ Hooks::class, 'fetchTranslatableTemplateAndTitle' ];

			// Set the page content language
			$hooks['PageContentLanguage'][] = [ Hooks::class, 'onPageContentLanguage' ];

			// Prevent editing of certain pages in translations namespace
			$hooks['getUserPermissionsErrorsExpensive'][] =
				[ Hooks::class, 'onGetUserPermissionsErrorsExpensive' ];
			// Prevent editing of translation pages directly
			$hooks['getUserPermissionsErrorsExpensive'][] =
				[ Hooks::class, 'preventDirectEditing' ];

			// Our custom header for translation pages
			$hooks['ArticleViewHeader'][] = [ Hooks::class, 'translatablePageHeader' ];

			// Edit notice shown on translatable pages
			$hooks['TitleGetEditNotices'][] = [ Hooks::class, 'onTitleGetEditNotices' ];

			// Custom move page that can move all the associated pages too
			$hooks['SpecialPage_initList'][] = [ Hooks::class, 'replaceMovePage' ];
			// Locking during page moves
			$hooks['getUserPermissionsErrorsExpensive'][] =
				[ Hooks::class, 'lockedPagesCheck' ];
			// Disable action=delete
			$hooks['ArticleConfirmDelete'][] = [ Hooks::class, 'disableDelete' ];

			// Replace subpage logic behavior
			$hooks['SkinSubPageSubtitle'][] = [ Hooks::class, 'replaceSubtitle' ];

			// Replaced edit tab with translation tab for translation pages
			$hooks['SkinTemplateNavigation::Universal'][] = [ Hooks::class, 'translateTab' ];

			// Update translated page when translation unit is moved
			$hooks['PageMoveComplete'][] = [ Hooks::class, 'onMovePageTranslationUnits' ];

			// Update translated page when translation unit is deleted
			$hooks['ArticleDeleteComplete'][] = [ Hooks::class, 'onDeleteTranslationUnit' ];
		}

		global $wgTranslateUseSandbox;
		if ( $wgTranslateUseSandbox ) {
			global $wgSpecialPages, $wgAvailableRights, $wgDefaultUserOptions;

			$wgSpecialPages['ManageTranslatorSandbox'] = [
				'class' => ManageTranslatorSandboxSpecialPage::class,
				'services' => [
					'Translate:TranslationStashReader',
					'UserOptionsLookup'
				],
				'args' => [
					static function () {
						return new ServiceOptions(
							ManageTranslatorSandboxSpecialPage::CONSTRUCTOR_OPTIONS,
							MediaWikiServices::getInstance()->getMainConfig()
						);
					}
				]
			];
			$wgSpecialPages['TranslationStash'] = [
				'class' => TranslationStashSpecialPage::class,
				'services' => [
					'LanguageNameUtils',
					'Translate:TranslationStashReader',
					'UserOptionsLookup',
					'LanguageFactory',
				],
				'args' => [
					static function () {
						return new ServiceOptions(
							TranslationStashSpecialPage::CONSTRUCTOR_OPTIONS,
							MediaWikiServices::getInstance()->getMainConfig()
						);
					}
				]
			];
			$wgDefaultUserOptions['translate-sandbox'] = '';
			// right-translate-sandboxmanage action-translate-sandboxmanage
			$wgAvailableRights[] = 'translate-sandboxmanage';

			$hooks['GetPreferences'][] = [ TranslateSandbox::class, 'onGetPreferences' ];
			$hooks['UserGetRights'][] = [ TranslateSandbox::class, 'enforcePermissions' ];
			$hooks['ApiCheckCanExecute'][] = [ TranslateSandbox::class, 'onApiCheckCanExecute' ];

			global $wgLogTypes, $wgLogActionsHandlers;
			// log-name-translatorsandbox log-description-translatorsandbox
			$wgLogTypes[] = 'translatorsandbox';
			// logentry-translatorsandbox-promoted logentry-translatorsandbox-rejected
			$wgLogActionsHandlers['translatorsandbox/promoted'] = 'TranslateLogFormatter';
			$wgLogActionsHandlers['translatorsandbox/rejected'] = 'TranslateLogFormatter';

			// This is no longer used for new entries since 2016.07.
			// logentry-newusers-tsbpromoted
			$wgLogActionsHandlers['newusers/tsbpromoted'] = 'LogFormatter';

			global $wgJobClasses;
			$wgJobClasses['TranslateSandboxEmailJob'] = 'TranslateSandboxEmailJob';

			global $wgAPIModules;
			$wgAPIModules['translationstash'] = [
				'class' => TranslationStashActionApi::class,
				'services' => [
					'DBLoadBalancer',
					'UserFactory'
				]
			];
			$wgAPIModules['translatesandbox'] = [
				'class' => TranslatorSandboxActionApi::class,
				'services' => [
					'UserFactory',
					'UserNameUtils',
					'UserOptionsManager',
					'WikiPageFactory',
					'UserOptionsLookup'
				],
				'args' => [
					static function () {
						return new ServiceOptions(
							TranslatorSandboxActionApi::CONSTRUCTOR_OPTIONS,
							MediaWikiServices::getInstance()->getMainConfig()
						);
					}
				]
			];
		}

		global $wgNamespaceRobotPolicies;
		$wgNamespaceRobotPolicies[NS_TRANSLATIONS] = 'noindex';

		// If no service has been configured, we use a built-in fallback.
		global $wgTranslateTranslationDefaultService,
			   $wgTranslateTranslationServices;
		if ( $wgTranslateTranslationDefaultService === true ) {
			$wgTranslateTranslationDefaultService = 'TTMServer';
			if ( !isset( $wgTranslateTranslationServices['TTMServer'] ) ) {
				$wgTranslateTranslationServices['TTMServer'] = [
					'database' => false, // Passed to wfGetDB
					'cutoff' => 0.75,
					'type' => 'ttmserver',
					'public' => false,
				];
			}
		}

		$hooks['SidebarBeforeOutput'][] = [ TranslateToolbox::class, 'toolboxAllTranslations' ];

		static::registerHookHandlers( $hooks );
	}

	private static function registerHookHandlers( array $hooks ): void {
		if ( defined( 'MW_PHPUNIT_TEST' ) && MediaWikiServices::hasInstance() ) {
			// When called from a test case's setUp() method,
			// we can use HookContainer, but we cannot use SettingsBuilder.
			$hookContainer = MediaWikiServices::getInstance()->getHookContainer();
			foreach ( $hooks as $name => $handlers ) {
				foreach ( $handlers as $h ) {
					$hookContainer->register( $name, $h );
				}
			}
		} elseif ( method_exists( SettingsBuilder::class, 'registerHookHandlers' ) ) {
			// Since 1.40: Use SettingsBuilder to register hooks during initialization.
			// HookContainer is not available at this time.
			$settingsBuilder = SettingsBuilder::getInstance();
			$settingsBuilder->registerHookHandlers( $hooks );
		} else {
			// For MW < 1.40: Directly manipulate $wgHooks during initialization.
			foreach ( $hooks as $name => $handlers ) {
				$GLOBALS['wgHooks'][$name] = array_merge(
					$GLOBALS['wgHooks'][$name] ?? [],
					$handlers
				);
			}
		}
	}

	/**
	 * Hook: UserGetReservedNames
	 * Prevents anyone from registering or logging in as FuzzyBot
	 */
	public static function onUserGetReservedNames( array &$names ): void {
		$names[] = FuzzyBot::getName();
		$names[] = TranslateUserManager::getName();
	}

	/** Used for setting an AbuseFilter variable. */
	public static function onAbuseFilterAlterVariables(
		VariableHolder &$vars, Title $title, User $user
	): void {
		$handle = new MessageHandle( $title );

		// Only set this variable if we are in a proper namespace to avoid
		// unnecessary overhead in non-translation pages
		if ( $handle->isMessageNamespace() ) {
			$vars->setLazyLoadVar(
				'translate_source_text',
				'translate-get-source',
				[ 'handle' => $handle ]
			);
			$vars->setLazyLoadVar(
				'translate_target_language',
				'translate-get-target-language',
				[ 'handle' => $handle ]
			);
		}
	}

	/** Computes the translate_source_text and translate_target_language AbuseFilter variables */
	public static function onAbuseFilterComputeVariable(
		string $method,
		VariableHolder $vars,
		array $parameters,
		?string &$result
	): bool {
		if ( $method !== 'translate-get-source' && $method !== 'translate-get-target-language' ) {
			return true;
		}

		$handle = $parameters['handle'];
		$value = '';
		if ( $handle->isValid() ) {
			if ( $method === 'translate-get-source' ) {
				$group = $handle->getGroup();
				$value = $group->getMessage( $handle->getKey(), $group->getSourceLanguage() );
			} else {
				$value = $handle->getCode();
			}
		}

		$result = $value;

		return false;
	}

	/** Register AbuseFilter variables provided by Translate. */
	public static function onAbuseFilterBuilder( array &$builderValues ): void {
		// Uses: 'abusefilter-edit-builder-vars-translate-source-text'
		// and 'abusefilter-edit-builder-vars-translate-target-language'
		$builderValues['vars']['translate_source_text'] = 'translate-source-text';
		$builderValues['vars']['translate_target_language'] = 'translate-target-language';
	}

	/**
	 * Hook: ParserFirstCallInit
	 * Registers \<languages> tag with the parser.
	 */
	public static function setupParserHooks( Parser $parser ): void {
		// For nice language list in-page
		$parser->setHook( 'languages', [ Hooks::class, 'languages' ] );
	}

	/** Hook: LoadExtensionSchemaUpdates */
	public static function schemaUpdates( DatabaseUpdater $updater ): void {
		$dir = dirname( __DIR__, 1 ) . '/sql';
		$dbType = $updater->getDB()->getType();

		if ( $dbType === 'mysql' || $dbType === 'sqlite' ) {
			$updater->addExtensionTable(
				'translate_sections',
				"{$dir}/{$dbType}/translate_sections.sql"
			);
			$updater->addExtensionTable(
				'revtag',
				"{$dir}/{$dbType}/revtag.sql"
			);
			$updater->addExtensionTable(
				'translate_groupstats',
				"{$dir}/{$dbType}/translate_groupstats.sql"
			);
			$updater->addExtensionTable(
				'translate_reviews',
				"{$dir}/{$dbType}/translate_reviews.sql"
			);
			$updater->addExtensionTable(
				'translate_groupreviews',
				"{$dir}/{$dbType}/translate_groupreviews.sql"
			);
			$updater->addExtensionTable(
				'translate_tms',
				"{$dir}/{$dbType}/translate_tm.sql"
			);
			$updater->addExtensionTable(
				'translate_metadata',
				"{$dir}/{$dbType}/translate_metadata.sql"
			);
			$updater->addExtensionTable(
				'translate_messageindex',
				"{$dir}/{$dbType}/translate_messageindex.sql"
			);
			$updater->addExtensionTable(
				'translate_stash',
				"{$dir}/{$dbType}/translate_stash.sql"
			);
			$updater->addExtensionTable(
				'translate_translatable_bundles',
				"{$dir}/{$dbType}/translate_translatable_bundles.sql"
			);

			// 1.32 - This also adds a PRIMARY KEY
			$updater->addExtensionUpdate( [
				'renameIndex',
				'translate_reviews',
				'trr_user_page_revision',
				'PRIMARY',
				false,
				"$dir/translate_reviews-patch-01-primary-key.sql",
				true
			] );

			$updater->addExtensionTable(
				'translate_cache',
				"{$dir}/{$dbType}/translate_cache.sql"
			);

			if ( $dbType === 'mysql' ) {
				// 1.38
				$updater->modifyExtensionField(
					'translate_cache',
					'tc_key',
					"{$dir}/{$dbType}/translate_cache-alter-varbinary.sql"
				);
			}
		} elseif ( $dbType === 'postgres' ) {
			$updater->addExtensionTable(
				'translate_sections',
				"{$dir}/{$dbType}/tables-generated.sql"
			);
			$updater->addExtensionUpdate( [
				'changeField', 'translate_cache', 'tc_exptime', 'TIMESTAMPTZ', 'th_timestamp::timestamp with time zone'
			] );
		}

		// 1.39
		$updater->dropExtensionIndex(
			'translate_messageindex',
			'tmi_key',
			"{$dir}/{$dbType}/patch-translate_messageindex-unique-to-pk.sql"
		);
		$updater->dropExtensionIndex(
			'translate_tmt',
			'tms_sid_lang',
			"{$dir}/{$dbType}/patch-translate_tmt-unique-to-pk.sql"
		);
		$updater->dropExtensionIndex(
			'revtag',
			'rt_type_page_revision',
			"{$dir}/{$dbType}/patch-revtag-unique-to-pk.sql"
		);

		$updater->addPostDatabaseUpdateMaintenance( SyncTranslatableBundleStatusMaintenanceScript::class );
	}

	/** Hook: ParserTestTables
	 */
	public static function parserTestTables( array &$tables ): void {
		$tables[] = 'revtag';
		$tables[] = 'translate_groupstats';
		$tables[] = 'translate_messageindex';
		$tables[] = 'translate_stash';
	}

	/**
	 * Hook: PageContentLanguage
	 * Set the correct page content language for translation units.
	 * @param Title $title
	 * @param Language|StubUserLang|string &$pageLang
	 */
	public static function onPageContentLanguage( Title $title, &$pageLang ): void {
		$handle = new MessageHandle( $title );
		if ( $handle->isMessageNamespace() ) {
			$pageLang = $handle->getEffectiveLanguage();
		}
	}

	/**
	 * Hook: LanguageGetTranslatedLanguageNames
	 * Hook: TranslateSupportedLanguages
	 */
	public static function translateMessageDocumentationLanguage( array &$names, ?string $code ): void {
		global $wgTranslateDocumentationLanguageCode;
		if ( $wgTranslateDocumentationLanguageCode ) {
			// Special case the autonyms
			if (
				$wgTranslateDocumentationLanguageCode === $code ||
				$code === null
			) {
				$code = 'en';
			}

			$names[$wgTranslateDocumentationLanguageCode] =
				wfMessage( 'translate-documentation-language' )->inLanguage( $code )->plain();
		}
	}

	/** Hook: SpecialSearchProfiles */
	public static function searchProfile( array &$profiles ): void {
		global $wgTranslateMessageNamespaces;
		$insert = [];
		$insert['translation'] = [
			'message' => 'translate-searchprofile',
			'tooltip' => 'translate-searchprofile-tooltip',
			'namespaces' => $wgTranslateMessageNamespaces,
		];

		// Insert translations before 'all'
		$index = array_search( 'all', array_keys( $profiles ) );

		// Or just at the end if all is not found
		if ( $index === false ) {
			wfWarn( '"all" not found in search profiles' );
			$index = count( $profiles );
		}

		$profiles = array_merge(
			array_slice( $profiles, 0, $index ),
			$insert,
			array_slice( $profiles, $index )
		);
	}

	/** Hook: SpecialSearchProfileForm */
	public static function searchProfileForm(
		SpecialSearch $search,
		string &$form,
		string $profile,
		string $term,
		array $opts
	): bool {
		if ( $profile !== 'translation' ) {
			return true;
		}

		if ( Services::getInstance()->getTtmServerFactory()->getDefaultForQuerying() instanceof SearchableTtmServer ) {
			$href = SpecialPage::getTitleFor( 'SearchTranslations' )
				->getFullUrl( [ 'query' => $term ] );
			$form = Html::successBox(
				$search->msg( 'translate-searchprofile-note', $href )->parse(),
				'plainlinks'
			);

			return false;
		}

		if ( !$search->getSearchEngine()->supports( 'title-suffix-filter' ) ) {
			return false;
		}

		$hidden = '';
		foreach ( $opts as $key => $value ) {
			$hidden .= Html::hidden( $key, $value );
		}

		$context = $search->getContext();
		$code = $context->getLanguage()->getCode();
		$selected = $context->getRequest()->getVal( 'languagefilter' );

		$languages = Utilities::getLanguageNames( $code );
		ksort( $languages );

		$selector = new XmlSelect( 'languagefilter', 'languagefilter' );
		$selector->setDefault( $selected );
		$selector->addOption( wfMessage( 'translate-search-nofilter' )->text(), '-' );
		foreach ( $languages as $code => $name ) {
			$selector->addOption( "$code - $name", $code );
		}

		$selector = $selector->getHTML();

		$label = Xml::label(
			wfMessage( 'translate-search-languagefilter' )->text(),
			'languagefilter'
		) . '&#160;';
		$params = [ 'id' => 'mw-searchoptions' ];

		$form = Xml::fieldset( false, false, $params ) .
			$hidden . $label . $selector .
			Html::closeElement( 'fieldset' );

		return false;
	}

	/** Hook: SpecialSearchSetupEngine */
	public static function searchProfileSetupEngine(
		SpecialSearch $search,
		string $profile,
		SearchEngine $engine
	): void {
		if ( $profile !== 'translation' ) {
			return;
		}

		$context = $search->getContext();
		$selected = $context->getRequest()->getVal( 'languagefilter' );
		if ( $selected !== '-' && $selected ) {
			$engine->setFeatureData( 'title-suffix-filter', "/$selected" );
			$search->setExtraParam( 'languagefilter', $selected );
		}
	}

	/** Hook: ParserAfterTidy */
	public static function preventCategorization( Parser $parser, string &$html ): void {
		$pageReference = $parser->getPage();
		if ( !$pageReference ) {
			return;
		}

		$linkTarget = TitleValue::newFromPage( $pageReference );
		$handle = new MessageHandle( $linkTarget );
		if ( $handle->isMessageNamespace() && !$handle->isDoc() ) {
			$parserOutput = $parser->getOutput();
			// MW >= 1.40
			if ( method_exists( $parserOutput, 'getCategorySortKey' ) ) {
				$names = $parserOutput->getCategoryNames();
				$parserCategories = [];
				foreach ( $names as $name ) {
					$parserCategories[$name] = $parserOutput->getCategorySortKey( $name );
				}
				$parserOutput->setExtensionData( 'translate-fake-categories', $parserCategories );
			} else {
				$parserOutput->setExtensionData( 'translate-fake-categories',
					$parserOutput->getCategories() );
			}
			$parserOutput->setCategories( [] );
		}
	}

	/** Hook: OutputPageParserOutput */
	public static function showFakeCategories( OutputPage $outputPage, ParserOutput $parserOutput ): void {
		$fakeCategories = $parserOutput->getExtensionData( 'translate-fake-categories' );
		if ( $fakeCategories ) {
			$outputPage->setCategoryLinks( $fakeCategories );
		}
	}

	/**
	 * Hook: MakeGlobalVariablesScript
	 * Adds $wgTranslateDocumentationLanguageCode to ResourceLoader configuration
	 * when Special:Translate is shown.
	 */
	public static function addConfig( array &$vars, OutputPage $out ): void {
		$title = $out->getTitle();
		[ $alias, ] = MediaWikiServices::getInstance()
			->getSpecialPageFactory()->resolveAlias( $title->getText() );

		if ( $title->isSpecialPage()
			&& ( $alias === 'Translate'
				|| $alias === 'TranslationStash'
				|| $alias === 'SearchTranslations' )
		) {
			global $wgTranslateDocumentationLanguageCode, $wgTranslatePermissionUrl,
				$wgTranslateUseSandbox;
			$vars['TranslateRight'] = $out->getUser()->isAllowed( 'translate' );
			$vars['TranslateMessageReviewRight'] =
				$out->getUser()->isAllowed( 'translate-messagereview' );
			$vars['DeleteRight'] = $out->getUser()->isAllowed( 'delete' );
			$vars['TranslateManageRight'] = $out->getUser()->isAllowed( 'translate-manage' );
			$vars['wgTranslateDocumentationLanguageCode'] = $wgTranslateDocumentationLanguageCode;
			$vars['wgTranslatePermissionUrl'] = $wgTranslatePermissionUrl;
			$vars['wgTranslateUseSandbox'] = $wgTranslateUseSandbox;
		}
	}

	/** Hook: AdminLinks */
	public static function onAdminLinks( ALTree $tree ): void {
		global $wgTranslateUseSandbox;

		if ( $wgTranslateUseSandbox ) {
			$sectionLabel = wfMessage( 'adminlinks_users' )->text();
			$row = $tree->getSection( $sectionLabel )->getRow( 'main' );
			$row->addItem( ALItem::newFromSpecialPage( 'TranslateSandbox' ) );
		}
	}

	/**
	 * Hook: MergeAccountFromTo
	 * For UserMerge extension.
	 */
	public static function onMergeAccountFromTo( User $oldUser, User $newUser ): void {
		$dbw = MediawikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );

		// Update the non-duplicate rows, we'll just delete
		// the duplicate ones later
		foreach ( self::USER_MERGE_TABLES as $table => $field ) {
			if ( $dbw->tableExists( $table, __METHOD__ ) ) {
				$dbw->update(
					$table,
					[ $field => $newUser->getId() ],
					[ $field => $oldUser->getId() ],
					__METHOD__,
					[ 'IGNORE' ]
				);
			}
		}
	}

	/**
	 * Hook: DeleteAccount
	 * For UserMerge extension.
	 */
	public static function onDeleteAccount( User $oldUser ): void {
		$dbw = MediawikiServices::getInstance()->getDBLoadBalancer()->getMaintenanceConnectionRef( DB_PRIMARY );

		// Delete any remaining rows that didn't get merged
		foreach ( self::USER_MERGE_TABLES as $table => $field ) {
			if ( $dbw->tableExists( $table, __METHOD__ ) ) {
				$dbw->delete(
					$table,
					[ $field => $oldUser->getId() ],
					__METHOD__
				);
			}
		}
	}

	/** Hook: AbortEmailNotification */
	public static function onAbortEmailNotificationReview(
		User $editor,
		Title $title,
		RecentChange $rc
	): bool {
		return $rc->getAttribute( 'rc_log_type' ) !== 'translationreview';
	}

	/**
	 * Hook: TitleIsAlwaysKnown
	 * Make Special:MyLanguage links red if the target page doesn't exist.
	 * A bit hacky because the core code is not so flexible.
	 * @param Title $target Title object that is being checked
	 * @param bool|null &$isKnown Whether MediaWiki currently thinks this page is known
	 * @return bool True or no return value to continue or false to abort
	 */
	public static function onTitleIsAlwaysKnown( $target, &$isKnown ): bool {
		if ( !$target->inNamespace( NS_SPECIAL ) ) {
			return true;
		}

		[ $name, $subpage ] = MediaWikiServices::getInstance()
			->getSpecialPageFactory()->resolveAlias( $target->getDBkey() );
		if ( $name !== 'MyLanguage' ) {
			return true;
		}

		if ( (string)$subpage === '' ) {
			return true;
		}

		$realTarget = Title::newFromText( $subpage );
		if ( !$realTarget || !$realTarget->exists() ) {
			$isKnown = false;

			return false;
		}

		return true;
	}

	/** Hook: ParserFirstCallInit */
	public static function setupTranslateParserFunction( Parser $parser ): void {
		$parser->setFunctionHook( 'translation', [ self::class, 'translateRenderParserFunction' ] );
	}

	public static function translateRenderParserFunction( Parser $parser ): string {
		$pageReference = $parser->getPage();
		if ( !$pageReference ) {
			return '';
		}
		$linkTarget = TitleValue::newFromPage( $pageReference );
		$handle = new MessageHandle( $linkTarget );
		$code = $handle->getCode();
		if ( MediaWikiServices::getInstance()->getLanguageNameUtils()->isKnownLanguageTag( $code ) ) {
			return '/' . $code;
		}
		return '';
	}

	/**
	 * Runs the configured validator to ensure that the message meets the required criteria.
	 * Hook: EditFilterMergedContent
	 * @return bool true if message is valid, false otherwise.
	 */
	public static function validateMessage(
		IContextSource $context,
		Content $content,
		Status $status,
		string $summary,
		User $user
	): bool {
		if ( !$content instanceof TextContent ) {
			// Not interested
			return true;
		}

		$text = $content->getText();
		$title = $context->getTitle();
		$handle = new MessageHandle( $title );

		if ( !$handle->isValid() ) {
			return true;
		}

		// Don't bother validating if FuzzyBot or translation admin are saving.
		if ( $user->isAllowed( 'translate-manage' ) || $user->equals( FuzzyBot::getUser() ) ) {
			return true;
		}

		// Check the namespace, and perform validations for all messages excluding documentation.
		if ( $handle->isMessageNamespace() && !$handle->isDoc() ) {
			$group = $handle->getGroup();

			if ( method_exists( $group, 'getMessageContent' ) ) {
				// @phan-suppress-next-line PhanUndeclaredMethod
				$definition = $group->getMessageContent( $handle );
			} else {
				$definition = $group->getMessage( $handle->getKey(), $group->getSourceLanguage() );
			}

			$message = new FatMessage( $handle->getKey(), $definition );
			$message->setTranslation( $text );

			$messageValidator = $group->getValidator();
			if ( !$messageValidator ) {
				return true;
			}

			$validationResponse = $messageValidator->validateMessage( $message, $handle->getCode() );
			if ( $validationResponse->hasErrors() ) {
				$status->fatal( new ApiRawMessage(
					$context->msg( 'translate-syntax-error' )->parse(),
					'translate-validation-failed',
					[
						'validation' => [
							'errors' => $validationResponse->getDescriptiveErrors( $context ),
							'warnings' => $validationResponse->getDescriptiveWarnings( $context )
						]
					]
				) );
				return false;
			}
		}

		return true;
	}

	/** @inheritDoc */
	public function onRevisionRecordInserted( $revisionRecord ): void {
		$parentId = $revisionRecord->getParentId();
		if ( $parentId === 0 || $parentId === null ) {
			// No parent, bail out.
			return;
		}

		$prevRev = $this->revisionLookup->getRevisionById( $parentId );
		if ( !$prevRev || !$revisionRecord->hasSameContent( $prevRev ) ) {
			// Not a null revision, bail out.
			return;
		}

		// List of tags that should be copied over when updating
		// tp:tag and tp:mark handling is in Hooks::updateTranstagOnNullRevisions.
		$tagsToCopy = [ RevTagStore::FUZZY_TAG, RevTagStore::TRANSVER_PROP ];

		$db = $this->loadBalancer->getConnection( DB_PRIMARY );
		$db->insertSelect(
			'revtag',
			'revtag',
			[
				'rt_type' => 'rt_type',
				'rt_page' => 'rt_page',
				'rt_revision' => $revisionRecord->getId(),
				'rt_value' => 'rt_value',

			],
			[
				'rt_type' => $tagsToCopy,
				'rt_revision' => $parentId,
			],
			__METHOD__
		);
	}

	/** @inheritDoc */
	public function onListDefinedTags( &$tags ): void {
		$tags[] = 'translate-translation-pages';
	}

	/** @inheritDoc */
	public function onChangeTagsListActive( &$tags ): void {
		if ( $this->config->get( 'EnablePageTranslation' ) ) {
			$tags[] = 'translate-translation-pages';
		}
	}
}
