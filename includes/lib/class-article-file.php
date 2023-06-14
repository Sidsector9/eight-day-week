<?php
/**
 * Class Article File
 *
 * @package eight-day-week
 */

/**
 * Class File
 *
 * @package Eight_Day_Week\Plugins\Article_Export
 *
 * Builds a "File" based on either a string or a readable, actual file
 */
class Article_File {

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
			$this->contents = wp_remote_get( $contents_or_file_path );
			$this->filename = basename( $contents_or_file_path );
		} else {
			$this->contents = $contents_or_file_path;
			$this->filename = $filename;
		}
	}
}
