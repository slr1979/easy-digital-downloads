#!/usr/bin/env php
<?php
/**
 * Square SDK Cleanup Script
 *
 * The Square SDK ships ~2,100 PHP files (~20MB unpacked), but EDD only uses a
 * small subset. This script runs after Mozart to dynamically scan the codebase
 * for actual Square class usage and remove everything else from libraries/Square/.
 *
 * It scans src/ and includes/ for any reference to EDD\Vendor\Square\ classes
 * (use statements, fully qualified calls, new instantiations, etc.), then also
 * scans the kept API classes and their model dependencies recursively, and
 * removes all unused files from Models/, Models/Builders/, and Apis/.
 *
 * It also cleans up SquareClient.php to remove use statements and getter
 * methods for deleted API classes.
 *
 * This script is called automatically via `composer run mozart`.
 *
 * @package EDD
 * @since   3.7.0
 */

// Resolve project root (bin/ is one level deep).
$projectRoot = dirname( __DIR__ );
$squareDir   = $projectRoot . '/libraries/Square';

if ( ! is_dir( $squareDir ) ) {
	echo "Square SDK not found at {$squareDir}. Skipping cleanup.\n";
	exit( 0 );
}

echo "Scanning codebase for Square SDK usage...\n";

/**
 * Scan PHP files in the given directories for references to Square classes.
 *
 * Matches patterns like:
 *   use EDD\Vendor\Square\Models\Money
 *   new \EDD\Vendor\Square\Models\Money(...)
 *   new EDD\Vendor\Square\Models\Money(...)
 *   EDD\Vendor\Square\ApiException::class
 *   \EDD\Vendor\Square\Utils\WebhooksHelper::verify(...)
 *
 * Also detects SquareClient API method calls like:
 *   ->getPaymentsApi()
 *   ->getCatalogApi()
 *
 * @param array $directories Directories to scan.
 *
 * @return array Associative array keyed by subdirectory (e.g., 'Models', 'Apis') with arrays of class names.
 */
function scan_for_square_references( array $directories ): array {
	$references = array();

	// Match EDD\Vendor\Square\ with or without a leading backslash.
	$namespacePattern = '/\\\\?EDD\\\\Vendor\\\\Square\\\\([A-Za-z\\\\]+)/';

	// Match SquareClient API getter calls like ->getPaymentsApi() or ->getCatalogApi().
	// These are lazy-loaded in SquareClient and map to the corresponding Api class file.
	$apiMethodPattern = '/->get([A-Za-z]+Api)\s*\(/';

	foreach ( $directories as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$content = file_get_contents( $file->getPathname() );

			// Scan for namespace-based references.
			if ( preg_match_all( $namespacePattern, $content, $matches ) ) {
				foreach ( $matches[1] as $match ) {
					// Split on backslash to get subdirectory and class.
					$parts = explode( '\\', $match );
					if ( count( $parts ) >= 2 ) {
						$subdir = $parts[0];
						$class  = end( $parts );
						if ( ! isset( $references[ $subdir ] ) ) {
							$references[ $subdir ] = array();
						}
						$references[ $subdir ][] = $class;
					} else {
						// Top-level class like SquareClient, Environment, etc.
						if ( ! isset( $references['_root'] ) ) {
							$references['_root'] = array();
						}
						$references['_root'][] = $parts[0];
					}
				}
			}

			// Scan for SquareClient API getter method calls.
			if ( preg_match_all( $apiMethodPattern, $content, $matches ) ) {
				if ( ! isset( $references['Apis'] ) ) {
					$references['Apis'] = array();
				}
				foreach ( $matches[1] as $apiClass ) {
					$references['Apis'][] = $apiClass;
				}
			}
		}
	}

	// Deduplicate.
	foreach ( $references as $key => $classes ) {
		$references[ $key ] = array_unique( $classes );
	}

	return $references;
}

/**
 * Scan kept API files for their Model dependencies.
 *
 * API classes reference Model classes in use statements, method signatures,
 * and ::class references. This captures those transitive dependencies.
 *
 * @param string $apisDir   Path to the Apis directory.
 * @param array  $keepApis  List of API filenames to keep.
 *
 * @return array Array of model class names referenced by kept APIs.
 */
function scan_api_model_dependencies( string $apisDir, array $keepApis ): array {
	$models  = array();
	$pattern = '/\\\\?EDD\\\\Vendor\\\\Square\\\\Models\\\\([A-Za-z]+)/';

	foreach ( $keepApis as $apiFile ) {
		$filePath = $apisDir . '/' . $apiFile;
		if ( ! file_exists( $filePath ) ) {
			continue;
		}

		$content = file_get_contents( $filePath );
		if ( preg_match_all( $pattern, $content, $matches ) ) {
			$models = array_merge( $models, $matches[1] );
		}
	}

	return array_unique( $models );
}

/**
 * Recursively scan model files for transitive model dependencies.
 *
 * Models reference other models via fully-qualified names (EDD\Vendor\Square\Models\Foo)
 * and via short names within the same namespace (@var Foo|null, ?Foo, Foo $param).
 * Both are caught here. Iterates until no new models are discovered.
 *
 * @since 3.7.0
 *
 * @param string $modelsDir  Path to the Models directory.
 * @param array  $keepModels Current list of model filenames to keep.
 *
 * @return array Updated list of model filenames including all transitive dependencies.
 */
function scan_transitive_model_dependencies( string $modelsDir, array $keepModels ): array {
	$fqPattern = '/\\\\?EDD\\\\Vendor\\\\Square\\\\Models\\\\([A-Za-z]+)/';

	// Build a lookup of every model class name that exists on disk so short-name
	// references (e.g. "@var Money|null" inside another model file) are caught.
	$allModelNames = array();
	foreach ( glob( $modelsDir . '/*.php' ) ?: array() as $file ) {
		$allModelNames[] = basename( $file, '.php' );
	}
	$shortPattern = '/\b(' . implode( '|', array_map( 'preg_quote', $allModelNames ) ) . ')\b/';

	$newFound = true;

	while ( $newFound ) {
		$newFound    = false;
		$currentList = $keepModels;

		foreach ( $currentList as $modelFile ) {
			$filePath = $modelsDir . '/' . $modelFile;
			if ( ! file_exists( $filePath ) ) {
				continue;
			}

			$content = file_get_contents( $filePath );

			// Fully-qualified references.
			if ( preg_match_all( $fqPattern, $content, $matches ) ) {
				foreach ( $matches[1] as $depClass ) {
					$depFile = $depClass . '.php';
					if ( ! in_array( $depFile, $keepModels, true ) ) {
						$keepModels[] = $depFile;
						$newFound     = true;
					}
				}
			}

			// Short-name references (same namespace, no use statement needed).
			if ( preg_match_all( $shortPattern, $content, $matches ) ) {
				foreach ( $matches[1] as $depClass ) {
					$depFile = $depClass . '.php';
					if ( ! in_array( $depFile, $keepModels, true ) ) {
						$keepModels[] = $depFile;
						$newFound     = true;
					}
				}
			}
		}
	}

	return array_unique( $keepModels );
}

/**
 * Recursively remove a directory and all its contents.
 *
 * @param string $dir Path to directory.
 *
 * @return int Number of files removed.
 */
function remove_directory_recursive( string $dir ): int {
	if ( ! is_dir( $dir ) ) {
		return 0;
	}

	$count    = 0;
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			if ( ! @rmdir( $item->getPathname() ) ) {
				echo sprintf( "Warning: Could not remove directory %s\n", $item->getPathname() );
			}
		} else {
			if ( ! @unlink( $item->getPathname() ) ) {
				echo sprintf( "Warning: Could not remove file %s\n", $item->getPathname() );
			} else {
				++$count;
			}
		}
	}

	if ( ! @rmdir( $dir ) ) {
		echo sprintf( "Warning: Could not remove directory %s\n", $dir );
	}

	return $count;
}

/**
 * Remove files from a directory that are not in the keep list.
 *
 * @param string $dir      Directory to clean.
 * @param array  $keepList List of filenames to keep.
 *
 * @return int Number of files removed.
 */
function cleanup_directory( string $dir, array $keepList ): int {
	if ( ! is_dir( $dir ) ) {
		return 0;
	}

	$removed = 0;
	$files   = glob( $dir . '/*.php' ) ?: array();

	foreach ( $files as $file ) {
		$filename = basename( $file );
		if ( ! in_array( $filename, $keepList, true ) ) {
			if ( ! @unlink( $file ) ) {
				echo sprintf( "Warning: Could not remove %s\n", $file );
			} else {
				++$removed;
			}
		}
	}

	return $removed;
}

/**
 * Clean up SquareClient.php by removing use statements and getter methods
 * for API classes that have been deleted.
 *
 * @since 3.7.0
 *
 * @param string $squareDir Path to the Square SDK directory.
 * @param array  $keepApis  List of API filenames that are kept.
 *
 * @return int Number of references removed.
 */
function cleanup_square_client( string $squareDir, array $keepApis ): int {
	$clientFile = $squareDir . '/SquareClient.php';
	if ( ! file_exists( $clientFile ) ) {
		return 0;
	}

	// Build list of kept API class names (without .php extension).
	$keptClasses = array();
	foreach ( $keepApis as $apiFile ) {
		$keptClasses[] = str_replace( '.php', '', $apiFile );
	}

	$lines     = file( $clientFile );
	$output    = array();
	$removed   = 0;
	$i         = 0;
	$lineCount = count( $lines );

	while ( $i < $lineCount ) {
		$line = $lines[ $i ];

		// Remove use statements for deleted API classes.
		if ( preg_match( '/^use EDD\\\\Vendor\\\\Square\\\\Apis\\\\([A-Za-z0-9]+);/', $line, $matches ) ) {
			if ( ! in_array( $matches[1], $keptClasses, true ) ) {
				++$removed;
				++$i;
				continue;
			}
		}

		// Detect docblocks that precede getter methods for deleted API classes.
		if ( preg_match( '/^\s*\/\*\*/', $line ) ) {
			// Peek ahead past the docblock to find the method signature.
			$peekEnd  = min( $i + 10, $lineCount );
			$apiClass = null;
			$sigIdx   = null;

			for ( $j = $i + 1; $j < $peekEnd; $j++ ) {
				if ( preg_match( '/^\s*public function get([A-Za-z0-9]+Api)\(\)/', $lines[ $j ], $methodMatch ) ) {
					$apiClass = $methodMatch[1];
					$sigIdx   = $j;
					break;
				}
				// Stop if we hit something that isn't part of a docblock.
				if ( ! preg_match( '/^\s*(\*|\/\*\*)/', $lines[ $j ] ) ) {
					break;
				}
			}

			// If this docblock belongs to a deleted API getter, skip the entire block.
			if ( $apiClass !== null && ! in_array( $apiClass, $keptClasses, true ) ) {
				// Skip from docblock start through end of method by counting braces.
				$braceCount = 0;
				$inMethod   = false;
				$k          = $sigIdx;

				while ( $k < $lineCount ) {
					$braceCount += substr_count( $lines[ $k ], '{' );
					$braceCount -= substr_count( $lines[ $k ], '}' );

					if ( $braceCount > 0 ) {
						$inMethod = true;
					}

					++$k;

					if ( $inMethod && $braceCount === 0 ) {
						break;
					}
				}

				// Skip a trailing blank line if present.
				if ( $k < $lineCount && trim( $lines[ $k ] ) === '' ) {
					++$k;
				}

				++$removed;
				$i = $k;
				continue;
			}
		}

		$output[] = $line;
		++$i;
	}

	if ( $removed > 0 ) {
		file_put_contents( $clientFile, implode( '', $output ) );
	}

	return $removed;
}

// Step 1: Scan the EDD codebase for Square references.
$scanDirs   = array(
	$projectRoot . '/src',
	$projectRoot . '/includes',
);
$references = scan_for_square_references( $scanDirs );

// Step 2: Build the API keep list.
$keepApis = array( 'BaseApi.php' ); // Always keep BaseApi.
if ( ! empty( $references['Apis'] ) ) {
	foreach ( $references['Apis'] as $apiClass ) {
		$keepApis[] = $apiClass . '.php';
	}
}
$keepApis = array_unique( $keepApis );

// Step 3: Scan kept API files for their model dependencies.
$apiModelDeps = scan_api_model_dependencies( $squareDir . '/Apis', $keepApis );

// Step 4: Build the Model keep list from EDD references + API dependencies.
$keepModels = array();
if ( ! empty( $references['Models'] ) ) {
	foreach ( $references['Models'] as $modelClass ) {
		$keepModels[] = $modelClass . '.php';
	}
}
foreach ( $apiModelDeps as $modelClass ) {
	$keepModels[] = $modelClass . '.php';
}
$keepModels = array_unique( $keepModels );

// Step 4b: Recursively scan kept models for transitive dependencies.
$keepModels = scan_transitive_model_dependencies( $squareDir . '/Models', $keepModels );

// Step 5: Report what was found.
echo sprintf( "Found %d API classes referenced in codebase.\n", count( $keepApis ) - 1 );
echo sprintf( "Found %d Model classes referenced (direct + API + transitive dependencies).\n", count( $keepModels ) );

// Step 6: Clean up.
$totalRemoved = 0;

// Remove the entire Builders directory (EDD uses none of them).
$buildersDir = $squareDir . '/Models/Builders';
if ( is_dir( $buildersDir ) ) {
	$removed       = remove_directory_recursive( $buildersDir );
	$totalRemoved += $removed;
	echo sprintf( "Removed Models/Builders/ directory (%d files).\n", $removed );
}

// Clean up unused Models.
$removed       = cleanup_directory( $squareDir . '/Models', $keepModels );
$totalRemoved += $removed;
echo sprintf( "Removed %d unused Model files.\n", $removed );

// Clean up unused APIs.
$removed       = cleanup_directory( $squareDir . '/Apis', $keepApis );
$totalRemoved += $removed;
echo sprintf( "Removed %d unused API files.\n", $removed );

// Clean up SquareClient.php dead references.
$clientRemoved = cleanup_square_client( $squareDir, $keepApis );
echo sprintf( "Removed %d dead references from SquareClient.php.\n", $clientRemoved );

// Step 7: Summary.
echo sprintf(
	"\nSquare SDK cleanup complete: %d files removed.\n",
	$totalRemoved
);

// Report remaining size.
$remainingFiles = 0;
$iterator       = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $squareDir, RecursiveDirectoryIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( $file->isFile() ) {
		++$remainingFiles;
	}
}
echo sprintf( "Remaining Square SDK files: %d\n", $remainingFiles );
