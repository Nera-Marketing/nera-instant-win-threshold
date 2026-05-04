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

$src_fs = realpath( $src_arg );
if ( $src_fs === false || ! is_dir( $src_fs ) ) {
	fwrite( STDERR, "Invalid source directory: {$src_arg}\n" );
	exit( 1 );
}

// Normalise to forward slashes so prefix math matches Iterator paths on Windows.
$src = str_replace( '\\', '/', $src_fs );
$src = rtrim( $src, '/' );
$src_prefix = $src . '/';

$root = basename( $src );
$zip  = new ZipArchive();
if ( true !== $zip->open( $out_zip, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "Cannot create zip: {$out_zip}\n" );
	exit( 1 );
}

$it = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $src_fs, FilesystemIterator::SKIP_DOTS ),
	RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ( $it as $file_info ) {
	if ( ! $file_info->isFile() ) {
		continue;
	}
	$full_raw = $file_info->getRealPath();
	if ( ! $full_raw ) {
		continue;
	}
	$full = str_replace( '\\', '/', $full_raw );
	if ( strpos( $full, $src_prefix ) !== 0 ) {
		continue;
	}
	$rel = substr( $full, strlen( $src_prefix ) );
	$rel = ltrim( $rel, '/' );
	// Central-directory paths must use `/` (never `\\`) or WordPress may fail copying on update.
	$zip->addFile( $full_raw, $root . '/' . $rel );
}

$zip->close();
