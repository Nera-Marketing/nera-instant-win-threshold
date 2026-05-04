<?php
/**
 * Build a WordPress-safe plugin/theme release zip (always `/` path separators).
 *
 * PowerShell Compress-Archive uses `\` in central-directory paths, which breaks
 * core's unzip/copy on many hosts ("Could not copy file …\lib\").
 *
 * Usage: php build-wp-release-zip.php <absolute-source-dir> <absolute-output.zip>
 *
 * The top-level folder inside the zip is basename(source-dir).
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php build-wp-release-zip.php <source-directory> <output.zip>\n" );
	exit( 1 );
}

$src_arg = $argv[1];
$out_zip = $argv[2];

$src = realpath( $src_arg );
if ( $src === false || ! is_dir( $src ) ) {
	fwrite( STDERR, "Invalid source directory: {$src_arg}\n" );
	exit( 1 );
}

$root = basename( $src );
$zip  = new ZipArchive();
if ( true !== $zip->open( $out_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create zip: {$out_zip}\n" );
	exit( 1 );
}

$src_len = strlen( $src );
$it      = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ( $it as $file_info ) {
	if ( ! $file_info->isFile() ) {
		continue;
	}
	$full = $file_info->getRealPath();
	if ( ! $full ) {
		continue;
	}
	if ( strpos( $full, $src ) !== 0 ) {
		continue;
	}
	$rel = substr( $full, $src_len );
	$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
	$zip->addFile( $full, $root . '/' . $rel );
}

$zip->close();
