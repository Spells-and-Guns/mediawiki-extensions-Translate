<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\MessageLoading;

use AppendIterator;
use ArrayAccess;
use Countable;
use EmptyIterator;
use Iterator;
use MediaWiki\Extension\Translate\MessageGroupProcessing\RevTagStore;
use MediaWiki\Extension\Translate\SystemUsers\FuzzyBot;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MWException;
use stdClass;
use TextContent;
use TitleValue;
use Traversable;
use Wikimedia\Rdbms\IDatabase;

/**
 * This file contains the class for core message collections implementation.
 *
 * Message collection is collection of messages of one message group in one
 * language. It handles loading of the messages in one huge batch, and also
 * stores information that can be used to filter the collection in different
 * ways.
 *
 * @author Niklas Laxström
 * @copyright Copyright © 2007-2011, Niklas Laxström
 * @license GPL-2.0-or-later
 */
class MessageCollection implements ArrayAccess, Iterator, Countable {
	/**
	 * The queries can get very large because each message title is specified
	 * individually. Very large queries can confuse the database query planner.
	 * Queries are split into multiple separate queries having at most this many
	 * items.
	 */
	private const MAX_ITEMS_PER_QUERY = 2000;

	/** Language code. */
	public string $code;
	private MessageDefinitions $definitions;
	/** array( %Message key => translation, ... ) */
	private array $infile = [];
	// Keys and messages.

	/** array( %Message display key => database key, ... ) */
	protected array $keys = [];
	/** array( %Message String => Message, ... ) */
	protected ?array $messages = [];
	private ?array $reverseMap;
	// Database resources

	/** Stored message existence and fuzzy state. */
	private Traversable $dbInfo;
	/** Stored translations in database. */
	private Traversable $dbData;
	/** Stored reviews in database. */
	private Traversable $dbReviewData;
	/**
	 * Tags, copied to thin messages
	 * tagtype => keys
	 * @var array[]
	 */
	protected array $tags = [];
	/**
	 * Properties, copied to thin messages
	 * @var array[]
	 */
	private array $properties = [];
	/** @var string[] Authors. */
	private array $authors = [];

	/**
	 * Constructors. Use newFromDefinitions() instead.
	 * @param string $code Language code.
	 */
	public function __construct( string $code ) {
		$this->code = $code;
	}

	/**
	 * Construct a new message collection from definitions.
	 * @param MessageDefinitions $definitions
	 * @param string $code Language code.
	 */
	public static function newFromDefinitions( MessageDefinitions $definitions, string $code ): self {
		$collection = new self( $code );
		$collection->definitions = $definitions;
		$collection->resetForNewLanguage( $code );

		return $collection;
	}

	public function getLanguage(): string {
		return $this->code;
	}

	// Data setters

	/**
	 * Set translation from file, as opposed to translation which only exists
	 * in the wiki because they are not exported and committed yet.
	 * @param string[] $messages Array of translations indexed by display key.
	 */
	public function setInFile( array $messages ): void {
		$this->infile = $messages;
	}

	/**
	 * Set message tags.
	 * @param string $type Tag type, usually ignored or optional.
	 * @param string[] $keys List of display keys.
	 */
	public function setTags( string $type, array $keys ): void {
		$this->tags[$type] = $keys;
	}

	/**
	 * Returns list of available message keys. This is affected by filtering.
	 * @return array List of database keys indexed by display keys (TitleValue).
	 */
	public function keys(): array {
		return $this->keys;
	}

	/**
	 * Returns list of TitleValues of messages that are used in this collection after filtering.
	 * @return TitleValue[]
	 */
	private function getTitles(): array {
		return array_values( $this->keys );
	}

	/**
	 * Returns list of message keys that are used in this collection after filtering.
	 * @return string[]
	 */
	public function getMessageKeys(): array {
		return array_keys( $this->keys );
	}

	/**
	 * Returns stored message tags.
	 * @param string $type Tag type, usually optional or ignored.
	 * @return string[] List of keys with given tag.
	 */
	public function getTags( string $type ): array {
		return $this->tags[$type] ?? [];
	}

	/**
	 * Lists all translators that have contributed to the latest revisions of
	 * each translation. Causes translations to be loaded from the database.
	 * Is not affected by filters.
	 * @return string[] List of usernames.
	 */
	public function getAuthors(): array {
		$this->loadTranslations();

		$authors = array_flip( $this->authors );

		foreach ( $this->messages as $m ) {
			// Check if there are authors
			/** @var Message $m */
			$author = $m->getProperty( 'last-translator-text' );

			if ( $author === null ) {
				continue;
			}

			if ( !isset( $authors[$author] ) ) {
				$authors[$author] = 1;
			} else {
				$authors[$author]++;
			}
		}

		# arsort( $authors, SORT_NUMERIC );
		ksort( $authors );
		$fuzzyBot = FuzzyBot::getName();
		$filteredAuthors = [];
		foreach ( $authors as $author => $edits ) {
			if ( $author !== $fuzzyBot ) {
				$filteredAuthors[] = $author;
			}
		}

		return $filteredAuthors;
	}

	/**
	 * Add external authors (usually from the file).
	 * @param string[] $authors List of authors.
	 * @param string $mode Either append or set authors.
	 * @throws MWException If invalid $mode given.
	 */
	public function addCollectionAuthors( array $authors, string $mode = 'append' ): void {
		switch ( $mode ) {
			case 'append':
				$authors = array_merge( $this->authors, $authors );
				break;
			case 'set':
				break;
			default:
				throw new MWException( "Invalid mode $mode" );
		}

		$this->authors = array_unique( $authors );
	}

	// Data modifiers

	/**
	 * Loads all message data. Must be called before accessing the messages
	 * with ArrayAccess or iteration.
	 */
	public function loadTranslations(): void {
		// Performance optimization: Instead of building conditions based on key in every
		// method, build them once and pass it on to each of them.
		$dbr = Utilities::getSafeReadDB();
		$titleConds = $this->getTitleConds( $dbr );

		$this->loadData( $this->keys, $titleConds );
		$this->loadInfo( $this->keys, $titleConds );
		$this->loadReviewInfo( $this->keys, $titleConds );
		$this->initMessages();
	}

	/**
	 * Some statistics scripts for example loop the same collection over every
	 * language. This is a shortcut which keeps tags and definitions.
	 */
	public function resetForNewLanguage( string $code ): void {
		$this->code = $code;
		$this->keys = $this->fixKeys();
		$this->dbInfo = new EmptyIterator();
		$this->dbData = new EmptyIterator();
		$this->dbReviewData = new EmptyIterator();
		$this->messages = null;
		$this->infile = [];
		$this->authors = [];

		unset( $this->tags['fuzzy'] );
		$this->reverseMap = null;
	}

	/**
	 * For paging messages. One can count messages before and after slice.
	 * @param string $offset
	 * @param int $limit
	 * @return array Offsets that can be used for paging backwards and forwards
	 * @since String offests and return value since 2013-01-10
	 */
	public function slice( $offset, $limit ) {
		$indexes = array_keys( $this->keys );

		if ( $offset === '' ) {
			$offset = 0;
		}

		// Handle string offsets
		if ( !ctype_digit( (string)$offset ) ) {
			$pos = array_search( $offset, array_keys( $this->keys ), true );
			// Now offset is always an integer, suitable for array_slice
			$offset = $pos !== false ? $pos : count( $this->keys );
		}

		// False means that cannot go back or forward
		$backwardsOffset = $forwardsOffset = false;
		// Backwards paging uses numerical indexes, see below

		// Can only skip this if no offset has been provided or the
		// offset is zero. (offset - limit ) > 1 does not work, because
		// users can end in offest=2, limit=5 and can't see the first
		// two messages. That's also why it is capped into zero with
		// max(). And finally make the offsets to be strings even if
		// they are numbers in this case.
		if ( $offset > 0 ) {
			$backwardsOffset = (string)( max( 0, $offset - $limit ) );
		}

		// Forwards paging uses keys. If user opens view Untranslated,
		// translates some messages and then clicks next, the first
		// message visible in the page is the first message not shown
		// in the previous page (unless someone else translated it at
		// the same time). If we used integer offsets, we would skip
		// same number of messages that were translated, because they
		// are no longer in the list. For backwards paging this is not
		// such a big issue, so it still uses integer offsets, because
		// we would need to also implement "direction" to have it work
		// correctly.
		if ( isset( $indexes[$offset + $limit] ) ) {
			$forwardsOffset = $indexes[$offset + $limit];
		}

		$this->keys = array_slice( $this->keys, $offset, $limit, true );

		return [ $backwardsOffset, $forwardsOffset, $offset ];
	}

	/**
	 * Filters messages based on some condition. Some filters cause data to be
	 * loaded from the database. PAGEINFO: existence and fuzzy tags.
	 * TRANSLATIONS: translations for every message. It is recommended to first
	 * filter with messages that do not need those. It is recommended to add
	 * translations from file with addInfile, and it is needed for changed
	 * filter to work.
	 *
	 * @param string $type
	 *  - fuzzy: messages with fuzzy tag (PAGEINFO)
	 *  - optional: messages marked for optional.
	 *  - ignored: messages which are not for translation.
	 *  - hastranslation: messages which have translation (be if fuzzy or not)
	 *    (PAGEINFO, *INFILE).
	 *  - translated: messages which have translation which is not fuzzy
	 *    (PAGEINFO, *INFILE).
	 *  - changed: translation in database differs from infile.
	 *    (INFILE, TRANSLATIONS)
	 * @param bool $condition Whether to return messages which do not satisfy
	 * the given filter condition (true), or only which do (false).
	 * @param int|null $value Value for properties filtering.
	 * @throws MWException If given invalid filter name.
	 */
	public function filter( string $type, bool $condition = true, ?int $value = null ): void {
		if ( !in_array( $type, self::getAvailableFilters(), true ) ) {
			throw new MWException( "Unknown filter $type" );
		}
		$this->applyFilter( $type, $condition, $value );
	}

	private static function getAvailableFilters(): array {
		return [
			'fuzzy',
			'optional',
			'ignored',
			'hastranslation',
			'changed',
			'translated',
			'reviewer',
			'last-translator',
		];
	}

	/**
	 * Really apply a filter. Some filters need multiple conditions.
	 * @param string $filter Filter name.
	 * @param bool $condition Whether to return messages which do not satisfy
	 * @param int|null $value Value for properties filtering.
	 * the given filter condition (true), or only which do (false).
	 * @throws MWException
	 */
	private function applyFilter( string $filter, bool $condition, ?int $value ): void {
		$keys = $this->keys;
		if ( $filter === 'fuzzy' ) {
			$keys = $this->filterFuzzy( $keys, $condition );
		} elseif ( $filter === 'hastranslation' ) {
			$keys = $this->filterHastranslation( $keys, $condition );
		} elseif ( $filter === 'translated' ) {
			$fuzzy = $this->filterFuzzy( $keys, false );
			$hastranslation = $this->filterHastranslation( $keys, false );
			// Fuzzy messages are not counted as translated messages
			$translated = $this->filterOnCondition( $hastranslation, $fuzzy );
			$keys = $this->filterOnCondition( $keys, $translated, $condition );
		} elseif ( $filter === 'changed' ) {
			$keys = $this->filterChanged( $keys, $condition );
		} elseif ( $filter === 'reviewer' ) {
			$keys = $this->filterReviewer( $keys, $condition, $value );
		} elseif ( $filter === 'last-translator' ) {
			$keys = $this->filterLastTranslator( $keys, $condition, $value );
		} else {
			// Filter based on tags.
			if ( !isset( $this->tags[$filter] ) ) {
				if ( $filter !== 'optional' && $filter !== 'ignored' ) {
					throw new MWException( "No tagged messages for custom filter $filter" );
				}
				$keys = $this->filterOnCondition( $keys, [], $condition );
			} else {
				$taggedKeys = array_flip( $this->tags[$filter] );
				$keys = $this->filterOnCondition( $keys, $taggedKeys, $condition );
			}
		}

		$this->keys = $keys;
	}

	/** @internal For MessageGroupStats */
	public function filterUntranslatedOptional(): void {
		$optionalKeys = array_flip( $this->tags['optional'] ?? [] );
		// Convert plain message keys to array<string,TitleValue>
		$optional = $this->filterOnCondition( $this->keys, $optionalKeys, false );
		// Then get reduce that list to those which have no translation. Ensure we don't
		// accidentally populate the info cache with too few keys.
		$this->loadInfo( $this->keys );
		$untranslatedOptional = $this->filterHastranslation( $optional, true );
		// Now remove that list from the full list
		$this->keys = $this->filterOnCondition( $this->keys, $untranslatedOptional );
	}

	/**
	 * Filters list of keys with other list of keys according to the condition.
	 * In other words, you have a list of keys, and you have determined list of
	 * keys that have some feature. Now you can either take messages that are
	 * both in the first list and the second list OR are in the first list but
	 * are not in the second list (conditition = false and true respectively).
	 * What makes this more complex is that second list of keys might not be a
	 * subset of the first list of keys.
	 * @param string[] $keys List of keys to filter.
	 * @param string[] $condKeys Second list of keys for filtering.
	 * @param bool $condition True (default) to return keys which are on first
	 * but not on the second list, false to return keys which are on both.
	 * second.
	 * @return string[] Filtered keys.
	 */
	private function filterOnCondition( array $keys, array $condKeys, bool $condition = true ): array {
		if ( $condition ) {
			// Delete $condKeys from $keys
			foreach ( array_keys( $condKeys ) as $key ) {
				unset( $keys[$key] );
			}
		} else {
			// Keep the keys which are in $condKeys
			foreach ( array_keys( $keys ) as $key ) {
				if ( !isset( $condKeys[$key] ) ) {
					unset( $keys[$key] );
				}
			}
		}

		return $keys;
	}

	/**
	 * Filters list of keys according to whether the translation is fuzzy.
	 * @param string[] $keys List of keys to filter.
	 * @param bool $condition True to filter away fuzzy translations, false
	 * to filter non-fuzzy translations.
	 * @return string[] Filtered keys.
	 */
	private function filterFuzzy( array $keys, bool $condition ): array {
		$this->loadInfo( $keys );

		$origKeys = [];
		if ( !$condition ) {
			$origKeys = $keys;
		}

		foreach ( $this->dbInfo as $row ) {
			if ( $row->rt_type !== null ) {
				unset( $keys[$this->rowToKey( $row )] );
			}
		}

		if ( !$condition ) {
			$keys = array_diff( $origKeys, $keys );
		}

		return $keys;
	}

	/**
	 * Filters list of keys according to whether they have a translation.
	 * @param string[] $keys List of keys to filter.
	 * @param bool $condition True to filter away translated, false
	 * to filter untranslated.
	 * @return string[] Filtered keys.
	 */
	private function filterHastranslation( array $keys, bool $condition ): array {
		$this->loadInfo( $keys );

		$origKeys = [];
		if ( !$condition ) {
			$origKeys = $keys;
		}

		foreach ( $this->dbInfo as $row ) {
			unset( $keys[$this->rowToKey( $row )] );
		}

		// Check also if there is something in the file that is not yet in the database
		foreach ( array_keys( $this->infile ) as $inf ) {
			unset( $keys[$inf] );
		}

		// Remove the messages which do not have a translation from the list
		if ( !$condition ) {
			$keys = array_diff( $origKeys, $keys );
		}

		return $keys;
	}

	/**
	 * Filters list of keys according to whether the current translation
	 * differs from the commited translation.
	 * @param string[] $keys List of keys to filter.
	 * @param bool $condition True to filter changed translations, false
	 * to filter unchanged translations.
	 * @return string[] Filtered keys.
	 */
	private function filterChanged( array $keys, bool $condition ): array {
		$this->loadData( $keys );

		$origKeys = [];
		if ( !$condition ) {
			$origKeys = $keys;
		}

		$revStore = MediaWikiServices::getInstance()->getRevisionStore();
		$infileRows = [];
		foreach ( $this->dbData as $row ) {
			$mkey = $this->rowToKey( $row );
			if ( isset( $this->infile[$mkey] ) ) {
				$infileRows[] = $row;
			}
		}

		$revisions = $revStore->newRevisionsFromBatch( $infileRows, [
			'slots' => [ SlotRecord::MAIN ],
			'content' => true
		] )->getValue();
		foreach ( $infileRows as $row ) {
			/** @var RevisionRecord|null $rev */
			$rev = $revisions[$row->rev_id];
			if ( $rev ) {
				/** @var TextContent $content */
				$content = $rev->getContent( SlotRecord::MAIN );
				if ( $content ) {
					$mkey = $this->rowToKey( $row );
					if ( $this->infile[$mkey] === $content->getText() ) {
						// Remove unchanged messages from the list
						unset( $keys[$mkey] );
					}
				}
			}
		}

		// Remove the messages which have changed from the original list
		if ( !$condition ) {
			$keys = $this->filterOnCondition( $origKeys, $keys );
		}

		return $keys;
	}

	/**
	 * Filters list of keys according to whether the user has accepted them.
	 * @param string[] $keys List of keys to filter.
	 * @param bool $condition True to remove translatations $user has accepted,
	 * false to get only translations accepted by $user.
	 * @param ?int $userId
	 * @return string[] Filtered keys.
	 */
	private function filterReviewer( array $keys, bool $condition, ?int $userId ): array {
		$this->loadReviewInfo( $keys );
		$origKeys = $keys;

		/* This removes messages from the list which have certain
		 * reviewer (among others) */
		foreach ( $this->dbReviewData as $row ) {
			if ( $userId === null || (int)$row->trr_user === $userId ) {
				unset( $keys[$this->rowToKey( $row )] );
			}
		}

		if ( !$condition ) {
			$keys = array_diff( $origKeys, $keys );
		}

		return $keys;
	}

	/**
	 * @param string[] $keys List of keys to filter.
	 * @param bool $condition True to remove translatations where last translator is $user
	 * false to get only last translations done by others.
	 * @return string[] Filtered keys.
	 */
	private function filterLastTranslator( array $keys, bool $condition, ?int $userId ): array {
		$this->loadData( $keys );
		$origKeys = $keys;

		$userId = $userId ?? 0;
		foreach ( $this->dbData as $row ) {
			if ( (int)$row->rev_user === $userId ) {
				unset( $keys[$this->rowToKey( $row )] );
			}
		}

		if ( !$condition ) {
			$keys = array_diff( $origKeys, $keys );
		}

		return $keys;
	}

	/**
	 * Takes list of keys and converts them into database format.
	 * @return array ( string => string ) Array of keys in database format indexed by display format.
	 */
	private function fixKeys(): array {
		$newkeys = [];

		$pages = $this->definitions->getPages();
		foreach ( $pages as $key => $baseTitle ) {
			$newkeys[$key] = new TitleValue(
				$baseTitle->getNamespace(),
				$baseTitle->getDBkey() . '/' . $this->code
			);
		}

		return $newkeys;
	}

	/**
	 * Loads existence and fuzzy state for given list of keys.
	 * @param string[] $keys List of keys in database format.
	 * @param string[]|null $titleConds Database query condition based on current keys.
	 */
	private function loadInfo( array $keys, ?array $titleConds = null ): void {
		if ( !$this->dbInfo instanceof EmptyIterator ) {
			return;
		}

		if ( !count( $keys ) ) {
			$this->dbInfo = new EmptyIterator();
			return;
		}

		$dbr = Utilities::getSafeReadDB();
		$tables = [ 'page', 'revtag' ];
		$fields = [ 'page_namespace', 'page_title', 'rt_type' ];
		$joins = [ 'revtag' =>
		[
			'LEFT JOIN',
			[ 'page_id=rt_page', 'page_latest=rt_revision', 'rt_type' => RevTagStore::FUZZY_TAG ]
		]
		];

		$titleConds = $titleConds ?? $this->getTitleConds( $dbr );
		$iterator = new AppendIterator();
		foreach ( $titleConds as $conds ) {
			$iterator->append( $dbr->select( $tables, $fields, $conds, __METHOD__, [], $joins ) );
		}

		$this->dbInfo = $iterator;

		// Populate and cache reverse map now, since if call to initMesages is delayed (e.g. a
		// filter that calls loadData() is used, or ::slice is used) the reverse map will not
		// contain all the entries that are present in our $iterator and will throw notices.
		$this->getReverseMap();
	}

	/**
	 * Loads reviewers for given messages.
	 * @param string[] $keys List of keys in database format.
	 * @param string[]|null $titleConds Database query condition based on current keys.
	 */
	private function loadReviewInfo( array $keys, ?array $titleConds = null ): void {
		if ( !$this->dbReviewData instanceof EmptyIterator ) {
			return;
		}

		if ( !count( $keys ) ) {
			$this->dbReviewData = new EmptyIterator();
			return;
		}

		$dbr = Utilities::getSafeReadDB();
		$tables = [ 'page', 'translate_reviews' ];
		$fields = [ 'page_namespace', 'page_title', 'trr_user' ];
		$joins = [ 'translate_reviews' =>
			[
				'JOIN',
				[ 'page_id=trr_page', 'page_latest=trr_revision' ]
			]
		];

		$titleConds = $titleConds ?? $this->getTitleConds( $dbr );
		$iterator = new AppendIterator();
		foreach ( $titleConds as $conds ) {
			$iterator->append( $dbr->select( $tables, $fields, $conds, __METHOD__, [], $joins ) );
		}

		$this->dbReviewData = $iterator;

		// Populate and cache reverse map now, since if call to initMesages is delayed (e.g. a
		// filter that calls loadData() is used, or ::slice is used) the reverse map will not
		// contain all the entries that are present in our $iterator and will throw notices.
		$this->getReverseMap();
	}

	/**
	 * Loads translation for given list of keys.
	 * @param string[] $keys List of keys in database format.
	 * @param string[]|null $titleConds Database query condition based on current keys.
	 */
	private function loadData( array $keys, ?array $titleConds = null ): void {
		if ( !$this->dbData instanceof EmptyIterator ) {
			return;
		}

		if ( !count( $keys ) ) {
			$this->dbData = new EmptyIterator();
			return;
		}

		$dbr = Utilities::getSafeReadDB();
		$revisionStore = MediaWikiServices::getInstance()->getRevisionStore();
		$revQuery = $revisionStore->getQueryInfo( [ 'page' ] );
		$tables = $revQuery['tables'];
		$fields = $revQuery['fields'];
		$joins = $revQuery['joins'];

		$titleConds = $titleConds ?? $this->getTitleConds( $dbr );
		$iterator = new AppendIterator();
		foreach ( $titleConds as $conds ) {
			$conds = [ 'page_latest = rev_id', $conds ];
			$iterator->append( $dbr->select( $tables, $fields, $conds, __METHOD__, [], $joins ) );
		}

		$this->dbData = $iterator;

		// Populate and cache reverse map now, since if call to initMesages is delayed (e.g. a
		// filter that calls loadData() is used, or ::slice is used) the reverse map will not
		// contain all the entries that are present in our $iterator and will throw notices.
		$this->getReverseMap();
	}

	/**
	 * Of the current set of keys, construct database query conditions.
	 * @return string[]
	 */
	private function getTitleConds( IDatabase $db ): array {
		$titles = $this->getTitles();
		$chunks = array_chunk( $titles, self::MAX_ITEMS_PER_QUERY );
		$results = [];

		foreach ( $chunks as $titles ) {
			// Array of array( namespace, pagename )
			$byNamespace = [];
			foreach ( $titles as $title ) {
				$namespace = $title->getNamespace();
				$pagename = $title->getDBkey();
				$byNamespace[$namespace][] = $pagename;
			}

			$conds = [];
			foreach ( $byNamespace as $namespaces => $pagenames ) {
				$cond = [
					'page_namespace' => $namespaces,
					'page_title' => $pagenames,
				];

				$conds[] = $db->makeList( $cond, LIST_AND );
			}

			$results[] = $db->makeList( $conds, LIST_OR );
		}

		return $results;
	}

	/**
	 * Given two-dimensional map of namespace and pagenames, this uses
	 * database fields page_namespace and page_title as keys and returns
	 * the value for those indexes.
	 */
	private function rowToKey( stdClass $row ): ?string {
		$map = $this->getReverseMap();
		if ( isset( $map[$row->page_namespace][$row->page_title] ) ) {
			return $map[$row->page_namespace][$row->page_title];
		} else {
			wfWarn( "Got unknown title from the database: {$row->page_namespace}:{$row->page_title}" );

			return null;
		}
	}

	/** Creates a two-dimensional map of namespace and pagenames. */
	private function getReverseMap(): array {
		if ( isset( $this->reverseMap ) ) {
			return $this->reverseMap;
		}

		$map = [];
		/** @var TitleValue $title */
		foreach ( $this->keys as $mkey => $title ) {
			$map[$title->getNamespace()][$title->getDBkey()] = $mkey;
		}

		$this->reverseMap = $map;
		return $this->reverseMap;
	}

	/**
	 * Constructs all Messages (ThinMessage) from the data accumulated so far.
	 * Usually there is no need to call this method directly.
	 */
	public function initMessages(): void {
		if ( $this->messages !== null ) {
			return;
		}

		$messages = [];
		$definitions = $this->definitions->getDefinitions();
		$revStore = MediaWikiServices::getInstance()->getRevisionStore();
		$queryFlags = Utilities::shouldReadFromPrimary() ? $revStore::READ_LATEST : 0;
		foreach ( array_keys( $this->keys ) as $mkey ) {
			$messages[$mkey] = new ThinMessage( $mkey, $definitions[$mkey] );
		}

		if ( !$this->dbData instanceof EmptyIterator ) {
			$slotRows = $revStore->getContentBlobsForBatch(
				$this->dbData, [ SlotRecord::MAIN ], $queryFlags
			)->getValue();

			foreach ( $this->dbData as $row ) {
				$mkey = $this->rowToKey( $row );
				if ( !isset( $messages[$mkey] ) ) {
					continue;
				}
				$messages[$mkey]->setRow( $row );
				$messages[$mkey]->setProperty( 'revision', $row->page_latest );

				if ( isset( $slotRows[$row->rev_id][SlotRecord::MAIN] ) ) {
					$slot = $slotRows[$row->rev_id][SlotRecord::MAIN];
					$messages[$mkey]->setTranslation( $slot->blob_data );
				}
			}
		}

		$fuzzy = [];
		foreach ( $this->dbInfo as $row ) {
			if ( $row->rt_type !== null ) {
				$fuzzy[] = $this->rowToKey( $row );
			}
		}

		$this->setTags( 'fuzzy', $fuzzy );

		// Copy tags if any.
		foreach ( $this->tags as $type => $keys ) {
			foreach ( $keys as $mkey ) {
				if ( isset( $messages[$mkey] ) ) {
					$messages[$mkey]->addTag( $type );
				}
			}
		}

		// Copy properties if any.
		foreach ( $this->properties as $type => $keys ) {
			foreach ( $keys as $mkey => $value ) {
				if ( isset( $messages[$mkey] ) ) {
					$messages[$mkey]->setProperty( $type, $value );
				}
			}
		}

		// Copy infile if any.
		foreach ( $this->infile as $mkey => $value ) {
			if ( isset( $messages[$mkey] ) ) {
				$messages[$mkey]->setInfile( $value );
			}
		}

		foreach ( $this->dbReviewData as $row ) {
			$mkey = $this->rowToKey( $row );
			if ( !isset( $messages[$mkey] ) ) {
				continue;
			}
			$messages[$mkey]->appendProperty( 'reviewers', $row->trr_user );
		}

		// Set the status property
		foreach ( $messages as $obj ) {
			if ( $obj->hasTag( 'fuzzy' ) ) {
				$obj->setProperty( 'status', 'fuzzy' );
			} elseif ( is_array( $obj->getProperty( 'reviewers' ) ) ) {
				$obj->setProperty( 'status', 'proofread' );
			} elseif ( $obj->translation() !== null ) {
				$obj->setProperty( 'status', 'translated' );
			} else {
				$obj->setProperty( 'status', 'untranslated' );
			}
		}

		$this->messages = $messages;
	}

	/**
	 * ArrayAccess methods. @{
	 * @param mixed $offset
	 */
	public function offsetExists( $offset ): bool {
		return isset( $this->keys[$offset] );
	}

	/** @param mixed $offset */
	public function offsetGet( $offset ): ?Message {
		return $this->messages[$offset] ?? null;
	}

	/**
	 * @param mixed $offset
	 * @param mixed $value
	 */
	public function offsetSet( $offset, $value ): void {
		$this->messages[$offset] = $value;
	}

	/** @param mixed $offset */
	public function offsetUnset( $offset ): void {
		unset( $this->keys[$offset] );
	}

	/** @} */

	/**
	 * Fail fast if trying to access unknown properties. @{
	 * @return never
	 * @throws MWException
	 */
	public function __get( string $name ): void {
		throw new MWException( __METHOD__ . ": Trying to access unknown property $name" );
	}

	/**
	 * Fail fast if trying to access unknown properties.
	 * @param mixed $value
	 * @return never
	 * @throws MWException
	 */
	public function __set( string $name, $value ): void {
		throw new MWException( __METHOD__ . ": Trying to modify unknown property $name" );
	}

	/** @} */

	/**
	 * Iterator method. @{
	 */
	public function rewind(): void {
		reset( $this->keys );
	}

	#[\ReturnTypeWillChange]
	public function current() {
		if ( !count( $this->keys ) ) {
			return false;
		}

		// @phan-suppress-next-line PhanTypeArraySuspiciousNullable
		return $this->messages[key( $this->keys )];
	}

	public function key(): ?string {
		return key( $this->keys );
	}

	public function next(): void {
		next( $this->keys );
	}

	public function valid(): bool {
		return isset( $this->messages[key( $this->keys )] );
	}

	public function count(): int {
		return count( $this->keys() );
	}

	/** @} */
}
