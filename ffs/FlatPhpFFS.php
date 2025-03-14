<?php
/**
 * PHP variables file format handler.
 *
 * @file
 * @author Niklas Laxström
 * @author Siebrand Mazeland
 * @copyright Copyright © 2008-2010, Niklas Laxström, Siebrand Mazeland
 * @license GPL-2.0-or-later
 */

use MediaWiki\Extension\Translate\MessageGroupConfiguration\MetaYamlSchemaExtender;
use MediaWiki\Extension\Translate\MessageLoading\Message;
use MediaWiki\Extension\Translate\MessageLoading\MessageCollection;
use MediaWiki\Extension\Translate\Utilities\Utilities;

/**
 * Implements file format support for PHP files which consist of multiple
 * variable assignments.
 */
class FlatPhpFFS extends SimpleFFS implements MetaYamlSchemaExtender {
	public function getFileExtensions(): array {
		return [ '.php' ];
	}

	/**
	 * @param string $data
	 * @return array Parsed data.
	 */
	public function readFromVariable( $data ): array {
		# Authors first
		$matches = [];
		preg_match_all( '/^ \* @author\s+(.+)$/m', $data, $matches );
		$authors = $matches[1];

		# Then messages
		$matches = [];
		$regex = '/^\$(.*?)\s*=\s*[\'"](.*?)[\'"];.*?$/mus';
		preg_match_all( $regex, $data, $matches, PREG_SET_ORDER );
		$messages = [];

		foreach ( $matches as $_ ) {
			$legal = Title::legalChars();
			$key = preg_replace_callback( "/([^$legal]|\\\\)/u",
				static function ( $m ) {
					return '\x' . dechex( ord( $m[0] ) );
				},
				$_[1]
			);
			$value = str_replace( [ "\'", "\\\\" ], [ "'", "\\" ], $_[2] );
			$messages[$key] = $value;
		}

		$messages = $this->group->getMangler()->mangleArray( $messages );

		return [
			'AUTHORS' => $authors,
			'MESSAGES' => $messages,
		];
	}

	protected function writeReal( MessageCollection $collection ) {
		$output = $this->extra['header'] ?? "<?php\n";
		$output .= $this->doHeader( $collection );

		$mangler = $this->group->getMangler();

		/** @var Message $item */
		foreach ( $collection as $item ) {
			$key = $mangler->unmangle( $item->key() );
			$key = stripcslashes( $key );

			$value = $item->translation();
			if ( $value === null ) {
				continue;
			}

			$value = str_replace( TRANSLATE_FUZZY, '', $value );
			$value = addcslashes( $value, "'" );

			$output .= "\$$key = '$value';\n";
		}

		return $output;
	}

	protected function doHeader( MessageCollection $collection ) {
		global $wgServer, $wgTranslateDocumentationLanguageCode;

		$code = $collection->code;
		$name = Utilities::getLanguageName( $code );
		$native = Utilities::getLanguageName( $code, $code );

		if ( $wgTranslateDocumentationLanguageCode ) {
			$docu = "\n * See the $wgTranslateDocumentationLanguageCode 'language' for " .
				'message documentation incl. usage of parameters';
		} else {
			$docu = '';
		}

		$authors = $this->doAuthors( $collection );

		$output =
			<<<PHP
			/** $name ($native)
			 * $docu
			 * To improve a translation please visit $wgServer
			 *
			 * @ingroup Language
			 * @file
			 *
			$authors */


			PHP;

		return $output;
	}

	protected function doAuthors( MessageCollection $collection ) {
		$output = '';
		$authors = $collection->getAuthors();
		$authors = $this->filterAuthors( $authors, $collection->code );

		foreach ( $authors as $author ) {
			$output .= " * @author $author\n";
		}

		return $output;
	}

	public static function getExtraSchema(): array {
		$schema = [
			'root' => [
				'_type' => 'array',
				'_children' => [
					'FILES' => [
						'_type' => 'array',
						'_children' => [
							'header' => [
								'_type' => 'text',
							],
						]
					]
				]
			]
		];

		return $schema;
	}
}
