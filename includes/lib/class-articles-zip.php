<?php
/**
 * Class Class Articles_Zip
 *
 * @package eight-day-week
 */

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
	public function add_file( $file ) {
		$this->files[] = $file;
	}

	/**
	 * Adds a set of files to the current set
	 *
	 * @param File[] $files FIles to add to the zip.
	 */
	public function add_files( $files ) {
		$this->files = array_merge( $this->files, $files );
	}

	/**
	 * Creates a new \ZipFile, adds current file set to it, and yields it
	 *
	 * @return \ZipFile
	 */
	public function build_zip() {
		include_once EDW_INC . 'lib/class-zipfile.php';
		$zip = new \ZipFile();

		foreach ( $this->files as $file ) {
			$zip->add_file( $file->contents, $file->filename );
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
		// Using phpcs:ignore for the missing escaping function error which is
		// needed to output the file contents.
		echo $zip->file(); // phpcs:ignore
		exit;
	}

}
