# dresscode/rules-nette

What the versions of the Nette libraries renamed, moved and retired, and what to write instead, as data for
[DressCode](https://dresscode.run). With it installed, `dresscode fix` rewrites code written for older
versions of Nette to the API of the versions the project stands on, and reports what has to be rewritten by hand,
with what to write instead.

It covers all twenty Nette libraries from their versions 3.0 on, more than 900 entries: interfaces that lost their
`I` prefix, constants renamed from upper case, renamed methods and parameters, magic properties replaced by getters
and setters, flags replaced by named arguments, annotations replaced by attributes.


Installation
------------

```shell
composer require --dev dresscode/rules-nette
```

DressCode finds the package by itself. The data apply in the rules of the group `deprecations`, most of which need the
types of the code from PHPStan:

```neon
types: phpstan

groups:
	- deprecations
```

The data of a library apply only when the project has it, and only the sections of the versions the project stands on:
the lowest version its constraint in `composer.json` allows, or the version the key `packages` of the configuration
names. To move code one major version at a time, name the next version there while the old one is still installed:

```neon
packages:
	nette/forms: '3.3'
```


What it holds
-------------

`upgrading/<library>.neon`, one file per library, each a map of rules in sections `since <version>`, written newest
first:

| rule | what an entry does |
|---|---|
| `replaced-classes` | writes another class, interface or enum |
| `replaced-members` | writes another constant, method or property, used the same way |
| `replaced-calls` | writes a call, an access or an instantiation the way the new API takes it |
| `nette/named-arguments-for-flags` | writes named arguments for the flags of an integer; the rule comes with this package |
| `attribute-for-annotation` | writes an attribute for an annotation |
| `forbidden-classes`, `forbidden-members` | reports what has no replacement, with what to do instead |

What a library only deprecated silently, what changed its behavior, the configuration in NEON and the syntax of Latte
templates are not in the data.

Besides the data, the package brings rules that read the code alone:

| rule | what it does |
|---|---|
| `nette/monitor-for-attached-hook` | writes the hooks `attached()` and `detached()` of a component as the callbacks of `monitor()` |
| `nette/link-destination-notation` | passes the query string and the fragment of a link destination as its arguments |


Development
-----------

The libraries the data are about are in `require-dev`, so the data are checked against their installed versions:

- `php tests/check.php <library>` lints `upgrading/<library>.neon` against the installed library and runs its sample,
  `tests/samples/<library>.code`, comparing the result with `.expected` and `.violations`,
- `php tests/check.php <library> --update` writes those two from the run; read the diff, it is what the data do,
- `vendor/bin/tester tests` runs all of it.

How the keys and values are written is described on the page "Maps of replacements" of the DressCode manual.
