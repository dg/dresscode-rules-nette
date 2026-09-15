<?php declare(strict_types=1);

if (@!include __DIR__ . '/../vendor/autoload.php') { // @ dependencies may not be installed
	echo 'Install Nette Tester using `composer install`';
	exit(1);
}

Tester\Environment::setup();
Tester\Environment::setupFunctions();


/** The grammar of the one map a rule reads, as its decision carries it. */
function grammarOf(string $rule): Nette\Schema\Schema
{
	$domain = $rule::getDecisions()[0]->domain;
	assert($domain instanceof DressCode\Domains\Map && $domain->grammar !== null);
	return $domain->grammar;
}
