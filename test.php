<?php

require_once __DIR__ . '/vendor/autoload.php';

use FlameGraph\FlameGraph;

$test_samples = array(
	'{main} 23381',
	'{main};str_split 64',
	'{main};ret_ord 215',
	'{main};ret_ord;ord 106',
);

try {
	FlameGraph::build( $test_samples )
		->to_svg()
		->save( 'test.svg' );
} catch ( Exception $ex ) {
	error_log( "Failed to build graph: {$ex->getMessage()}" );
	exit(1);
}

try {
	$markup = FlameGraph::build( $test_samples, array( 'title' => 'Test' ) )
		->to_svg()
		->get();

	if ( false === strpos( $markup, 'Test' ) ) {
		error_log( "Title option isn't working: {$markup}" );
		exit(1);
	}
} catch ( Exception $ex ) {
	error_log( "Failed to build graph: {$ex->getMessage()}" );
	exit(1);
}
