<?php declare(strict_types=1);

namespace DressCodeRules\Nette;

use DressCode\Analyses\{MemberAccess, Parameter, Types};
use DressCode\{ConfigurationException, Decision, NodeRule, RuleContext, RuleInfo, Stage, Values, Violation};
use DressCode\Domains\{Data, Map};
use DressCode\Rules\Upgrading\{MemberMaps, MemberPattern};
use Nette\Schema\{Expect, Schema};
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{Builder, Node, Token};
use PhpSyntax\Nodes\{ArgumentListNode, ArgumentNode, ExpressionNode, IdentifierNode, NameNode};
use PhpSyntax\Nodes\Expression\{BinaryOpNode, ClassConstantFetchNode, ConstantFetchNode, FunctionCallNode, MethodCallNode, NewNode, ParenthesizedNode, StaticMethodCallNode};
use PhpSyntax\Nodes\Scalar\IntegerNode;
use function count, is_bool;


/**
 * A tool for a method that takes named arguments where it took an integer of flags: the project, or a library it
 * stands on, maps a call to what each flag becomes, and the rule writes `Json::encode($value, pretty: true,
 * asciiSafe: true)` for `Json::encode($value, Json::PRETTY | Json::ESCAPE_UNICODE)`. Whose method a call reaches
 * is decided by the type of what it is made on, as replacedCalls decides it.
 *
 * A key is a method or an instantiation with the shape of its arguments, `Class::name($value, $flags)`, the last
 * item of which, or the last before `...`, is the placeholder of the flags; the value maps each flag, `Class::NAME`
 * or a global `NAME`, to the named arguments it becomes, `{pretty: true}`, or to none, `{}`, where the method now
 * does always what the flag asked for. The flags are the constants joined by `|`; a call whose flags are anything
 * else is reported and left alone, and so is one with an argument behind them the method gives no name, and one
 * that already names an argument a flag becomes. An argument behind the flags by its position takes the name of
 * its parameter. An argument passed there by its name, or as a literal, true, false or a bare 0 alike, is the call
 * the method takes now, and is left alone.
 */
#[RuleInfo(Stage::Structure, typesRequired: true)]
final class NamedArgumentsForFlagsRule extends NodeRule
{
	private const Map = 'nette.flagsAsNamedArguments';

	/** @var array<string, list<array{MemberPattern, array{string, array<string, array<string, scalar|null>>, int}}>>  lowercased name → the entries of that name with the placeholder of the flags, the arguments of each flag and the count of the arguments up to the flags */
	private array $byName = [];

	/** @var array<string, int>  lowercased name → the fewest arguments a call of that name has to pass to reach the flags */
	private array $minArguments = [];


	public static function getDecisions(): array
	{
		return [
			new Decision(self::Map, new Map(new Data, grammar: self::createGrammar()), 'A call passing the named arguments its method takes for the flags of an integer')];
	}


	/** The grammar of the entries of the map. */
	private static function createGrammar(): Schema
	{
		return MemberMaps::createMapSchema(
			Expect::arrayOf(Expect::arrayOf(Expect::scalar()->nullable(), Expect::string()), Expect::string()),
			'The call, `Class::name($value, $flags)` with the placeholder of the flags last → the flag, `Class::NAME` or `NAME` → the named arguments it becomes, `{pretty: true}`',
			self::readFlags(...),
		);
	}


	/** @throws ConfigurationException where an entry is not a call with the flags of its arguments */
	public function configure(Values $values): void
	{
		$this->byName = MemberMaps::indexEntries($values->read(self::Map), self::readFlags(...));
		foreach ($this->byName as $name => $entries) {
			foreach ($entries as [, [, , $count]]) {
				$this->minArguments[$name] = min($count, $this->minArguments[$name] ?? $count);
			}
		}
	}


	public function getVisitedNodes(): array
	{
		return [MethodCallNode::class, StaticMethodCallNode::class, NewNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof MethodCallNode && !$node instanceof StaticMethodCallNode && !$node instanceof NewNode) {
			return;
		}

		// the types are asked only about a name the map knows, in a call with arguments enough to reach the flags
		$name = match (true) {
			$node instanceof NewNode => '__construct',
			$node->name instanceof IdentifierNode => strtolower($node->name->text),
			default => null,
		};
		$entries = $name !== null && count($node->arguments->items ?? []) >= ($this->minArguments[$name] ?? 0)
			? $this->byName[$name] ?? []
			: [];
		$arguments = $node->arguments ?? (new Builder)->arguments([]);
		$types = $entries === [] ? null : $context->getAnalysis(Types::class);
		$access = $types?->findMemberAccess($node);
		if ($types === null || $access === null) {
			return;
		}

		$parameters = $types->findParameters($access);
		$entry = MemberMaps::findEntry($entries, $access, $types, $arguments, $parameters);
		if ($entry === null) {
			return;
		}

		[$placeholder, $flags] = $entry->value;
		$flagsArgument = $entry->bindings?->arguments[$placeholder] ?? null;
		if ($flagsArgument instanceof ArgumentNode && $flagsArgument->name === null && !$flagsArgument->value->hasValue()) {
			$this->rewrite($node, $access, $entry->pattern, $flags, $flagsArgument, $arguments, $parameters, $context);
		}
	}


	/**
	 * @param  array<string, array<string, scalar|null>>  $flags
	 * @param  ?list<Parameter>  $parameters  of the method called, which name the arguments standing behind the flags
	 */
	private function rewrite(
		MethodCallNode|StaticMethodCallNode|NewNode $node,
		MemberAccess $access,
		MemberPattern $pattern,
		array $flags,
		ArgumentNode $flagsArgument,
		ArgumentListNode $arguments,
		?array $parameters,
		RuleContext $context,
	): void
	{
		$named = $unknown = [];
		foreach (self::splitFlags($flagsArgument->value) as $operand) {
			$flag = array_find(
				self::resolveFlag($operand, $context->getAnalysis(NameResolver::class), $context->getAnalysis(Types::class)),
				fn(string $flag) => isset($flags[$flag]),
			);
			if ($flag === null) {
				$unknown[] = $operand;
			} else {
				$named = array_replace($named, $flags[$flag]);
			}
		}

		// an argument behind the flags by its position loses it, so it takes the name of its parameter
		$items = $arguments->items->getItems();
		$index = (int) array_search($flagsArgument, $items, strict: true);
		$behind = [];
		foreach (array_slice($items, $index + 1, preserve_keys: true) as $position => $item) {
			if ($item instanceof ArgumentNode && $item->name === null) {
				$behind[] = [$item, $parameters[$position]->name ?? null];
			}
		}

		$names = [...array_keys($named), ...array_column($behind, 1)];
		$refusal = match (true) {
			$unknown !== [] => $unknown[0] instanceof ConstantFetchNode || $unknown[0] instanceof ClassConstantFetchNode
				? ', but no named argument is known for ' . Violation::formatCode($unknown[0]->text)
				: ', but ' . Violation::formatCode($unknown[0]->text) . ' is known only at run time',
			array_any($behind, fn(array $argument) => $argument[0]->ellipsis !== null) => ', but an argument after the flags is unpacked',
			array_any($behind, fn(array $argument) => $argument[1] === null) => ', but an argument after the flags is passed by position, and its parameter has no name to pass it by',
			array_any($items, fn(Node $item) => $item instanceof ArgumentNode && $item !== $flagsArgument && in_array($item->name?->text, $names, true))
			|| count($names) !== count(array_unique($names)) => ', but the call names an argument a flag becomes already',
			default => null,
		};

		$written = [];
		foreach ($named as $name => $value) {
			$written[] = "$name: " . ($value === null || is_bool($value) ? strtolower(var_export($value, true)) : var_export($value, true));
		}

		$code = Violation::formatCode($flagsArgument->value->text);
		$takes = match (true) {
			$unknown !== [] => "takes named arguments instead of $code",
			$written === [] => "needs no argument for $code, which must be left out",
			default => 'takes ' . implode(', ', array_map(Violation::formatCode(...), $written)) . " instead of $code",
		};

		if (
			!$context->report(
				$flagsArgument,
				$pattern->describeAccess($access, $node) . " $takes" . ($refusal ?? '') . '.',
				fixable: $refusal === null,
			)
			|| $refusal !== null
		) {
			return;
		}

		foreach ($behind as [$argument, $name]) {
			$argument->replaceWith((new Builder)->fragment(ArgumentNode::class, $name . ': $value', value: $argument->value));
		}

		$call = (new Builder)->expression('f(' . implode(', ', $written) . ')');
		assert($call instanceof FunctionCallNode);
		$new = array_map(fn(Node $item) => $item->withoutEdgeTrivia(), $call->arguments->items->getItems());
		if ($new === []) {
			$arguments->items->removeItem($flagsArgument);
			return;
		}

		$flagsArgument->replaceWith($new[0]);
		foreach (array_slice($new, 1) as $offset => $argument) {
			$arguments->items->insert($index + 1 + $offset, $argument);
		}
	}


	/**
	 * The operands of the flags joined by `|`, without the parentheses around them and without the zeros.
	 * @return list<ExpressionNode>
	 */
	private static function splitFlags(ExpressionNode $flags): array
	{
		return match (true) {
			$flags instanceof ParenthesizedNode => self::splitFlags($flags->expression),
			$flags instanceof BinaryOpNode && $flags->operator->text === '|' => [...self::splitFlags($flags->left), ...self::splitFlags($flags->right)],
			$flags instanceof IntegerNode && $flags->toValue() === 0 => [],
			default => [$flags],
		};
	}


	/**
	 * The flag the operand names, fully qualified the way the map keys it: a constant of a class by the class that
	 * declares it, `$router::ONE_WAY` and `self::ONE_WAY` alike, and by the class written; none for what is no constant.
	 * @return list<string>
	 */
	private static function resolveFlag(ExpressionNode $operand, NameResolver $resolver, Types $types): array
	{
		if ($operand instanceof ConstantFetchNode) {
			return [$resolver->resolveConstant($operand->name)];
		} elseif (!$operand instanceof ClassConstantFetchNode || !$operand->name instanceof IdentifierNode) {
			return [];
		}

		$classes = [
			$types->findMember($operand)?->declaringClass,
			$operand->class instanceof NameNode && !$operand->class->isSpecialClass() ? $resolver->resolveClass($operand->class) : null,
		];
		return array_values(array_unique(array_map(
			fn(string $class) => strtolower($class) . '::' . $operand->name->text,
			array_filter($classes),
		)));
	}


	/**
	 * The placeholder of the flags, the arguments of each flag keyed by the flag written fully qualified, the class
	 * in lower case, and the count of the arguments up to the flags, which a call passes by position.
	 * @param  array<string, array<string, scalar|null>>  $value
	 * @return array{string, array<string, array<string, scalar|null>>, int}
	 * @throws \InvalidArgumentException
	 */
	private static function readFlags(array $value, MemberPattern $key): array
	{
		$items = $key->arguments->items ?? [];
		if ($key->arguments?->takesRest()) {
			array_pop($items); // the flags may stand before `...`
		}

		$last = $items === [] ? null : $items[count($items) - 1];
		if ($last === null || $last->placeholder === null || $last->variadic || $last->parameterName !== null) {
			throw new \InvalidArgumentException("The call `$key->class::$key->name()` must be given with the placeholder of its flags last, `'$key->class::$key->name(\$value, \$flags)'` or `'$key->class::$key->name(\$value, \$flags, ...)'`.");
		}

		$flags = [];
		foreach ($value as $flag => $arguments) {
			if (!preg_match('~^\\\\?(\w+(?:\\\\\w+)*)(?:::(\w+))?$~D', (string) $flag, $m, PREG_UNMATCHED_AS_NULL)) {
				throw new \InvalidArgumentException("The flag `$flag` of `$key->class::$key->name()` is not written as `Class::NAME` or `NAME`.");
			}

			$flags[$m[2] === null ? $m[1] : strtolower($m[1]) . "::$m[2]"] = $arguments;
		}

		return [$last->placeholder, $flags, count($items)];
	}
}
