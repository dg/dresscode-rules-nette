<?php declare(strict_types=1);

/**
 * Checks the upgrading file and the sample of one library, which is what writing the data of a library needs:
 *
 *   php tests/check.php <library> [--update]
 *
 * Lints upgrading/<library>.neon against the installed library, runs tests/samples/<library>.code and compares
 * the result with <library>.expected and <library>.violations; --update writes those two from the run instead.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/samples.php';

$library = $argv[1] ?? exit("Usage: php tests/check.php <library> [--update]\n");
$update = in_array('--update', $argv, true);
$failed = false;

$problems = lintLibrary($library);
echo $problems === [] ? "lint: ok\n" : "lint:\n  " . implode("\n  ", $problems) . "\n";
$failed = $problems !== [];

$sample = __DIR__ . "/samples/$library";
if (!is_file("$sample.code")) {
	echo "sample: tests/samples/$library.code is missing\n";
	exit(1);
}

[$output, $violations] = runSample($library);
foreach (['expected' => $output, 'violations' => $violations] as $extension => $actual) {
	if ($update) {
		file_put_contents("$sample.$extension", $actual);
		echo "sample: $library.$extension written\n";
	} elseif (!is_file("$sample.$extension") || file_get_contents("$sample.$extension") !== $actual) {
		echo "sample: $library.$extension differs from the run, which gives:\n$actual\n";
		$failed = true;
	} else {
		echo "sample: $library.$extension ok\n";
	}
}

exit($failed ? 1 : 0);
