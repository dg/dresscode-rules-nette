<?php declare(strict_types=1);

use DressCode\Testing\UpgradingTester;

/** The rules the upgrading files feed, which a sample is run with. */
const UpgradingRules = [
	'replaced-classes',
	'replaced-members',
	'replaced-calls',
	'forbidden-classes',
	'forbidden-members',
	'attribute-for-annotation',
];


/**
 * What the upgrading file of a library has wrong, checked against the installed library.
 * @return list<string>
 */
function lintLibrary(string $library): array
{
	$root = dirname(__DIR__);
	return UpgradingTester::check("$root/upgrading/$library.neon", $root);
}


/**
 * The sample of a library, code written for its old API, as the upgrading files fix it, and what they report in it:
 * the fixed code, and the violations as `line: message`, the fixed ones among them.
 * @return array{string, string}
 */
function runSample(string $library): array
{
	$code = (string) file_get_contents(__DIR__ . "/samples/$library.code");
	$result = UpgradingTester::runSample($code, dirname(__DIR__), UpgradingRules, $library);
	$violations = array_map(fn($violation) => "$violation->line: $violation->message", $result->violations);
	return [(string) $result->output, implode("\n", $violations) . "\n"];
}
