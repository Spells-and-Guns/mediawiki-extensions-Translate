<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\MessageGroupProcessing;

use AggregateMessageGroup;
use ApiQuery;
use ApiQueryBase;
use MediaWiki\Extension\Translate\MessageProcessing\StringMatcher;
use MediaWiki\Extension\Translate\Utilities\Utilities;
use MediaWiki\HookContainer\HookContainer;
use MessageGroup;
use TranslateMetadata;
use Wikimedia\ParamValidator\ParamValidator;
use WikiPageMessageGroup;

/**
 * Api module for querying MessageGroups.
 * @author Niklas Laxström
 * @author Harry Burt
 * @copyright Copyright © 2012-2013, Harry Burt
 * @license GPL-2.0-or-later
 * @ingroup API TranslateAPI
 */
class QueryMessageGroupsActionApi extends ApiQueryBase {
	/** @var HookContainer */
	private $hookContainer;

	public function __construct(
		ApiQuery $query,
		string $moduleName,
		HookContainer $hookContainer
	) {
		parent::__construct( $query, $moduleName, 'mg' );
		$this->hookContainer = $hookContainer;
	}

	public function execute(): void {
		$params = $this->extractRequestParams();
		$filter = $params['filter'];

		$groups = [];
		$props = array_flip( $params['prop'] );

		$needsMetadata = isset( $props['prioritylangs'] ) || isset( $props['priorityforce'] );

		// Parameter root as all for all pages subgroups
		if ( $params['root'] === 'all' ) {
			$allGroups = MessageGroups::getAllGroups();
			foreach ( $allGroups as $id => $group ) {
				if ( $group instanceof WikiPageMessageGroup ) {
					$groups[$id] = $group;
				}
			}
		} elseif ( $params['format'] === 'flat' ) {
			if ( $params['root'] !== '' ) {
				$group = MessageGroups::getGroup( $params['root'] );
				if ( $group ) {
					$groups[$params['root']] = $group;
				}
			} else {
				$groups = MessageGroups::getAllGroups();
				usort( $groups, [ MessageGroups::class, 'groupLabelSort' ] );
			}
			TranslateMetadata::preloadGroups( array_keys( $groups ), __METHOD__ );
		} elseif ( $params['root'] !== '' ) {
			// format=tree from now on, as it is the only other valid option
			$group = MessageGroups::getGroup( $params['root'] );
			if ( $group instanceof AggregateMessageGroup ) {
				$childIds = [];
				$groups = MessageGroups::subGroups( $group, $childIds );
				// The parent group is the first, ignore it
				array_shift( $groups );
			}
		} else {
			$groups = MessageGroups::getGroupStructure();
		}

		if ( $needsMetadata && $groups ) {
			TranslateMetadata::preloadGroups( array_keys( $groups ), __METHOD__ );
		}

		if ( $params['root'] === '' ) {
			$dynamicGroups = [];
			foreach ( array_keys( MessageGroups::getDynamicGroups() ) as $id ) {
				$dynamicGroups[$id] = MessageGroups::getGroup( $id );
			}
			// Have dynamic groups appear first in the list
			$groups = $dynamicGroups + $groups;
		}
		'@phan-var (MessageGroup|array)[] $groups';

		// Do not list the sandbox group. The code that knows it
		// exists can access it directly.
		if ( isset( $groups['!sandbox'] ) ) {
			unset( $groups['!sandbox'] );
		}

		$result = $this->getResult();
		$matcher = new StringMatcher( '', $filter );
		/** @var MessageGroup|array $mixed */
		foreach ( $groups as $mixed ) {
			// array when Format = tree
			$group = is_array( $mixed ) ? reset( $mixed ) : $mixed;
			if ( $filter !== [] && !$matcher->matches( $group->getId() ) ) {
				continue;
			}

			if (
				$params['languageFilter'] !== '' &&
				TranslateMetadata::isExcluded( $group->getId(), $params['languageFilter'] )
			) {
				continue;
			}

			$a = $this->formatGroup( $mixed, $props );

			$result->setIndexedTagName( $a, 'group' );

			// @todo Add a continue?
			$fit = $result->addValue( [ 'query', $this->getModuleName() ], null, $a );
			if ( !$fit ) {
				// Even if we're not going to give a continue, no point carrying on
				// if the result is full
				break;
			}
		}

		$result->addIndexedTagName( [ 'query', $this->getModuleName() ], 'group' );
	}

	/**
	 * @param array|MessageGroup $mixed
	 * @param array $props List of props as the array keys
	 * @param int $depth
	 */
	protected function formatGroup( $mixed, array $props, int $depth = 0 ): array {
		$params = $this->extractRequestParams();
		$context = $this->getContext();

		// Default
		$g = $mixed;
		$subgroups = [];

		// Format = tree and has subgroups
		if ( is_array( $mixed ) ) {
			$g = array_shift( $mixed );
			$subgroups = $mixed;
		}

		$a = [];

		$groupId = $g->getId();

		if ( isset( $props['id'] ) ) {
			$a['id'] = $groupId;
		}

		if ( isset( $props['label'] ) ) {
			$a['label'] = $g->getLabel( $context );
		}

		if ( isset( $props['description'] ) ) {
			$a['description'] = $g->getDescription( $context );
		}

		if ( isset( $props['class'] ) ) {
			$a['class'] = get_class( $g );
		}

		if ( isset( $props['namespace'] ) ) {
			$a['namespace'] = $g->getNamespace();
		}

		if ( isset( $props['exists'] ) ) {
			$a['exists'] = $g->exists();
		}

		if ( isset( $props['icon'] ) ) {
			$formats = Utilities::getIcon( $g, $params['iconsize'] );
			if ( $formats ) {
				$a['icon'] = $formats;
			}
		}

		if ( isset( $props['priority'] ) ) {
			$priority = MessageGroups::getPriority( $g );
			$a['priority'] = $priority ?: 'default';
		}

		if ( isset( $props['prioritylangs'] ) ) {
			$prioritylangs = TranslateMetadata::get( $groupId, 'prioritylangs' );
			$a['prioritylangs'] = $prioritylangs ? explode( ',', $prioritylangs ) : false;
		}

		if ( isset( $props['priorityforce'] ) ) {
			$a['priorityforce'] = ( TranslateMetadata::get( $groupId, 'priorityforce' ) === 'on' );
		}

		if ( isset( $props['workflowstates'] ) ) {
			$a['workflowstates'] = $this->getWorkflowStates( $g );
		}

		if ( isset( $props['sourcelanguage'] ) ) {
			$a['sourcelanguage'] = $g->getSourceLanguage();
		}

		$this->hookContainer->run(
			'TranslateProcessAPIMessageGroupsProperties',
			[ &$a, $props, $params, $g ]
		);

		// Depth only applies to tree format
		if ( $depth >= $params['depth'] && $params['format'] === 'tree' ) {
			$a['groupcount'] = count( $subgroups );

			// Prevent going further down in the three
			return $a;
		}

		// Always empty array for flat format, only sometimes for tree format
		if ( $subgroups !== [] ) {
			foreach ( $subgroups as $sg ) {
				$a['groups'][] = $this->formatGroup( $sg, $props );
			}
			$result = $this->getResult();
			$result->setIndexedTagName( $a['groups'], 'group' );
		}

		return $a;
	}

	/**
	 * Get the workflow states applicable to the given message group
	 * @return bool|array Associative array with states as key and localized state
	 * labels as values
	 */
	private function getWorkflowStates( MessageGroup $group ) {
		if ( MessageGroups::isDynamic( $group ) ) {
			return false;
		}

		$stateConfig = $group->getMessageGroupStates()->getStates();

		if ( !is_array( $stateConfig ) || $stateConfig === [] ) {
			return false;
		}

		$user = $this->getUser();

		foreach ( $stateConfig as $state => $config ) {
			if ( is_array( $config ) ) {
				// Check if user is allowed to change states generally
				$allowed = $user->isAllowed( 'translate-groupreview' );
				// Check further restrictions
				if ( $allowed && isset( $config['right'] ) ) {
					$allowed = $user->isAllowed( $config['right'] );
				}

				if ( $allowed ) {
					$stateConfig[$state]['canchange'] = 1;
				}

				$stateConfig[$state]['name'] =
					$this->msg( "translate-workflow-state-$state" )->text();
			}
		}

		return $stateConfig;
	}

	protected function getAllowedParams(): array {
		$allowedParams = [
			'depth' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 100,
			],
			'filter' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'format' => [
				ParamValidator::PARAM_TYPE => [ 'flat', 'tree' ],
				ParamValidator::PARAM_DEFAULT => 'flat',
			],
			'iconsize' => [
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_DEFAULT => 64,
			],
			'prop' => [
				ParamValidator::PARAM_TYPE => array_keys( $this->getPropertyList() ),
				ParamValidator::PARAM_DEFAULT => 'id|label|description|class|exists',
				ParamValidator::PARAM_ISMULTI => true,
			],
			'root' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			],
			'languageFilter' => [
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_DEFAULT => '',
			]
		];
		$this->hookContainer->run( 'TranslateGetAPIMessageGroupsParameterList', [ &$allowedParams ] );

		return $allowedParams;
	}

	/**
	 * Returns an array of properties and their descriptions. Descriptions are ignored.
	 * Descriptions come from apihelp-query+messagegroups-param-prop and that is not
	 * extensible.
	 */
	private function getPropertyList(): array {
		$properties = array_flip( [
			'id',
			'label',
			'description',
			'class',
			'namespace',
			'exists',
			'icon',
			'priority',
			'prioritylangs',
			'priorityforce',
			'workflowstates',
			'sourcelanguage'
		] );

		$this->hookContainer->run( 'TranslateGetAPIMessageGroupsPropertyDescs', [ &$properties ] );

		return $properties;
	}

	protected function getExamplesMessages(): array {
		return [
			'action=query&meta=messagegroups' => 'apihelp-query+messagegroups-example-1',
		];
	}
}
