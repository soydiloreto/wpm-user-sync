<?php
/**
 * Line coverage of the plugin's PHP (src/, the main file, uninstall.php and
 * legacy-deprecated.php: everything that ships), per test layer and all layers
 * together.
 *
 * Reads what `make coverage-unit`, `coverage-integration` and `coverage-e2e`
 * left in build/coverage/: the unit and integration suites' --coverage-php
 * reports and the end-to-end collector's JSON files, and counts lines the
 * way PHPUnit does, so the three layers are measured alike.
 *
 * Prints a table and exits 1 when all layers together are below MIN
 * (percent, argv[1]) or a layer is below LAYER_MIN (argv[2]). Under each
 * file it lists the lines no layer runs, or one layer (argv[3]) does not.
 * Run inside the wp-env tests-cli container: `make coverage-report`.
 *
 * @package WPMUS\Tests\Coverage
 */

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingFileAnalyser;

$root = dirname( __DIR__, 2 );
require $root . '/vendor/autoload.php';

$min       = (float) ( $argv[1] ?? 0 );
$layer_min = (float) ( $argv[2] ?? 0 );
$show      = (string) ( $argv[3] ?? 'all' );
$dir       = $root . '/build/coverage';
$layers    = array( 'unit', 'integration', 'e2e' );

// Executable lines, from the static analysis PHPUnit uses (which honours
// `@codeCoverageIgnore`): each line with the statement it belongs to.
$analyser   = new ParsingFileAnalyser( true, false );
$executable = array();
$statement  = array();
$files      = array_merge(
	array( $root . '/wpm-user-sync.php', $root . '/uninstall.php', $root . '/legacy-deprecated.php' ),
	iterator_to_array( new RegexIterator( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ), '/\.php$/' ), false )
);
foreach ( $files as $file ) {
	$file     = (string) $file;
	$relative = substr( $file, strlen( $root ) + 1 );
	$map      = array_diff_key( $analyser->executableLinesIn( $file ), array_flip( $analyser->ignoredLinesFor( $file ) ) );
	if ( $map ) {
		$executable[ $relative ] = array_keys( $map );
		$statement[ $relative ]  = $map;
	}
}

// What each layer ran, by path relative to the plugin. The PHPUnit reports
// already count a statement spread over several lines as run on all of them
// when one of them ran; the end-to-end lines Xdebug recorded get the same rule.
$run = array_fill_keys( $layers, array() );
foreach ( array( 'unit', 'integration' ) as $layer ) {
	if ( ! is_file( "$dir/$layer.cov" ) ) {
		continue;
	}
	$report = include "$dir/$layer.cov";
	foreach ( $report->getData( true )->lineCoverage() as $file => $lines ) {
		foreach ( $lines as $line => $tests ) {
			if ( $tests ) {
				$run[ $layer ][ substr( $file, strlen( $root ) + 1 ) ][ $line ] = true;
			}
		}
	}
}
$ran_statements = array();
foreach ( glob( "$dir/e2e/*.json" ) ?: array() as $file ) {
	$recorded = json_decode( (string) file_get_contents( $file ), true );
	if ( ! is_array( $recorded ) ) {
		fwrite( STDERR, "✖ $file is not a coverage record: run make coverage again.\n" );
		exit( 1 );
	}
	foreach ( $recorded as $relative => $lines ) {
		foreach ( $lines as $line ) {
			if ( isset( $statement[ $relative ][ $line ] ) ) {
				$ran_statements[ $relative ][ $statement[ $relative ][ $line ] ] = true;
			}
		}
	}
}
foreach ( $ran_statements as $relative => $ids ) {
	foreach ( $statement[ $relative ] as $line => $id ) {
		if ( isset( $ids[ $id ] ) ) {
			$run['e2e'][ $relative ][ $line ] = true;
		}
	}
}
ksort( $executable );

$covered = static function ( string $layer, string $file ) use ( $executable, $run, $layers ): array {
	$sources = 'all' === $layer ? $layers : array( $layer );
	return array_values(
		array_filter(
			$executable[ $file ],
			static function ( $line ) use ( $sources, $run, $file ): bool {
				foreach ( $sources as $source ) {
					if ( isset( $run[ $source ][ $file ][ $line ] ) ) {
						return true;
					}
				}
				return false;
			}
		)
	);
};
$pct = static fn ( int $n, int $total ): float => $total ? 100 * $n / $total : 100.0;

printf( "%-42s %5s %7s %7s %7s %7s\n", 'File', 'Lines', 'Unit', 'Integr.', 'E2E', 'All' );
$totals = array_fill_keys( array( 'unit', 'integration', 'e2e', 'all' ), 0 );
foreach ( $executable as $file => $lines ) {
	printf( '%-42s %5d', $file, count( $lines ) );
	foreach ( array_keys( $totals ) as $layer ) {
		$n                = count( $covered( $layer, $file ) );
		$totals[ $layer ] += $n;
		printf( ' %6.1f%%', $pct( $n, count( $lines ) ) );
	}
	echo "\n";
	$missing = array_diff( $lines, $covered( $show, $file ) );
	if ( $missing ) {
		echo '    not run by ', 'all' === $show ? 'any layer' : $show, ': lines ', implode( ', ', $missing ), "\n";
	}
}

$total  = array_sum( array_map( 'count', $executable ) );
$failed = false;
echo "\n";
foreach ( $totals as $layer => $n ) {
	$p      = $pct( $n, $total );
	$floor  = 'all' === $layer ? $min : $layer_min;
	$below  = $p < $floor;
	$failed = $failed || $below;
	printf( "%-12s %6.2f%% (%d/%d)%s\n", $layer, $p, $n, $total, $below ? sprintf( '  ✖ below %s%%', $floor ) : '' );
}

exit( $failed ? 1 : 0 );
