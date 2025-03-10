<?php
declare( strict_types = 1 );

namespace MediaWiki\Extension\Translate\PageTranslation;

use DifferenceEngine;
use Html;
use SpecialPage;

/**
 * Contains code to prepare a page for translation
 * @author Pratik Lahoti
 * @license GPL-2.0-or-later
 */
class PrepareTranslatablePageSpecialPage extends SpecialPage {
	public function __construct() {
		parent::__construct( 'PagePreparation', 'pagetranslation' );
	}

	protected function getGroupName() {
		return 'translation';
	}

	public function execute( $par ) {
		$request = $this->getRequest();
		$output = $this->getOutput();
		$this->setHeaders();
		$this->checkPermissions();
		$this->outputHeader();
		$inputValue = htmlspecialchars( $request->getText( 'page', $par ?? '' ) );
		$pagenamePlaceholder = $this->msg( 'pp-pagename-placeholder' )->escaped();
		$prepareButtonValue = $this->msg( 'pp-prepare-button-label' )->escaped();
		$saveButtonValue = $this->msg( 'pp-save-button-label' )->escaped();
		$cancelButtonValue = $this->msg( 'pp-cancel-button-label' )->escaped();
		$summaryValue = $this->msg( 'pp-save-summary' )->inContentLanguage()->escaped();
		$output->addModules( 'ext.translate.special.pagepreparation' );
		$output->addModuleStyles( [
			'ext.translate.specialpages.styles',
			'jquery.uls.grid'
		] );

		$diff = new DifferenceEngine( $this->getContext() );
		$diffHeader = $diff->addHeader( ' ', $this->msg( 'pp-diff-old-header' )->escaped(),
			$this->msg( 'pp-diff-new-header' )->escaped() );

		$output->addHTML(
			<<<HTML
			<div class="mw-tpp-sp-container grid">
				<form class="mw-tpp-sp-form row" name="mw-tpp-sp-input-form" action="">
					<input id="pp-summary" type="hidden" value="{$summaryValue}" />
					<input name="page" id="page" class="mw-searchInput mw-ui-input"
						placeholder="{$pagenamePlaceholder}" value="{$inputValue}"/>
					<button id="action-prepare" class="mw-ui-button mw-ui-progressive" type="button">
						{$prepareButtonValue}</button>
					<button id="action-save" class="mw-ui-button mw-ui-progressive hide" type="button">
						{$saveButtonValue}</button>
					<button id="action-cancel" class="mw-ui-button mw-ui-quiet hide" type="button">
						{$cancelButtonValue}</button>
				</form>
				<div class="messageDiv hide"></div>
				<div class="divDiff hide">
					{$diffHeader}
				</div>
			</div>
			HTML
		);
		$output->addHTML(
			Html::errorBox(
				$this->msg( 'tux-nojs' )->plain(),
				'',
				'tux-nojs'
			)
		);
	}
}
