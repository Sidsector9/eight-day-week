<?php
/**
 * Handles the article export functionality
 *
 * @package eight-day-week
 */

namespace Eight_Day_Week\Plugins\Article_Export;

use Eight_Day_Week\Core as Core;

/**
 * Default setup routine
 *
 * @uses add_action()
 * @uses do_action()
 *
 * @return void
 */
function setup() {

	add_action(
		'Eight_Day_Week\Core\plugin_init',
		function () {
			/**
			 * Return's a namespaced function
			 *
			 * @param string $func The function name.
			 */
			function ns( $func ) {
				return __NAMESPACE__ . "\\$func";
			}

			/**
			 * Register a function action
			 *
			 * @param string $func The function name.
			 */
			function a( $func ) {
				add_action( $func, ns( $func ) );
			}

			add_action( 'edw_sections_top', ns( 'output_article_export_buttons' ), 11 );
			add_action( 'wp_ajax_pp-article-export', ns( 'export_articles' ) );

			add_action( 'wp_ajax_pp-article-export-update', ns( 'article_status_response' ) );

			// Add export status column.
			add_filter( 'Eight_Day_Week\Articles\article_columns', ns( 'article_export_status_column' ), 10 );

			// Retrieve export status + build string for output.
			add_filter( 'Eight_Day_Week\Articles\article_meta_export_status', ns( 'filter_export_status' ), 10, 2 );

			add_filter( __NAMESPACE__ . '\short_circuit_article_export_files', ns( 'maybe_export_article_attachments' ), 10, 4 );

			add_filter( __NAMESPACE__ . '\xml_filename', ns( 'filter_xml_filename_author' ), 10, 3 );
		}
	);
}

/**
 * Output export buttons!
 */
function output_article_export_buttons() {
	?>
	<div class="alignleft actions bulkactions article-export-buttons">
		<h3><?php esc_html_e( 'Export for InDesign', 'eight-day-week-print-workflow' ); ?></h3>
		<button id="article-export-checked" class="button button-secondary"><?php esc_html_e( 'Export checked', 'eight-day-week-print-workflow' ); ?></button>
		<button id="article-export-all" class="button button-secondary"><?php esc_html_e( 'Export all', 'eight-day-week-print-workflow' ); ?></button>
	</div>
	<?php
}

/**
 * Big ol' export article controller
 */
function export_articles() {

	Core\check_ajax_referer();

	if ( ! isset( $_POST['article_ids'] ) ) {
		die( __( 'No article IDs sent', 'eight-day-week-print-workflow' ) );
	}

	$article_ids = $_POST['article_ids'];

	$print_issue_id    = isset( $_POST['print_issue_id'] ) ? absint( $_POST['print_issue_id'] ) : false;
	$print_issue_title = isset( $_POST['print_issue_title'] ) ? sanitize_text_field( wp_unslash( $_POST['print_issue_title'] ) ) : false;

	// Sanitize - only allow comma delimited integers.
	if ( ! ctype_digit( str_replace( ',', '', $article_ids ) ) ) {
		die( esc_html__( 'Invalid article IDs specified in the request.', 'eight-day-week-print-workflow' ) );
	}

	$article_ids = explode( ',', $article_ids );

	do_action( __NAMESPACE__ . '\before_export_articles', $article_ids, $print_issue_id, $print_issue_title );

	try {
		$factory = new Article_Zip_Factory( $article_ids, $print_issue_id, $print_issue_title );
	} catch ( \Exception $e ) {
		Core\send_json_error( $e->getMessage() );
		return;
	}

	try {
		$factory->output_zip();
	} catch ( \Exception $e ) {
		Core\send_json_error( $e->getMessage() );
	}

	do_action( __NAMESPACE__ . '\_after_export_articles', $article_ids, $print_issue_id, $print_issue_title );
}

/**
 * Adds exports status to the list table columns
 *
 * @param array $columns Array of registered columns.
 *
 * @return array Modified columns
 */
function article_export_status_column( $columns ) {
	$columns['export_status'] = __( 'Export Status', 'eight-day-week-print-workflow' );
	return $columns;
}

/**
 * Updates post meta with export status and retrieves the built status string
 *
 * @param array $article_ids Post IDs.
 *
 * @uses set_export_status
 * @uses get_export_status
 *
 * @return string The export status
 */
function set_and_get_export_status( $article_ids ) {
	set_export_status( $article_ids );
	return get_export_status( reset( $article_ids ) );
}

/**
 * Manages the ajax response for setting/getting article export status
 */
function article_status_response() {
	Core\check_ajax_referer();
	try {
		$export_status = article_status_handler();
		Core\send_json_success( array( 'export_status' => $export_status ) );
	} catch ( \Exception $e ) {
		Core\send_json_error();
	}
}

/**
 * Pulls data from the http request and sets/gets article export status
 *
 * @uses set_and_get_export_status
 *
 * @return string The export status
 * @throws \Exception Points of failure in the process.
 */
function article_status_handler() {

	if ( ! isset( $_POST['article_ids'] ) ) {
		throw new \Exception( __( 'No article IDs sent', 'eight-day-week-print-workflow' ) );
	}

	$article_ids = $_POST['article_ids'];

	// Sanitize - only allow comma delimited integers.
	if ( ! ctype_digit( str_replace( ',', '', $article_ids ) ) ) {
		throw new \Exception( __( 'Invalid article IDs specified in the request.', 'eight-day-week-print-workflow' ) );
	}

	$article_ids = explode( ',', $article_ids );

	return set_and_get_export_status( $article_ids );
}

/**
 * Updates each exported article with meta
 * about who last exported it, and when
 *
 * @param array $article_ids IDs of articles that were exported.
 */
function set_export_status( $article_ids ) {
	$article_ids = array_map( 'absint', $article_ids );
	foreach ( $article_ids as $article_id ) {
		update_post_meta( $article_id, 'export_status_timestamp', time() );
		update_post_meta( $article_id, 'export_status_user_id', get_current_user_id() );
	}
}

/**
 * Gets the article's export status
 *
 * @param bool     $incoming The incoming meta value (false by default).
 * @param \WP_Post $article The current post.
 *
 * @return string The article's export status, or the incoming value
 */
function filter_export_status( $incoming, $article ) {
	$status = get_export_status( $article->ID );
	return $status ? $status : $incoming;
}

/**
 * Builds the export status string
 *
 * @param int $article_id The article post ID.
 *
 * @return string The export status
 */
function get_export_status( $article_id ) {

	$timestamp = get_post_meta( $article_id, 'export_status_timestamp', true );

	if ( ! $timestamp ) {
		return false;
	}

	$user_id = get_post_meta( $article_id, 'export_status_user_id', true );
	$user    = get_user_by( 'id', absint( $user_id ) );

	$zone = new \DateTimeZone( Core\get_timezone() );

	$export_datetime = new \DateTime( 'now', $zone );
	$export_datetime->setTimestamp( $timestamp );

	$now = new \DateTime( 'now', $zone );

	$not_today = $export_datetime->diff( $now )->format( '%R%a' );

	if ( ! $not_today ) {
		/* translators: %1$s is replaced with the date, %2$s is replacted with the user's display name */
		$export_status = sprintf( esc_html__( 'Exported on %1$s by %2$s', 'eight-day-week-print-workflow' ), $export_datetime->format( get_option( 'date_format' ) ), $user->display_name );
	} else {
		/* translators: %1$s is replaced with the date, %2$s is replacted with the user's display name */
		$export_status = sprintf( esc_html__( 'Exported at %1$s by %2$s', 'eight-day-week-print-workflow' ), $export_datetime->format( _x( 'g:ia', 'Format for article export timestamp', 'eight-day-week-print-workflow' ) ), $user->display_name );
	}

	return $export_status;
}

/**
 * Class Article_Zip_Factory
 *
 * @package Eight_Day_Week\Plugins\Article_Export
 *
 * Factory for building an export zip for an article
 *
 * @todo Pull out XML-related functions so this class is for a generic ZIP;
 * @todo Perhaps introduce a fallback filter vs explicitly requesting an XML file fallback
 */
class Article_Zip_Factory {

	/**
	 * Article IDs
	 *
	 * @var int[] Article IDs
	 */
	private $ids;

	/**
	 * Articles
	 *
	 * @var \WP_Post[] Articles
	 */
	public $articles;

	/**
	 * Files for all articles
	 *
	 * @var File[] Files for all articles
	 */
	public $files;

	/**
	 * Sets up object properties
	 *
	 * @param array  $ids Array of post IDs.
	 * @param int    $print_issue_id ID of parent print issue.
	 * @param string $print_issue_title Title of parent print issue (to avoid lookup).
	 *
	 * @throws \Exception Various points of failure in constructing the factory.
	 */
	public function __construct( $ids, $print_issue_id, $print_issue_title ) {

		$article_ids = array_filter( $ids, 'is_numeric' );
		if ( count( $ids ) !== count( $article_ids ) ) {
			throw new \Exception( __( 'Invalid article IDs specified in the request.', 'eight-day-week-print-workflow' ) );
		}

		$this->ids               = $ids;
		$this->print_issue_id    = absint( $print_issue_id );
		$this->print_issue_title = sanitize_text_field( $print_issue_title );
	}

	/**
	 * Builds an array of WP_Post objects via the object's set of IDs
	 *
	 * @return \WP_Post[]
	 */
	public function import_articles() {
		$articles = array();
		foreach ( $this->ids as $id ) {
			$article = get_post( $id );
			if ( $article ) {
				$articles[ $id ] = $article;
			}
		}

		return $articles;
	}

	/**
	 * Gets the object's set of \WP_Posts
	 *
	 * @uses import_articles
	 *
	 * @return \WP_Post[]
	 */
	public function get_articles() {
		if ( $this->articles ) {
			return $this->articles;
		}

		$this->articles = $this->import_articles();
		return $this->articles;
	}

	/**
	 * Gets export files for an article
	 *
	 * Provides a filter so that 3rd parties can hook in
	 * and determine what files to export vs the standard XML.
	 *
	 * @return array File[] Set of export files
	 */
	public function build_file_sets() {

		$articles = $this->get_articles();

		$file_sets = array();
		foreach ( $articles as $article ) {

			// Allow articles to export an alternative file.
			$files = apply_filters( __NAMESPACE__ . '\short_circuit_article_export_files', false, $article, $this->print_issue_id, $this->print_issue_title );

			// But fall back to XML.
			if ( ! $files ) {
				$files = $this->get_xml_file( $article );
			}

			$file_sets[ $article->ID ] = $files;

		}

		return $file_sets;
	}

	/**
	 * Gets an xml export file for an article
	 *
	 * @param \WP_Post $article The current post.
	 *
	 * @return File The XML file for the provided post
	 */
	public function get_xml_file( $article ) {
		$xml = $this->get_xml( $article );

		$file_name  = apply_filters( __NAMESPACE__ . '\xml_filename', $xml->root_element->getAttribute( 'title' ), $article, $xml );
		$file_name .= '.xml';

		$file_contents = $xml->xml_document->saveXML();

		return new File( $file_contents, apply_filters( __NAMESPACE__ . '\xml_full_filename', $file_name, $article ) );
	}

	/**
	 * Builds and returns an XML file for an article
	 *
	 * @param \WP_Post $article the current post.
	 *
	 * @return object Contains properties of a DOMDocument object for easy access
	 */
	public function get_xml( $article ) {
		$xml = new Article_XML( $article );
		return $xml->build_xml();
	}

	/**
	 * Gets a set of files for the current set of articles
	 *
	 * @return File[] Set of files
	 */
	public function get_file_sets() {
		if ( $this->files ) {
			return $this->files;
		}

		$this->files = $this->build_file_sets();
		return $this->files;
	}

	/**
	 * Outputs a zip archive
	 */
	public function output_zip() {
		$file_sets = $this->get_file_sets();
		$zip       = new Articles_Zip( $this->print_issue_title );

		foreach ( $file_sets as $article_id => $files ) {

			// Force an array.
			$files = is_array( $files ) ? $files : array( $files );
			$zip->addFiles( $files );
		}

		$zip->output_zip();
	}

}

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

/**
 * Class Articles_Zip
 *
 * @package Eight_Day_Week\Plugins\Article_Export
 *
 * Represents an in-memory Zip, including implementation of the vendor zip lib
 */
class Articles_Zip {

	/**
	 * Issue title
	 *
	 * @var string The print issue title (used for building zip file name)
	 */
	protected $issue_title;

	/**
	 * Files for the current articles
	 *
	 * @var File[] Files for the current articles
	 */
	protected $files;

	/**
	 * The xip file
	 *
	 * @var \ZipFile
	 */
	private $zip;

	/**
	 * Sets object properties
	 *
	 * @param string $issue_title The current print issue's title.
	 * @param File[] $files Set of Files.
	 */
	public function __construct( $issue_title, $files = array() ) {
		$this->issue_title = $issue_title;
		$this->files       = $files;
	}

	/**
	 * Builds the file name for the zip
	 * Uses the print issue title & day/time
	 *
	 * @uses get_timezone
	 *
	 * @return string The zip file name
	 */
	public function get_zip_file_name() {
		$timezone_string = get_option( 'timezone_string' );
		if ( empty( $timezone_string ) ) {
			$offset          = get_option( 'gmt_offset' );
			$hours           = (int) $offset;
			$minutes         = abs( ( $offset - (int) $offset ) * 60 );
			$timezone_string = sprintf( '%+03d:%02d', $hours, $minutes );
		}
		$date = new \DateTime( 'now', new \DateTimeZone( $timezone_string ) );
		return 'Issue ' . $this->issue_title . ' exported on ' . $date->format( 'm-d-y' ) . ' at ' . $date->format( 'h:ia' );
	}

	/**
	 * Adds a File to the object, for later addition to the zip
	 *
	 * @param File $file The file to add to the zip.
	 */
	public function addFile( $file ) {
		$this->files[] = $file;
	}

	/**
	 * Adds a set of files to the current set
	 *
	 * @param File[] $files FIles to add to the zip.
	 */
	public function addFiles( $files ) {
		$this->files = array_merge( $this->files, $files );
	}

	/**
	 * Creates a new \ZipFile, adds current file set to it, and yields it
	 *
	 * @return \ZipFile
	 */
	public function build_zip() {
		include_once EDW_INC . 'lib/zip.lib.php';
		$zip = new \ZipFile();

		foreach ( $this->files as $file ) {
			$zip->addFile( $file->contents, $file->filename );
		}

		return $zip;
	}

	/**
	 * Gets/builds a \ZipFile via the current file set
	 *
	 * @uses build_zip
	 *
	 * @return \ZipFile
	 */
	public function get_zip() {
		if ( $this->zip ) {
			return $this->zip;
		}

		$this->zip = $this->build_zip();
		return $this->zip;
	}

	/**
	 * Builds a zip and outputs it to the browser
	 *
	 * @uses get_zip
	 */
	public function output_zip() {
		$zip = $this->get_zip();

		header( 'Content-type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $this->get_zip_file_name() . '.zip"' );
		echo $zip->file();
		exit;
	}

}

/**
 * Class File
 *
 * @package Eight_Day_Week\Plugins\Article_Export
 *
 * Builds a "File" based on either a string or a readable, actual file
 */
class File {

	/**
	 * The File's name
	 *
	 * @var string The File's name
	 */
	public $filename;

	/**
	 * The File's contents
	 *
	 * @var string The File's contents
	 */
	public $contents;

	/**
	 * Sets object properties
	 *
	 * If given a readable file path, builds the file name + contents via the actual file
	 * Otherwise assumes provision of explicit file contents + name
	 *
	 * @param string $contents_or_file_path Content or filepath.
	 * @param string $filename The filename.
	 */
	public function __construct( $contents_or_file_path, $filename = '' ) {
		if ( is_readable( $contents_or_file_path ) ) {
			$this->contents = file_get_contents( $contents_or_file_path );
			$this->filename = basename( $contents_or_file_path );
		} else {
			$this->contents = $contents_or_file_path;
			$this->filename = $filename;
		}
	}
}

/**
 * Returns a set of Files if there are documents attached to provided article
 *
 * @param mixed|bool $incoming The previous filter value.
 * @param \WP_Post   $article The post to retrieve attachments from.
 * @param int        $issue_id The print issue's ID.
 * @param string     $issue_title The print issue's title.
 *
 * @return File[] Set of Attachment Files, or $incoming value
 */
function maybe_export_article_attachments( $incoming, $article, $issue_id, $issue_title ) {

	add_filter( 'posts_where', __NAMESPACE__ . '\filter_article_export_attachments_where' );

	$attachments = new \WP_Query(
		array(
			'post_type'              => 'attachment',
			'post_parent'            => absint( $article->ID ),
			'posts_per_page'         => 20,
			'post_status'            => 'inherit',
			// Meta will be used, so only disable term cache.
			'update_post_term_cache' => false,
		)
	);

	remove_filter( 'posts_where', __NAMESPACE__ . '\filter_article_export_attachments_where' );

	if ( $attachments->have_posts() ) {
		$incoming = get_document_files( $attachments->posts );
	}

	return $incoming;
}

/**
 * Returns a filters article export where clause
 *
 * @param string $where The current where clause.
 */
function filter_article_export_attachments_where( $where ) {
	return $where . " AND post_mime_type NOT LIKE 'image%'";
}

/**
 * Gets a set of Files[] from attachments
 *
 * @param \WP_Post[] $attachments Attachments of a parent article.
 *
 * @return File[] Attachment files
 */
function get_document_files( $attachments ) {
	return array_map(
		function( $attachment ) {
			return get_attachment_file( $attachment );
		},
		$attachments
	);
}

/**
 * Builds a File from an attachment
 *
 * @param obj $attachment The attachment.
 *
 * @return File The File of the attachment
 */
function get_attachment_file( $attachment ) {
	return new File( get_attached_file( $attachment->ID ) );
}

/**
 * Adds a headline (title) element to the root of the XML document
 *
 * @param array    $elements Incoming elements.
 * @param \WP_Post $article The current post.
 *
 * @return array Modified elements
 */
function filter_xml_elements_headline( $elements, $article ) {
	$elements['headline'] = get_the_title( $article );
	return $elements;
}

/**
 * Prefix the xml filename with the post author's name
 *
 * @param string   $file_name The original file name.
 * @param \WP_Post $article The post.
 * @param object   $xml Custom DOMDocument wrapper object.
 *
 * @return string The modified filename
 */
function filter_xml_filename_author( $file_name, $article, $xml ) {
	$author = $xml->root_element->getAttribute( 'author' );
	if ( $author ) {
		$file_name = $author . '.' . $file_name;
	}

	return $file_name;
}
