<?php declare(strict_types=1);

namespace DressCode\Nette;

use DressCode\Config;


/**
 * The rules this package brings to a project that has it, known by their names; the files of `upgrading/` reach
 * DressCode through `extra.dresscode.upgrading`, not through the extension.
 */
final class Extension implements \DressCode\Extension
{
	public function getConfig(): Config
	{
		return new Config(extensions: [NamedArgumentsForFlagsRule::class]);
	}
}
