<?php declare(strict_types=1);

namespace DressCodeUpgrade\Nette;

use DressCode\PluginManifest;


/**
 * The rules this package brings to a project that has it, known by their names; the files of `upgrading/` reach
 * DressCode through `extra.dresscode.upgrading-dir`, not through the plugin, and turn its rules on from their versions.
 */
final class Plugin implements \DressCode\Plugin
{
	public function getManifest(): PluginManifest
	{
		return new PluginManifest(
			rules: [
				NamedArgumentsForFlagsRule::class,
				MonitorForAttachedHookRule::class,
				LinkDestinationNotationRule::class,
			],
			section: 'nette',
		);
	}
}
