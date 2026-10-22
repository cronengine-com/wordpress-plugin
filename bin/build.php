<?php
/**
 * Builds dist/cronengine.zip, ready to upload in Plugins, Add New, Upload.
 *
 *   php bin/build.php
 *
 * The zip holds one folder named "cronengine" with only what WordPress needs.
 * Needs the zip extension (on Windows: php -d extension=zip bin/build.php).
 */

$root  = dirname( __DIR__ );
$files = array( 'cronengine.php', 'uninstall.php', 'readme.txt', 'LICENSE' );
$dirs  = array( 'includes', 'assets' );

foreach ( $dirs as $dir ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/' . $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$files[] = substr( str_replace( '\\', '/', $file->getPathname() ), strlen( str_replace( '\\', '/', $root ) ) + 1 );
	}
}

if ( ! is_dir( $root . '/dist' ) ) {
	mkdir( $root . '/dist' );
}

$target = $root . '/dist/cronengine.zip';
@unlink( $target );

$zip = new ZipArchive();
if ( true !== $zip->open( $target, ZipArchive::CREATE ) ) {
	fwrite( STDERR, "Could not create $target\n" );
	exit( 1 );
}

sort( $files );
foreach ( $files as $file ) {
	$zip->addFile( $root . '/' . $file, 'cronengine/' . $file );
}
$zip->close();

echo 'Built dist/cronengine.zip with ' . count( $files ) . " files.\n";
