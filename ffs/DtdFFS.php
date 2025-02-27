<?php
/**
 * Implements FFS for DTD file format.
 *
 * @file
 * @author Guillaume Duhamel
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @copyright Copyright © 2009-2010, Guillaume Duhamel, Niklas Laxström, Siebrand Mazeland
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\MessageLoading\Message;
use MediaWiki\Extension\Translate\MessageLoading\MessageCollection;
use MediaWiki\Extension\Translate\Utilities\Utilities;

/**
 * File format support for DTD.
 *
 * @ingroup FileFormatSupport
 */
class DtdFFS extends SimpleFFS {
	public function getFileExtensions(): array {
		return [ '.dtd' ];
	}

	/**
	 * @param string $data
	 * @return array Parsed data.
	 */
	public function readFromVariable( $data ): array {
		preg_match_all( ',# Author: ([^\n]+)\n,', $data, $matches );
		$authors = $matches[1];

		preg_match_all( ',<!ENTITY[ ]+([^ ]+)\s+"([^"]+)"[^>]*>,', $data, $matches );
		list( , $keys, $messages ) = $matches;
		$messages = array_combine(
			$keys,
			array_map(
				static function ( $message ) {
					return html_entity_decode( $message, ENT_QUOTES );
				},
				$messages
			)
		);

		$messages = $this->group->getMangler()->mangleArray( $messages );

		return [
			'AUTHORS' => $authors,
			'MESSAGES' => $messages,
		];
	}

	protected function writeReal( MessageCollection $collection ) {
		$collection->loadTranslations();

		$header = "<!--\n";
		$header .= $this->doHeader( $collection );
		$header .= $this->doAuthors( $collection );
		$header .= "-->\n";

		$output = '';
		$mangler = $this->group->getMangler();

		/** @var Message $m */
		foreach ( $collection as $key => $m ) {
			$key = $mangler->unmangle( $key );
			$trans = $m->translation();
			$trans = str_replace( TRANSLATE_FUZZY, '', $trans );

			if ( $trans === '' ) {
				continue;
			}

			$trans = str_replace( '"', '&quot;', $trans );
			$output .= "<!ENTITY $key \"$trans\">\n";
		}

		if ( $output ) {
			return $header . $output;
		}

		return '';
	}

	protected function doHeader( MessageCollection $collection ) {
		global $wgSitename;

		$code = $collection->code;
		$name = Utilities::getLanguageName( $code );
		$native = Utilities::getLanguageName( $code, $code );

		$output = "# Messages for $name ($native)\n";
		$output .= "# Exported from $wgSitename\n\n";

		return $output;
	}

	protected function doAuthors( MessageCollection $collection ) {
		$output = '';
		$authors = $collection->getAuthors();
		$authors = $this->filterAuthors( $authors, $collection->code );

		foreach ( $authors as $author ) {
			$output .= "# Author: $author\n";
		}

		return $output;
	}
}
