<?php declare(strict_types=1);

namespace DressCodeRules\Nette;

use DressCode\PluginManifest;


/**
 * The rules this package brings to a project that has it, known by their names; the files of `upgrading/` reach
 * DressCode through `extra.dresscode.upgrading`, not through the plugin.
 */
final class Plugin implements \DressCode\Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(rules: [NamedArgumentsForFlagsRule::class], section: 'nette');
	}
}
