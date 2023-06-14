<?php
/**
 * Class Article_XML
 *
 * @package eight-day-week
 */

/**
 * Class Article_XML
 *
 * @package Eight_Day_Week\Plugins\Article_Export
 *
 * Builds an XML DOMDocument based on a WP_Post
 */
class Article_XML {

	/**
	 * The article
	 *
	 * @var \WP_Post
	 */
	public $article;

	/**
	 * Sets object properties
	 *
	 * @param \WP_Post $article A post to export.
	 */
	public function __construct( \WP_Post $article ) {
		$this->article = $article;
		$this->id      = $article->ID;
	}

	/**
	 * Builds XML document from a WP_Post
	 *
	 * @return object DOMDocument + children elements for easy access
	 * @throws \Exception Various points of failure.
	 */
	public function build_xml() {

		global $post;

		// Store global post backup.
		$old = $post;

		// And set global post to this post.
		$post = $this->article; // phpcs:ignore

		$elements = apply_filters(
			__NAMESPACE__ . '\xml_outer_elements',
			array(
				'headline' => get_the_title( $this->article ),
			),
			$this->article
		);

		$dom = new \DOMDocument();

		$content = strip_shortcodes( $this->article->post_content );

		if ( ! $content ) {
			throw new \Exception( 'Post ' . $this->id . ' was empty.' );
		}

		@$dom->loadHTML(
			mb_convert_encoding(
				$content,
				apply_filters( __NAMESPACE__ . '\dom_encoding_from', 'HTML-ENTITIES' ),
				apply_filters( __NAMESPACE__ . '\dom_encoding_to', 'UTF-8' )
			)
		);

		// Perform dom manipulations.
		$dom = $this->manipulate_dom( $dom );

		// Do html_to_xml before adding elements so that the html wrap stuff is removed first.
		$xml_elements = $this->html_to_xml( $dom );

		if ( $elements ) {
			$this->add_outer_elements( $xml_elements, $elements );
		}

		$this->add_article_attributes( $xml_elements->root_element, $elements );

		// Reset global post.
		$post = $old; // phpcs:ignore

		return $xml_elements;
	}

	/**
	 * Appends various elements
	 *
	 * @param object $xml_elements XML Document + children.
	 * @param array  $outer_elements Elements to add to the root element.
	 */
	public function add_outer_elements( $xml_elements, $outer_elements ) {
		foreach ( $outer_elements as $tag_name => $value ) {
			if ( ! $value ) {
				continue;
			}
			$element            = $xml_elements->xml_document->createElement( $tag_name );
			$element->nodeValue = $value;
			$xml_elements->root_element->appendChild( $element );
		}
	}

	/**
	 * Get the post's first author's name
	 *
	 * @return string The author's name (last name if set, but has fallbacks)
	 */
	public function get_first_author_name() {
		if ( function_exists( 'get_coauthors' ) ) {
			$authors = get_coauthors( $this->id );
		} else {
			$authors = array( get_userdata( $this->id ) );
		}

		if ( ! $authors ) {
			return '';
		}

		$author = $authors[0];

		if ( ! $author ) {
			return '';
		}

		if ( $author->last_name ) {
			return $author->last_name;
		}

		return $author->display_name;
	}

	/**
	 * Adds the first author's name to the article element
	 *
	 * @param \DOMElement $article_element The root article element.
	 */
	public function add_author_name( $article_element ) {
		$article_element->setAttribute( 'author', html_entity_decode( $this->get_first_author_name() ) );
	}

	/**
	 * Adds the post title to the article element
	 *
	 * @param \DOMElement $article_element The root article element.
	 * @param string      $title The post title to add.
	 */
	public function add_post_title( $article_element, $title ) {
		$article_element->setAttribute( 'title', html_entity_decode( $title ) );
	}

	/**
	 * Adds various attributes to the article element
	 *
	 * @param \DOMElement $article_element The article element.
	 * @param array       $elements Predefined attribute values.
	 */
	public function add_article_attributes( $article_element, $elements ) {
		$this->add_author_name( $article_element );

		if ( isset( $elements['headline'] ) ) {
			$this->add_post_title( $article_element, $elements['headline'] );
		}
	}

	/**
	 * Performs various DOM manipulations
	 *
	 * @param \DOMDocument $dom The DOM.
	 *
	 * @return \DOMDocument The modified DOM
	 */
	public function manipulate_dom( $dom ) {
		$this->extract_elements_by_xpath( $dom );
		$this->remove_elements( $dom );

		// Allow third party modification of the entire dom.
		$dom = apply_filters( __NAMESPACE__ . '\dom', $dom );

		return $dom;
	}

	/**
	 * Removes elements from the DOM
	 * Doesn't need to return anything because the DOM is aliiiiiive
	 *
	 * @param \DOMDocument $dom The DOM.
	 *
	 * @throws \Exception Unable to remove a child element.
	 */
	public function remove_elements( $dom ) {
		$elements_to_remove = apply_filters( __NAMESPACE__ . '\remove_elements', array( 'img' ) );

		$remove = array();
		foreach ( $elements_to_remove as $tag_name ) {
			$found = $dom->getElementsByTagName( $tag_name );
			foreach ( $found as $el ) {
				$remove[ $tag_name ][] = $el;
			}
		}

		foreach ( $remove as $tag_name => $els ) {
			foreach ( $els as $el ) {
				try {
					$el->parentNode->removeChild( $el );
				} catch ( \Exception $e ) {
					throw new \Exception( $e->getMessage() );
				}
			}
		}
	}

	/**
	 * Extracts elements within the content to the root of the document via Xpath queries
	 *
	 * Using the filter, a "query set" can be added like:
	 * [
	 *  'tag_name'  => 'pullQuote',
	 *  'container' => 'pullQuotes',
	 *  'query'     => '//p[contains(@class, "pullquote")]'
	 * ]
	 *
	 * The above array would extract all paragraphs with the "pullquote" class
	 * Create a new root element in the DOM called "pullQuotes"
	 * and add each found paragraph to the pullQuotes element
	 * as a newly created "pullQuote" element with the content of the paragraph
	 *
	 * @param \DOMDocument $dom The DOM.
	 *
	 * Doesn't need to return anything because the DOM is aliiiiiive.
	 */
	public function extract_elements_by_xpath( $dom ) {
		$xpath_extract = apply_filters( __NAMESPACE__ . '\xpath_extract', array() );
		if ( $xpath_extract ) {
			$domxpath = new \DOMXPath( $dom );

			foreach ( $xpath_extract as $set ) {
				$remove   = array();
				$elements = $domxpath->query( $set['query'] );
				if ( $elements->length ) {
					$wrap = $dom->createElement( $set['container'] );
					$dom->appendChild( $wrap );
					foreach ( $elements as $el ) {
						$remove[]           = $el;
						$element            = $dom->createElement( $set['tag_name'] );
						$element->nodeValue = $el->nodeValue;
						$wrap->appendChild( $element );
					}
					foreach ( $remove as $el ) {
						$el->parentNode->removeChild( $el );
					}
				}
			}
		}
	}

	/**
	 * Converts the html document to valid xml document
	 * with a root element of 'article'
	 *
	 * @param \DOMDocument $dom The DOM.
	 *
	 * @throws \Exception Various points of failure.
	 *
	 * @return object DOMDocument + child elements for easy access
	 */
	public function html_to_xml( $dom ) {
		$content = $dom->getElementsByTagName( 'body' );
		if ( ! $content ) {
			throw new \Exception( 'Empty content' );
		}

		$content = $content->item( 0 );

		$xml_document = new \DOMDocument();

		$article_element = $xml_document->createElement( apply_filters( __NAMESPACE__ . '\xml_root_element', 'article' ) );
		$xml_document->appendChild( $article_element );

		$content_element = $xml_document->createElement( apply_filters( __NAMESPACE__ . '\xml_content_element', 'content' ) );
		$article_element->appendChild( $content_element );

		foreach ( $content->childNodes as $el ) {
			$content_element->appendChild( $xml_document->importNode( $el, true ) );
		}

		$article_xml                  = new \stdClass();
		$article_xml->xml_document    = $xml_document;
		$article_xml->root_element    = $article_element;
		$article_xml->content_element = $content_element;

		return $article_xml;
	}

}
