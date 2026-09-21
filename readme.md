DressCode Rules for Nette
=========================

[![Latest Stable Version](https://poser.pugx.org/dresscode/rules-nette/v/stable)](https://packagist.org/packages/dresscode/rules-nette)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](https://packagist.org/packages/dresscode/rules-nette)

 <!---->

<h3>

✅ Upgrades code for [every Nette library](#nette-libraries-and-versions-covered), from 3.0 to today<br>
✅ Knows [what each variable is](#what-gets-upgraded), so it rewrites only the right calls<br>
✅ Reports [what needs a human](#what-is-left-to-you), with what to write instead<br>
✅ From the author of Nette, [written from every commit](#where-the-data-come-from) of its libraries

</h3>

 <!---->

**Upgrade a Nette application without hunting for renames.** Install this package, run `dresscode fix`, and code
written for older versions of Nette is rewritten to the API of the versions your project stands on:

```diff
- /** @persistent */
+ #[Persistent]

- private Context $database,
+ private Explorer $database,

- $page = $this->getParameter('page', 1);
+ $page = $this->getParameter('page') ?? 1;

- $this->invalidateControl('list');
+ $this->redrawControl('list');

- $form->addUpload('photos', 'Photos', true);
+ $form->addMultiUpload('photos', 'Photos');

- Json::encode($values, Json::PRETTY)
+ Json::encode($values, pretty: true)
```

Six changes from four libraries, the imports rewritten along with them, and not a byte more of the file touched.
The package holds the data, what each version of Nette renamed, moved or retired; the rewriting is done by
[DressCode](https://dresscode.run), the PHP coding standard and upgrade tool, which comes with it.

 <!---->

Installation and first run
==========================

**1️⃣ Install it: `composer require --dev dresscode/rules-nette`**<br>
**2️⃣ Turn the upgrade on in `dresscode.neon`**<br>
**3️⃣ Look at the changes and make them: `vendor/bin/dresscode check --diff`, `vendor/bin/dresscode fix`**

The package brings DressCode along, and DressCode reads its upgrading data by itself; its rules are turned on by
naming the package in `use`. The upgrade is the set `deprecations`, and most of its rewrites need to know what a
variable is, which DressCode asks your PHPStan:

```neon
typeAnalysis: phpstan

use:
	- dresscode/rules-nette
	- deprecations
```

If your project already has a `dresscode.neon`, add the two keys to it. If it does not, these lines are the whole
file: with no coding standard named, DressCode upgrades the code and leaves its style alone. Then run your tests and
PHPStan, read the diff, and commit.

 <!---->

What gets upgraded
==================

None of it is search and replace. DressCode asks PHPStan what each variable is, so `getName()` is rewritten where it
is called on a `FileUpload` and nowhere else, and `$this` is known to be a presenter even in a method inherited from
a parent. What the package rewrites:

- **renamed classes and interfaces**: `Nette\Configurator` to `Nette\Bootstrap\Configurator`, `IRouter` to
  `Nette\Routing\Router`, `IControl` to `Nette\Forms\Control`, with the import, the type, `new`, `instanceof` and
  attributes,
- **constants out of upper case**: `Form::FILLED` to `Form::Filled`, `IResponse::S404_NOT_FOUND` to
  `IResponse::S404_NotFound`, `Cache::EXPIRE` to `Cache::Expire`, chains across several versions included,
- **renamed methods**: `invalidateControl()` to `redrawControl()`, `getUnsafeValues()` to `getUntrustedValues()`,
  `Strings::contains()` to `str_contains()`,
- **renamed parameters** of calls with named arguments: `setExpiration(time: ...)` to `setExpiration(expire: ...)`,
  also in the `parent::__construct()` of a child,
- **a default moved behind `??`**: `getParameter('page', 1)` to `getParameter('page') ?? 1`, the same with
  `getQuery()`, `getPost()`, `getCookie()`, `getHeader()` and `getOption()`,
- **magic properties to getters and setters**: `$form->action`, `$control->caption`, `$section->user`,
  `$section['user']`,
- **flags to named arguments**: `Json::encode($value, Json::PRETTY)`, `Strings::match()` and its kin with `PREG_*`
  constants, `Neon::encode()` with `Neon::BLOCK`, the router with `Router::ONE_WAY`,
- **calls of another shape**: `addUpload($name, $label, true)` to `addMultiUpload()`, `getValues(true)` to
  `getValues('array')`, `Callback::closure()` to `Closure::fromCallable()`,
- **annotations to attributes**: `@persistent` and `@crossOrigin` in presenters, `@filter` and `@function` in the
  classes of Latte template parameters,
- **component hooks** `attached()` and `detached()` to the callbacks of `monitor()` in the constructor, and the query
  string and the fragment of a link destination, `link('Product:show?id=1#reviews')`, to its arguments.

The rewritten code takes the shape of the file around it, or of your coding standard, if the configuration names one.

 <!---->

What is left to you
===================

Where there is no replacement, or one that needs a decision, DressCode does not guess. It reports the place and says
what to write instead:

```
Constant `Nette\Application\IRouter::SECURED` is forbidden: write the protocol, `https://`, into the mask of the route
Method `Nette\Http\UrlScript::setPath()` is forbidden: the class is immutable; take a changed copy with `withPath()`
```

An upgrade is a rewrite plus a list of what remains, and the list is half of its value. Every such sentence is
written as an instruction, so the list can be worked through by you or handed to an AI coding agent as it is.

A few rewrites could change what the code does, such as a property read on the left of `??`, where PHP first asks
whether the property is set. Those wait for your consent: `dresscode fix --review` asks about each one with its diff, `--fix-risky` makes them all.

 <!---->

One major version at a time
===========================

The data of each library apply only when your project has it, and only the sections of the versions it stands on:
the lowest version the constraint in `composer.json` allows. So an upgrade goes the way you would do it by hand:
raise the constraint to the next major version, `composer update`, `dresscode fix`, tests, commit, and on to the
next one. In each step the data meet the installed library, whose `@deprecated` annotations tell DressCode what the
next version will remove.

Code can also be upgraded before the library is, with the key `targets`, which names the version to write for:

```neon
targets:
	nette/application: '3.2'
	nette/forms: '3.2'
```

`dresscode config` shows for every library the version the data start from and the versions a further update would
lead to. An application written for Nette 2.4 is upgraded the same way, starting with 3.0.

 <!---->

Where the data come from
========================

The data come from the author of Nette. Every library first got an upgrading guide, `docs/upgrading.md` in its
repository, written from its whole history: thousands of commits read one by one, and the public API of each release
compared with the one before. Every entry was then checked against the code of the release that made the change,
because the tag decides, not anyone's memory.

The data are also checked against the installed libraries, so a replacement that does not exist cannot get in, and
each library has a sample of old code that the data must turn into the expected new code. And they are kept where
Nette is made, by the same hands that change the libraries.

Each entry is a line of text, even one that changes the shape of a call:

```neon
since 3.1:
	replacedClasses:
		Nette\Database\Context: Nette\Database\Explorer
	replacedMembers:
		'Nette\Http\FileUpload::getName()': getUntrustedName
	replacedCalls:
		'Nette\Database\Explorer::queryArgs($sql, $params)': 'query($sql, ...$params)'
```

 <!---->

Nette libraries and versions covered
====================================

All of them, with more than 900 entries, most libraries from their version 3.0 to the current one:
`nette/application`, `nette/assets`, `nette/bootstrap`, `nette/caching`, `nette/component-model`, `nette/database`,
`nette/di`, `nette/forms`, `nette/http`, `nette/mail`, `nette/neon`, `nette/php-generator`, `nette/robot-loader`,
`nette/routing`, `nette/schema`, `nette/security`, `nette/tester`, `nette/utils`, `latte/latte` and `tracy/tracy`.
The [manual](https://dresscode.run/upgrading-nette) lists the versions of each.

 <!---->

Limits
======

- **Most rewrites need PHPStan.** Without `typeAnalysis: phpstan`, only classes, functions and annotations are rewritten.
- **DressCode runs on PHP 8.4 to 8.6.** The code it upgrades may be written for PHP 8.0 and newer, but the package is
  installed into the project, so the project has to install on PHP 8.4 or newer.
- **The data fix the code, not its meaning.** A change of behavior, the configuration in NEON and the syntax of Latte
  templates are not in the data; the upgrading guides of the libraries describe them. Run your tests after a fix.

 <!---->

Other ecosystems
================

| package | upgrades |
|---|---|
| [`dresscode/rules-nette`](https://github.com/dg/dresscode-rules-nette) | every Nette library, from 3.0 |
| [`dresscode/rules-symfony`](https://github.com/dg/dresscode-rules-symfony) | the components and bridges of Symfony, from 6.0 |
| [`dresscode/rules-laravel`](https://github.com/dg/dresscode-rules-laravel) | the Laravel framework from 6, and the attributes of Laravel 13 |
| [`dresscode/rules-deegee`](https://github.com/dg/dresscode-rules-deegee) | Dibi and Texy |

A package like these can be written for any library; the [manual](https://dresscode.run/upgrading-data) says how.

 <!---->

Development
===========

The libraries the data are about are in `require-dev`, so the data are checked against their installed versions:

- `php tests/check.php <library>` lints `upgrading/<library>.neon` and runs its sample,
  `tests/samples/<library>.code`, comparing the result with `.expected` and `.violations`,
- `php tests/check.php <library> --update` writes those two from the run; read the diff, it is what the data do,
- `vendor/bin/tester tests` runs all of it.
