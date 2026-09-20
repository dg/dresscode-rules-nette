<?php declare(strict_types=1);

/** The fixtures and the examples of every rule of this package, which run against the libraries of require-dev or the stubs beside them. */

use DressCode\Testing\RuleTester;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$rules = [
	'named-arguments-for-flags' => DressCode\Nette\NamedArgumentsForFlagsRule::class,
];

foreach ($rules as $slug => $class) {
	$examples = __DIR__ . "/../examples/$slug";
	foreach ([__DIR__ . "/fixtures/$slug", $examples] as $dir) {
		foreach (glob("$dir/*.code") ?: [] as $file) {
			// what the rule says and where is part of its contract, so every fixture records it
			Assert::true(is_file(preg_replace('~\.code$~', '.violations', $file)), basename($file) . ' has no .violations file.');
		}
	}

	Assert::noError(fn() => RuleTester::run($class, __DIR__ . "/fixtures/$slug"));
	if (is_dir($examples)) {
		Assert::noError(fn() => RuleTester::run($class, $examples));
	}
}

// an example in a directory no rule owns would never be run against anything
foreach (glob(__DIR__ . '/../examples/*', GLOB_ONLYDIR) ?: [] as $dir) {
	Assert::true(isset($rules[basename($dir)]), $dir);
}
