<?php declare(strict_types=1);

use Tester\Assert;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/samples.php';


test('every upgrading file is what the rules accept, and replaces by what the installed libraries have', function () {
	$problems = [];
	foreach (glob(__DIR__ . '/../upgrading/*.neon') ?: [] as $file) {
		$library = basename($file, '.neon');
		foreach (lintLibrary($library) as $problem) {
			$problems[] = "$library: $problem";
		}
	}

	Assert::same([], $problems);
});


test('the sample of a library, code written for its old API, is fixed and reported the way its files say', function () {
	foreach (glob(__DIR__ . '/samples/*.code') ?: [] as $file) {
		$library = basename($file, '.code');
		[$output, $violations] = runSample($library);
		Assert::same(file_get_contents(__DIR__ . "/samples/$library.expected"), $output, "$library.expected");
		Assert::same(file_get_contents(__DIR__ . "/samples/$library.violations"), $violations, "$library.violations");
	}
});
