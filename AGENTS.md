# To My Agents!

It is my fervent wish that this file guide every AI coding agent working with code in this repository.


## What this is

Data for DressCode: what the versions of the Nette libraries renamed, moved and retired, and what to write instead.
One file per library, `upgrading/<library>.neon`, listed under `extra.dresscode.upgrading` of `composer.json`; the
libraries themselves are in `require-dev`, so that the data are checked against what is installed. DressCode is
required as `dresscode/dresscode`.

Besides the data the package ships the rules the data of an ecosystem need and DressCode itself does not carry,
in `src/` under `DressCode\Nette`, named `nette/<name>`. `DressCode\Nette\Extension` makes them known, `composer.json` names it
under `extra.dresscode.extension`, and `tests/rules.phpt` runs their fixtures from `tests/fixtures/<name>/`.

Where an entry comes from is the upgrading guide of the library (`docs/upgrading.md` in its repository), checked
against the code of the library at its tags: the tag decides, not the guide.


## Essential commands

- `php tests/check.php <library>`: the lint of one file and its sample; the verdict is the exit code.
- `php tests/check.php <library> --update`: writes `tests/samples/<library>.expected` and `.violations` from the run.
  Always read the diff of `.code` against `.expected`: a wrong fix recorded there is a wrong fix tested ever after.
- `vendor/bin/tester tests`: every file and every sample.
- After a change of DressCode itself, `composer reinstall dresscode/dresscode`; the path repository is a copy.


## Writing the data

- A file starts with `package: vendor/name` and `group: deprecations`, the intent of its data, and goes on with
  sections `since <version>`, newest first, the version without trailing zeros (`since 3.1`). The order decides
  nothing, the sections are merged by version.
- Decide by what happens to the code, not by the label of the guide: a member called differently and used the same
  way is `replaced-members`, one used differently but writable as one expression is `replaced-calls`, flags turned
  into named arguments are `nette/named-arguments-for-flags`, what has no replacement or one of another nature (another
  type, another behavior) is `forbidden-classes` or `forbidden-members` with a sentence, and a change of behavior,
  of configuration, of Latte syntax, an `@internal` symbol or a signature changed only for types is nothing.
- What a library deprecates only silently does not go into `forbidden-*`; `no-deprecated-classes` and
  `no-deprecated-members` report it from the types.
- A key with parentheses or a dollar goes in apostrophes. `Class::name()` is a call without arguments,
  `Class::name(...$args)` one with any, `Class::NAME` without a lower-case letter a constant only.
- What a later version took back gets `keep` in the section of that version. A chain across versions is written link
  by link; the lint follows it to its end.
- A sentence of `forbidden-*` is English, completes `… is forbidden:` and says what to write instead: lower case
  unless it begins with a name, no period, no backticks or double quotes, at most 160 characters, which the lint
  checks. One situation is said one way:
  - a replacement: `<verb> <API>[, which <how it differs>]`, the verb one of call, use, pass, read, register,
    implement, create, catch, test, override;
  - no replacement: `there is no replacement[, <why>][; <what to do>]`;
  - nothing to write: `…; drop the call`, `drop the argument`, `drop the flag`, `drop the setting`;
  - a named argument: `pass <name>: <value> to <method>()`.

  A member of the class of the key is named alone (`setHost()`), any other class fully qualified. Two clauses are
  joined by a semicolon; `test`, never `check`; `such as`, never `e.g.`; no `you`, no trailing `instead`, no `should`.
- A sample holds code of the old API the way an application writes it, a child overriding a method among it, and
  every shape the data fix or report; it may declare classes of its own.
