<?php
/**
 * Plugin Name: Eight Day Week
 * Description: Tools that help improve digital & print workflows.
 * Version:     1.2.1
 * Author:      10up
 * Author URI:  https://10up.com
 * License:     GPLv2+
 * Text Domain: eight-day-week-print-workflow
 *
 * @package eight-day-week
 */

/**
 * Copyright (c) 2015 10up (email : info@10up.com)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2 or, at
 * your discretion, any later version, as published by the Free
 * Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

// Load VIP compat functions.
require_once __DIR__ . '/vip-compat.php';

// Load plugin loader.
require_once __DIR__ . '/plugins.php';

// Useful global constants.
define( 'EDW_VERSION', '1.2.1' );
define( 'EDW_URL', Eight_Day_Week\plugins_url( __FILE__ ) );
define( 'EDW_PATH', __DIR__ . '/' );
define( 'EDW_INC', EDW_PATH . 'includes/' );

// Allow override from wp-config (et al).
if ( ! defined( 'EDW_PRODUCTION' ) ) {

	// If on VIP, let VIP define production state.
	if ( defined( 'WPCOM_IS_VIP_ENV' ) ) {
		define( 'EDW_PRODUCTION', WPCOM_IS_VIP_ENV );
	} else {
		// Otherwise, assume production.
		define( 'EDW_PRODUCTION', true );
	}
}

// More specific constants, used throughout.
define( 'EDW_PRINT_ISSUE_CPT', 'print-issue' );
define( 'EDW_ADMIN_MENU_SLUG', 'edit.php?post_type=' . EDW_PRINT_ISSUE_CPT );
define( 'EDW_SECTION_CPT', 'pi-section' );
define( 'EDW_ARTICLE_STATUS_TAX', 'pi-article-status' );
define( 'EDW_AJAX_NONCE_SLUG', 'edw_ajax_nonce' );

/**
 * Calls the setup function of any namespaced files
 * in the includes/functions dir
 *
 * @throws \Exception Failed to load a file.
 */
function edw_bootstrap() {

	$map = edw_build_namespace_map();

	if ( ! $map ) {
		return;
	}

	$core_file = EDW_INC . 'functions' . DIRECTORY_SEPARATOR . 'core.php';

	if ( ! isset( $map[ $core_file ] ) ) {
		return;
	}

	require_once $core_file;
	Eight_Day_Week\Core\setup();

	unset( $map[ $core_file ] );

	foreach ( $map as $file => $namespace ) {

		// Play nice.
		try {
			require_once $file;
			$setup = $namespace . '\setup';

			// Allow files *not* to have a setup function.
			if ( function_exists( $setup ) ) {
				$setup();
				do_action( $namespace . '\setup' );
			}
		} catch ( \Exception $e ) {
			throw new \Exception( $e->getMessage() );
		}
	}
}
// Hook before init so the plugin has its own "init" action.
add_action( 'after_setup_theme', 'edw_bootstrap' );

/**
 * Builds a map of files + namespaces
 *
 * @return array Map of files => namespace
 */
function edw_build_namespace_map() {

	$dir = EDW_INC . 'functions';

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST,
		RecursiveIteratorIterator::CATCH_GET_CHILD
	);

	$map = array();
	foreach ( $iterator as $file ) {

		if ( 'php' !== $file->getExtension() ) {
			continue;
		}

		// Get just the file name, e.g. "core".
		$file_name = str_replace( '.php', '', $file->getFileName() );

		// Convert dashes to spaces.
		$spaced = str_replace( '-', ' ', $file_name );

		// So that ucwords will work.
		$capitalized = ucwords( $spaced );

		// Get the "end" namespace.
		$tip_of_the_iceberg = str_replace( ' ', '_', $capitalized );

		$path = $file->getPathInfo()->getPathname();
		if ( $dir !== $path ) {
			$sub_directory = str_replace( $dir . DIRECTORY_SEPARATOR, '', $path );

			// Convert slashes to spaces.
			$capitalized = ucwords( str_replace( DIRECTORY_SEPARATOR, ' ', $sub_directory ) );

			$tip_of_the_iceberg = str_replace( ' ', '\\', $capitalized ) . '\\' . $tip_of_the_iceberg;
		}

		// Add the namespace prefix + convert spaces to underscores for the final namespace.
		$namespace = '\Eight_Day_Week\\' . $tip_of_the_iceberg;

		// Add it all the map.
		$map[ $file->getPathname() ] = $namespace;
	}

	return apply_filters( 'edw_files_to_load', $map );
}
