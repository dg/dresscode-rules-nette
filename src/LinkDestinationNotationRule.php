<?php declare(strict_types=1);

namespace DressCode\Nette;

use DressCode\Analyses\Types;
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use PhpSyntax\{Node, Parser, Token};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, IdentifierNode};
use PhpSyntax\Nodes\Expression\{ArrayAccessNode, ArrayNode, BinaryOpNode, ClassConstantFetchNode, ConstantFetchNode, FunctionCallNode, MethodCallNode, PropertyFetchNode, StaticMethodCallNode, StaticPropertyFetchNode, VariableNode};
use PhpSyntax\Nodes\Scalar\{InterpolatedStringNode, InterpolatedStringPartNode, InterpolationNode};


/**
 * The query string and the fragment of the destination of a link, `link('Product:show?id=1#reviews')`, passed as its
 * arguments, `link('Product:show', ['id' => '1', '#' => 'reviews'])`, which nette/application 3.3 takes and which
 * deprecated the query string. The destination is a string, a concatenation or an interpolated string whose ? or #
 * stands in a literal part; the query has to be literal, the values it gives are strings as parse_str() makes them,
 * and the fragment may go on with an expression. The items join a literal array of arguments, the positional
 * arguments are wrapped into an array with them, and an array held by an expression is joined with +, the query on
 * the left, as it took precedence.
 *
 * The fix is risky where the call has arguments besides the query, which the query used to replace, and where the
 * fragment is not a literal name, which the argument '#' encodes and the destination did not. A query that is not
 * written out, one holding an array, an empty fragment, a comment among the arguments, arguments passed by name or
 * unpacked, and an argument the types do not tell as an array or not are reported and left.
 */
#[RuleInfo(
	'nette/link-destination-notation',
	Stage::Structure,
	description: 'Passes the query string and the fragment of a link destination as arguments',
	group: Group::Deprecations,
	requires: ['nette/application' => '>=3.3'],
	requiresTypes: true,
)]
final class LinkDestinationNotationRule extends NodeRule
{
	/** method → the classes whose method takes a destination first and its arguments second */
	private const Methods = [
		'link' => ['Nette\Application\UI\Component', 'Nette\Application\LinkGenerator'],
		'lazylink' => ['Nette\Application\UI\Component'],
		'redirect' => ['Nette\Application\UI\Component'],
		'redirectpermanent' => ['Nette\Application\UI\Component'],
		'islinkcurrent' => ['Nette\Application\UI\Component'],
		'forward' => ['Nette\Application\UI\Presenter'],
		'canonicalize' => ['Nette\Application\UI\Presenter'],
	];

	private const Generator = 'Nette\Application\LinkGenerator';


	public function getVisitedTypes(): array
	{
		return [MethodCallNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		$method = $node instanceof MethodCallNode && $node->name instanceof IdentifierNode ? strtolower($node->name->text) : null;
		$arguments = $method !== null && isset(self::Methods[$method]) ? $node->arguments->items->getItems() : [];
		$destination = $arguments[0] ?? null;
		$parts = $destination instanceof ArgumentNode && $destination->name === null && $destination->ellipsis === null
			? self::collectParts($destination->value)
			: null;
		$split = $parts === null ? null : self::split($parts);
		if ($split === null) {
			return; // no ? or # in a literal part
		}

		assert($node instanceof MethodCallNode);
		$types = $context->getAnalysis(Types::class);
		$classes = $types->findAccess($node)->classes ?? [];
		$owner = array_find(self::Methods[(string) $method], fn(string $owner) => $classes !== [] && array_all($classes, fn(string $class) => $types->isSubtype($class, $owner)));
		if ($owner === null) {
			return;
		}

		[$path, $query, $fragment, $refusal] = $split;
		$message = match (true) {
			$query !== null && $fragment !== null => 'The query string and the fragment of the link destination must be passed as arguments',
			$query !== null => 'The query string of the link destination must be passed as arguments',
			default => "The fragment of the link destination must be passed as the '#' argument",
		};
		$rest = array_slice($arguments, 1);
		$items = $refusal === null ? self::buildItems($query, $fragment) : null;
		$refusal ??= is_string($items) ? $items : null;
		$refusal ??= match (true) {
			array_any($rest, fn(Node $argument) => !$argument instanceof ArgumentNode || $argument->name !== null || $argument->ellipsis !== null) => ', but its arguments are passed by name or unpacked',
			$node->arguments->hasComment() => ', but a comment stands among its arguments',
			default => null,
		};
		$shape = $refusal === null ? self::findShape($rest, $owner === self::Generator, $types) : null;
		$refusal ??= is_string($shape) ? $shape : null;
		$risk = match (true) {
			$refusal !== null => null,
			$query !== null && $rest !== [] => ', which may pass arguments the query string used to override',
			$fragment !== null && !(count($fragment) === 1 && $fragment[0][0] === 'literal' && preg_match('~^[\w-]*$~D', $fragment[0][1])) => ', which encodes the fragment the destination wrote as it is',
			default => null,
		};
		if (
			$context->report($destination, $message . ($refusal ?? $risk ?? ''), fixable: $refusal === null, risky: $risk !== null)
			&& is_array($items)
			&& is_array($shape)
		) {
			$destination->value->replaceWithExpression((new Parser)->parseExpression(self::write($path)));
			self::mergeArguments($node, $rest, $items, $shape[0]);
		}
	}


	/**
	 * The destination as a list of its literal parts and its expressions, in order; null for an expression that is no
	 * string, no concatenation and no interpolated string.
	 * @return ?list<array{'literal'|'expression', string}>
	 */
	private static function collectParts(ExpressionNode $expression): ?array
	{
		if ($expression instanceof BinaryOpNode && $expression->operator->text === '.') {
			$left = self::collectParts($expression->left);
			return [
				...$left ?? [['expression', self::wrap($expression->left)]],
				...self::collectParts($expression->right) ?? [['expression', self::wrap($expression->right)]],
			];

		} elseif ($expression instanceof InterpolatedStringNode) {
			$parts = [];
			foreach ($expression->parts->getItems() as $part) {
				$parts[] = match (true) {
					$part instanceof InterpolatedStringPartNode => str_contains($part->token->text, '\\') ? ['escaped', $part->token->text] : ['literal', $part->token->text],
					$part instanceof InterpolationNode => ['expression', self::wrap($part->expression)],
					default => ['expression', self::wrap($part)],
				};
			}

			// what an escape sequence means is the string's business, so a part holding one is an expression of its own
			return array_map(fn(array $part) => $part[0] === 'escaped' ? ['expression', "\"$part[1]\""] : $part, $parts);
		}

		return $expression->hasValue() && is_string($expression->toValue()) ? [['literal', $expression->toValue()]] : null;
	}


	/** The text of an expression as a part of a concatenation, in parentheses unless it binds tighter than any operator. */
	private static function wrap(ExpressionNode $expression): string
	{
		$tight = $expression instanceof VariableNode
			|| $expression instanceof PropertyFetchNode
			|| $expression instanceof StaticPropertyFetchNode
			|| $expression instanceof ArrayAccessNode
			|| $expression instanceof MethodCallNode
			|| $expression instanceof StaticMethodCallNode
			|| $expression instanceof FunctionCallNode
			|| $expression instanceof ClassConstantFetchNode
			|| $expression instanceof ConstantFetchNode
			|| $expression->hasValue();
		return $tight ? $expression->text : "($expression->text)";
	}


	/**
	 * The destination split at the first ? or # of a literal part: the path, the query, the fragment, and why it cannot
	 * be written apart; null where no literal part has either.
	 * @param  list<array{'literal'|'expression', string}>  $parts
	 * @return ?array{list<array{'literal'|'expression', string}>, ?string, ?list<array{'literal'|'expression', string}>, ?string}
	 */
	private static function split(array $parts): ?array
	{
		foreach ($parts as $index => [$kind, $text]) {
			$position = $kind === 'literal' ? strcspn($text, '?#') : strlen($text);
			if ($position === strlen($text)) {
				continue;
			}

			$path = [...array_slice($parts, 0, $index), ['literal', substr($text, 0, $position)]];
			$after = substr($text, $position + 1);
			$query = null;
			if ($text[$position] === '?') {
				$end = strcspn($after, '#');
				$query = substr($after, 0, $end);
				if ($end === strlen($after)) {
					$rest = array_slice($parts, $index + 1);
					return [$path, $query, null, $rest === [] ? null : ', but the query string is not written out whole'];
				}

				$after = substr($after, $end + 1);
			}

			$fragment = array_values(array_filter([['literal', $after], ...array_slice($parts, $index + 1)], fn(array $part) => $part[1] !== ''));
			return [$path, $query, $fragment, $fragment === [] ? ', but its fragment is empty' : null];
		}

		return null;
	}


	/**
	 * The items the query and the fragment add to the arguments, as the text of array items; the reason as the end of
	 * the message where the query holds an array.
	 * @param  ?list<array{'literal'|'expression', string}>  $fragment
	 * @return list<string>|string
	 */
	private static function buildItems(?string $query, ?array $fragment): array|string
	{
		$items = [];
		parse_str($query ?? '', $values);
		foreach ($values as $key => $value) {
			if (!is_string($value)) {
				return ', but its query string holds an array';
			}

			$items[] = self::quote((string) $key) . ' => ' . self::quote($value);
		}

		if ($fragment !== null) {
			$items[] = "'#' => " . self::write($fragment);
		}

		return $items;
	}


	/**
	 * Where the items go: into the literal array, joined with the array an expression holds, or wrapped with the
	 * positional arguments into a new array, which is also where they go without any; the reason as the end of the
	 * message where it is not known whether the argument is an array.
	 * @param  list<Node>  $rest  the arguments after the destination
	 * @return array{'array'|'join'|'wrap'}|string
	 */
	private static function findShape(array $rest, bool $generator, Types $types): array|string
	{
		$first = $rest[0] ?? null;
		assert($first === null || $first instanceof ArgumentNode);
		if ($first === null) {
			return ['wrap'];
		} elseif ($first->value instanceof ArrayNode) {
			return count($rest) === 1 || $generator ? ['array'] : ['wrap'];
		} elseif ($generator) {
			return ['join'];
		} elseif (count($rest) > 1) {
			return ['wrap'];
		}

		$array = $types->getType($first->value)?->isArray();
		return match (true) {
			$array?->yes() === true => ['join'],
			$array?->no() === true => ['wrap'],
			default => ', but it is not known whether its argument is an array',
		};
	}


	/**
	 * @param  list<Node>  $rest
	 * @param  list<string>  $items
	 */
	private static function mergeArguments(MethodCallNode $call, array $rest, array $items, string $shape): void
	{
		$parser = new Parser;
		$first = $rest[0] ?? null;
		if ($shape === 'array') {
			assert($first instanceof ArgumentNode && $first->value instanceof ArrayNode);
			$template = $parser->parseExpression('[' . implode(', ', $items) . ']');
			assert($template instanceof ArrayNode);
			foreach ($template->items->getItems() as $item) {
				$first->value->items->insert(count($first->value->items->getItems()), $item->withoutEdgeTrivia());
			}

		} elseif ($shape === 'join') {
			assert($first instanceof ArgumentNode);
			$first->value->replaceWithExpression($parser->parseExpression('[' . implode(', ', $items) . '] + ' . self::wrap($first->value)));

		} else {
			$values = array_map(fn(Node $argument) => $argument instanceof ArgumentNode ? $argument->value->text : '', $rest);
			$template = $parser->parseExpression('f(0, [' . implode(', ', [...$values, ...$items]) . '])');
			assert($template instanceof FunctionCallNode);
			$argument = $template->arguments->items->getItems()[1]->withoutEdgeTrivia();
			foreach ($rest as $old) {
				$call->arguments->items->removeItem($old);
			}

			$call->arguments->items->insert(1, $argument);
		}
	}


	/** @param  list<array{'literal'|'expression', string}>  $parts */
	private static function write(array $parts): string
	{
		$parts = array_values(array_filter($parts, fn(array $part) => $part[1] !== ''));
		return $parts === []
			? "''"
			: implode(' . ', array_map(fn(array $part) => $part[0] === 'literal' ? self::quote($part[1]) : $part[1], $parts));
	}


	private static function quote(string $value): string
	{
		return "'" . addcslashes($value, "'\\") . "'";
	}
}
