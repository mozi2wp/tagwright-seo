<?php
/**
 * Renames the plugin family from one brand to another.
 *
 * Kept in the repository (but not in the release zip) so a name change is a
 * single command instead of an afternoon of search and replace. It rewrites
 * file contents and file names in the folders you pass, and leaves anything
 * that is not part of the brand alone ("classic editor" stays as it is).
 *
 * Usage, from the plugins folder:
 *
 *     php tagwright-seo/bin/rename-brand.php "Tagwright SEO" tagwright "New Name SEO" nn tagwright-seo tagwright-seo-addon-starter
 *
 * Arguments: old name, old short prefix, new name, new short prefix, then one
 * or more folders. Each folder is copied to its renamed sibling (tagwright-seo
 * becomes new-name-seo); the original folders are never changed.
 *
 * The short prefix is used for tables and CSS classes (wp_tagwright_objects,
 * .tagwright-btn). Remember that a released plugin also needs a database
 * migration when its table and option names change.
 *
 * Words that merely contain the old prefix, such as the author's username,
 * are listed in tagwright_seo_rename_protected() and survive untouched. Third-party
 * code in vendor/ is copied as it is.
 *
 * @package TagwrightSEO
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

/**
 * Prints a message and stops.
 *
 * @param string $message Message.
 * @param int    $code    Exit code.
 * @return void
 */
function tagwright_seo_rename_exit( $message, $code = 1 ) {
	fwrite( 0 === $code ? STDOUT : STDERR, $message . PHP_EOL ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( $code ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Every spelling of a brand name used in the code.
 *
 * @param string $name  Display name, for example "Tagwright SEO".
 * @param string $short Short prefix, for example "tagwright".
 * @return array<string,string> Spelling key => value.
 */
function tagwright_seo_rename_forms( $name, $short ) {
	$words = preg_split( '/\s+/', trim( $name ) );
	$lower = array_map( 'strtolower', $words );
	$camel = $lower[0];

	foreach ( array_slice( $lower, 1 ) as $word ) {
		$camel .= ucfirst( $word );
	}

	return array(
		'display' => implode( ' ', $words ),
		'pascal'  => implode( '', $words ),
		'camel'   => $camel,
		'upper'   => strtoupper( implode( '_', $words ) ),
		'kebab'   => implode( '-', $lower ),
		'snake'   => implode( '_', $lower ),
		'short'   => strtolower( $short ),
	);
}

/**
 * Words that contain the old prefix but are not part of the brand.
 *
 * @return string[]
 */
function tagwright_seo_rename_protected() {
	return array( 'mozi2wp', 'Mozi2WP', 'Mozilla', 'mozilla', 'Mozayyan' );
}

/**
 * Applies the brand map while leaving protected words alone.
 *
 * @param string               $text Text to rewrite.
 * @param array<string,string> $map  Old spelling => new spelling.
 * @return string
 */
function tagwright_seo_rename_text( $text, $map ) {
	$keep = array();

	foreach ( tagwright_seo_rename_protected() as $index => $word ) {
		$keep[ $word ] = "\x00{$index}\x00";
	}

	$text = strtr( strtr( $text, $keep ), $map );

	return strtr( $text, array_flip( $keep ) );
}

$tagwright_seo_args = array_slice( $argv, 1 );

if ( count( $tagwright_seo_args ) < 5 ) {
	tagwright_seo_rename_exit( 'Usage: php rename-brand.php "<Old Name>" <old-short> "<New Name>" <new-short> <folder> [<folder>...]' );
}

list( $tagwright_seo_old_name, $tagwright_seo_old_short, $tagwright_seo_new_name, $tagwright_seo_new_short ) = $tagwright_seo_args;
$tagwright_seo_folders = array_slice( $tagwright_seo_args, 4 );

if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9 ]+$/', $tagwright_seo_new_name ) || ! preg_match( '/^[a-z][a-z0-9]{1,10}$/', $tagwright_seo_new_short ) ) {
	tagwright_seo_rename_exit( 'Names may use letters, numbers and spaces; the short prefix lowercase letters and numbers only.' );
}

$tagwright_seo_old = tagwright_seo_rename_forms( $tagwright_seo_old_name, $tagwright_seo_old_short );
$tagwright_seo_new = tagwright_seo_rename_forms( $tagwright_seo_new_name, $tagwright_seo_new_short );

// strtr() always prefers the longest match, so "Tagwright SEO" wins over "tagwright".
$tagwright_seo_map = array();
foreach ( $tagwright_seo_old as $tagwright_seo_key => $tagwright_seo_value ) {
	$tagwright_seo_map[ $tagwright_seo_value ] = $tagwright_seo_new[ $tagwright_seo_key ];
}

foreach ( $tagwright_seo_folders as $tagwright_seo_folder ) {
	$tagwright_seo_source = rtrim( $tagwright_seo_folder, '/\\' );

	if ( ! is_dir( $tagwright_seo_source ) ) {
		tagwright_seo_rename_exit( "Folder not found: {$tagwright_seo_source}" );
	}

	$tagwright_seo_target = dirname( $tagwright_seo_source ) . DIRECTORY_SEPARATOR . strtr( basename( $tagwright_seo_source ), $tagwright_seo_map );

	if ( file_exists( $tagwright_seo_target ) ) {
		tagwright_seo_rename_exit( "{$tagwright_seo_target} already exists. Move it away first." );
	}

	$tagwright_seo_files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $tagwright_seo_source, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::SELF_FIRST
	);

	$tagwright_seo_count = 0;

	foreach ( $tagwright_seo_files as $tagwright_seo_file ) {
		$tagwright_seo_relative = str_replace( '\\', '/', substr( $tagwright_seo_file->getPathname(), strlen( $tagwright_seo_source ) + 1 ) );

		if ( preg_match( '#^(\.git|node_modules|Claude outputs)(/|$)#', $tagwright_seo_relative ) ) {
			continue;
		}

		// Third-party libraries keep their own names and contents.
		$tagwright_seo_vendor = (bool) preg_match( '#^vendor(/|$)#', $tagwright_seo_relative );

		$tagwright_seo_destination = $tagwright_seo_target . '/' . ( $tagwright_seo_vendor ? $tagwright_seo_relative : tagwright_seo_rename_text( $tagwright_seo_relative, $tagwright_seo_map ) );

		if ( $tagwright_seo_file->isDir() ) {
			if ( ! is_dir( $tagwright_seo_destination ) ) {
				mkdir( $tagwright_seo_destination, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			}
			continue;
		}

		if ( ! is_dir( dirname( $tagwright_seo_destination ) ) ) {
			mkdir( dirname( $tagwright_seo_destination ), 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}

		$tagwright_seo_contents = file_get_contents( $tagwright_seo_file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( ! $tagwright_seo_vendor && preg_match( '/\.(php|js|css|txt|md|json|xml|pot|distignore)$|(^|\/)\.distignore$/', $tagwright_seo_relative ) ) {
			$tagwright_seo_contents = tagwright_seo_rename_text( $tagwright_seo_contents, $tagwright_seo_map );
		}

		file_put_contents( $tagwright_seo_destination, $tagwright_seo_contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		++$tagwright_seo_count;
	}

	echo "{$tagwright_seo_source} -> {$tagwright_seo_target} ({$tagwright_seo_count} files)" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
