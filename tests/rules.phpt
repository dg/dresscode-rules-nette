<?php declare(strict_types=1);

/** The fixtures of every rule of this package, which run against the libraries of require-dev or the stubs beside them. */

use DressCode\Testing\RuleTester;
use Tester\Assert;

require __DIR__ . '/bootstrap.php';

$rules = [
	'link-destination-notation' => DressCode\Nette\LinkDestinationNotationRule::class,
	'monitor-for-attached-hook' => DressCode\Nette\MonitorForAttachedHookRule::class,
	'named-arguments-for-flags' => DressCode\Nette\NamedArgumentsForFlagsRule::class,
];

foreach ($rules as $slug => $class) {
	foreach (glob(__DIR__ . "/fixtures/$slug/*.code") ?: [] as $file) {
		// what the rule says and where is part of its contract, so every fixture records it
		Assert::true(is_file(preg_replace('~\.code$~', '.violations', $file)), basename($file) . ' has no .violations file.');
	}

	Assert::noError(fn() => RuleTester::run($class, __DIR__ . "/fixtures/$slug"));
}
