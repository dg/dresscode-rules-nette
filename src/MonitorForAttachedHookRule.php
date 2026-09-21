<?php declare(strict_types=1);

namespace DressCode\Nette;

use DressCode\Analyses\{Access, MemberKind, Types};
use DressCode\{Group, NodeRule, RuleContext, RuleInfo, Stage};
use DressCode\Rules\CodeWriter;
use PhpSyntax\Analyses\NameResolver;
use PhpSyntax\{CommentPolicy, Node, Parser, Printer, Token, TokenKind, Trivia, TriviaKind};
use PhpSyntax\Nodes\{ArgumentNode, ExpressionNode, IdentifierNode, NameNode, StatementNode};
use PhpSyntax\Nodes\Expression\{ArrowFunctionNode, ClassConstantFetchNode, ClosureNode, FunctionCallNode, InstanceofNode, MethodCallNode, ParenthesizedNode, StaticMethodCallNode, UnaryOpNode, VariableNode};
use PhpSyntax\Nodes\Member\MethodNode;
use PhpSyntax\Nodes\Statement\{BlockNode, ClassNode, ExpressionStatementNode, FunctionNode, IfNode, ReturnNode};


/**
 * The hooks attached() and detached() of a component, which nette/component-model 2.4 called for every type the
 * constructor monitored and 3.0 stopped calling, written as the callbacks of monitor(). The type a callback is
 * registered for is the one a guard of the hook tests by instanceof, each branch of a chain of them a callback of its
 * own, else the one a one-argument monitor() call names, else the native type of the parameter, else the type the
 * class monitored by itself, the presenter for a UI component and the form for a control or a container of a form.
 * The callback goes into that monitor() call, else to the end of the constructor, which is added where no ancestor
 * declares one. A leading call of the empty parent hook goes, and so does the guard, their comments with them, as
 * does the doc comment of the hook; a hook that does nothing else goes without a callback.
 *
 * What cannot be decided is reported and left: a type tested other than by instanceof or named by no literal, a hook
 * that calls the hook of a parent of the project, which is converted first, a hook calling its parent other than
 * first, a hook nothing names a type for, a class whose parent has a constructor but which declares none, a heredoc,
 * which would not survive being indented anew.
 */
#[RuleInfo(
	'nette/monitor-for-attached-hook',
	Stage::Structure,
	description: 'Registers the logic of an attached() or detached() hook as a callback of monitor()',
	group: Group::Deprecations,
	modifiesComments: true,
	requires: ['nette/component-model' => '>=3.0'],
	requiresTypes: true,
)]
final class MonitorForAttachedHookRule extends NodeRule
{
	/** hook → the position of its callback among the arguments of monitor() */
	private const Hooks = ['attached' => 1, 'detached' => 2];

	/** the types every component is of, which a parameter of the hook declares without saying what it waits for */
	private const GenericTypes = [
		'nette\componentmodel\icomponent',
		'nette\componentmodel\icontainer',
		'nette\componentmodel\component',
		'nette\componentmodel\container',
	];

	private const Component = 'Nette\ComponentModel\Component';


	public function getVisitedTypes(): array
	{
		return [ClassNode::class];
	}


	public function enter(Node|Token $node, RuleContext $context): void
	{
		if (!$node instanceof ClassNode) {
			return;
		}

		$hooks = self::findHooks($node);
		if ($hooks === []) {
			return;
		}

		$types = $context->getAnalysis(Types::class);
		$class = $types->findDeclaringClass(array_values($hooks)[0]);
		if ($class === null || !$types->isSubtype($class, self::Component)) {
			return;
		}

		// everything is decided before anything is written, the two hooks sharing the calls of monitor() and the constructor
		$monitors = self::findMonitorCalls($node, $context);
		$constructor = self::findConstructor($node);
		$plans = [];
		foreach ($hooks as $name => $hook) {
			$plan = is_string($monitors) ? $monitors : self::planHook($hook, $class, $monitors, $types, $context);
			$plan = is_array($plan) ? array_filter($plan, fn(array $callback) => $callback[1] !== []) : $plan; // a hook doing nothing just goes
			if (is_array($plan) && $constructor === null && array_diff_key($plan, is_array($monitors) ? $monitors : []) !== []) {
				$plan = self::findConstructorRefusal($class, $types) ?? $plan;
			}

			$message = "Method $name() is a hook nette/component-model no longer calls; its logic belongs to a callback of monitor()";
			if ($context->report($hook->name, $message . (is_string($plan) ? $plan : ''), fixable: is_array($plan)) && is_array($plan)) {
				$plans[$name] = $plan;
			}
		}

		if ($plans !== []) {
			self::apply($node, $hooks, $plans, is_array($monitors) ? $monitors : [], $constructor, $context);
		}
	}


	/**
	 * The hooks the class declares with a body, by their names.
	 * @return array<string, MethodNode>
	 */
	private static function findHooks(ClassNode $class): array
	{
		$hooks = [];
		foreach ($class->members->getItems() as $member) {
			$name = $member instanceof MethodNode && $member->body !== null ? strtolower($member->name->text) : null;
			if ($name !== null && isset(self::Hooks[$name])) {
				$hooks[$name] = $member;
			}
		}

		return $hooks;
	}


	/**
	 * The one-argument calls of monitor() in the methods of the class, by the lowercased type they name; the reason as
	 * the end of the message where a call names its type by no literal, the hook then being called for what nobody knows.
	 * @return array<string, array{string, MethodCallNode}>|string
	 */
	private static function findMonitorCalls(ClassNode $class, RuleContext $context): array|string
	{
		$resolver = $context->getAnalysis(NameResolver::class);
		$calls = [];
		foreach ($class->members->getItems() as $member) {
			foreach ($member instanceof MethodNode ? $member->find(MethodCallNode::class) : [] as $call) {
				$arguments = $call->arguments->items->getItems();
				if (
					!$call->object instanceof VariableNode
					|| $call->object->plainName !== 'this'
					|| !$call->name instanceof IdentifierNode
					|| strcasecmp($call->name->text, 'monitor') !== 0
					|| count($arguments) !== 1
				) {
					continue;
				}

				$value = $arguments[0] instanceof ArgumentNode && $arguments[0]->name === null && $arguments[0]->ellipsis === null ? $arguments[0]->value : null;
				$type = match (true) {
					$value instanceof ClassConstantFetchNode && $value->class instanceof NameNode && $value->name instanceof IdentifierNode
					&& strcasecmp($value->name->text, 'class') === 0 => $resolver->resolveClass($value->class),
					$value !== null && $value->hasValue() && is_string($value->toValue()) => ltrim($value->toValue(), '\\'),
					default => null,
				};
				if ($type === null) {
					return ', but monitor() is called with a type that is not written out';
				}

				$calls[strtolower($type)] ??= [$type, $call];
			}
		}

		return $calls;
	}


	/**
	 * The callbacks the hook becomes, by the type each is registered for, with the statements of its body; the reason
	 * as the end of the message where the body cannot be read.
	 * @param  array<string, array{string, MethodCallNode}>  $monitors
	 * @return array<string, array{string, list<StatementNode>}>|string
	 */
	private static function planHook(MethodNode $hook, string $class, array $monitors, Types $types, RuleContext $context): array|string
	{
		assert($hook->body !== null);
		$name = strtolower($hook->name->text);
		$parameter = $hook->parameters->getItems()[0]->variable->plainName ?? null;
		$statements = $hook->body->statements->getItems();
		if (array_any($hook->body->getTokens(), fn(Token $token) => $token->is(TokenKind::StartHeredoc))) {
			return ', but its body holds a heredoc, which would not survive being indented anew';
		}

		// the hook of Component is empty, so a call of it goes; the hook of a parent of the project is converted first
		if ($statements !== [] && self::isParentCall($statements[0], $name)) {
			$overridden = $types->findOverridden($hook)?->declaringClass;
			if ($overridden !== null && strcasecmp($overridden, self::Component) !== 0) {
				return ", but it calls the hook of $overridden, which has to become a callback first";
			}

			array_shift($statements);
		}

		if (array_any($statements, fn(StatementNode $statement) => array_any(
			$statement->find(StaticMethodCallNode::class),
			fn(StaticMethodCallNode $call) => self::isParentCall($call, $name),
		))) {
			return ", but it calls parent::$name() other than as its first statement";
		} elseif ($parameter !== null && self::testsTypeOtherwise($statements, $parameter)) {
			return ', but it tests the type of the object without instanceof';
		}

		$guarded = $parameter === null ? null : self::splitGuards($statements, $parameter, $context);
		if ($guarded !== null) {
			return $guarded;
		} elseif ($monitors !== []) {
			return array_map(fn(array $monitor) => [$monitor[0], $statements], $monitors);
		}

		$type = self::findParameterType($hook, $context) ?? self::findDefaultType($class, $types);
		return $type === null
			? ', but nothing says which type it is called for'
			: [strtolower($type) => [$type, $statements]];
	}


	/** Whether the node is parent::name() or the statement holding it alone. */
	private static function isParentCall(Node $node, string $name): bool
	{
		$call = $node instanceof ExpressionStatementNode ? $node->expression : $node;
		return $call instanceof StaticMethodCallNode
			&& $call->class instanceof NameNode
			&& strcasecmp($call->class->text, 'parent') === 0
			&& $call->name instanceof IdentifierNode
			&& strcasecmp($call->name->text, $name) === 0;
	}


	/**
	 * Whether a condition of an if among the statements asks for the type of the parameter by anything but instanceof.
	 * @param  list<StatementNode>  $statements
	 */
	private static function testsTypeOtherwise(array $statements, string $parameter): bool
	{
		foreach ($statements as $statement) {
			$conditions = $statement instanceof IfNode
				? [$statement->condition, ...array_map(fn(Node $elseif) => $elseif->condition, $statement->elseifs->getItems())]
				: [];
			foreach ($conditions as $condition) {
				$mentions = array_any($condition->find(VariableNode::class), fn(VariableNode $variable) => $variable->plainName === $parameter);
				$tests = array_any(
					$condition->find(FunctionCallNode::class),
					fn(FunctionCallNode $call) => $call->name instanceof NameNode
						&& in_array(strtolower(ltrim($call->name->text, '\\')), ['is_a', 'is_subclass_of', 'get_class', 'get_debug_type', 'get_parent_class'], true),
				) || array_any(
					$condition->find(ClassConstantFetchNode::class),
					fn(ClassConstantFetchNode $fetch) => $fetch->name instanceof IdentifierNode && strcasecmp($fetch->name->text, 'class') === 0,
				);
				if ($mentions && $tests) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * The callbacks the guards of the body make: `if (!$obj instanceof T) { return; }` leading the body, or the body
	 * being one if with an elseif per type, each branch a callback; null where the body has no guard.
	 * @param  list<StatementNode>  $statements
	 * @return ?array<string, array{string, list<StatementNode>}>
	 */
	private static function splitGuards(array $statements, string $parameter, RuleContext $context): ?array
	{
		$first = $statements[0] ?? null;
		if (!$first instanceof IfNode || $first->body === null || $first->else !== null) {
			return null;
		}

		$negated = self::unwrap($first->condition);
		if ($negated instanceof UnaryOpNode && $negated->operator->text === '!' && $first->elseifs->getItems() === []) {
			$type = self::findTestedType($negated->expression, $parameter, $context);
			$body = $first->body instanceof BlockNode ? $first->body->statements->getItems() : [$first->body];
			$returns = count($body) === 1 && $body[0] instanceof ReturnNode && $body[0]->expression === null;
			return $type !== null && $returns ? [strtolower($type) => [$type, array_slice($statements, 1)]] : null;
		} elseif (count($statements) !== 1) {
			return null;
		}

		$callbacks = [];
		foreach ([$first, ...$first->elseifs->getItems()] as $branch) {
			$type = self::findTestedType($branch->condition, $parameter, $context);
			if ($type === null || $branch->body === null || isset($callbacks[strtolower($type)])) {
				return null;
			}

			$callbacks[strtolower($type)] = [$type, $branch->body instanceof BlockNode ? $branch->body->statements->getItems() : [$branch->body]];
		}

		return $callbacks;
	}


	/** The class `$parameter instanceof Class` tests; null for any other expression. */
	private static function findTestedType(ExpressionNode $condition, string $parameter, RuleContext $context): ?string
	{
		$condition = self::unwrap($condition);
		return $condition instanceof InstanceofNode
			&& $condition->expression instanceof VariableNode
			&& $condition->expression->plainName === $parameter
			&& $condition->class instanceof NameNode
			&& !$condition->class->isSpecialClass()
			? $context->getAnalysis(NameResolver::class)->resolveClass($condition->class)
			: null;
	}


	private static function unwrap(ExpressionNode $expression): ExpressionNode
	{
		while ($expression instanceof ParenthesizedNode) {
			$expression = $expression->expression;
		}

		return $expression;
	}


	/** The class the parameter of the hook declares, unless it is one every component is of; null for none. */
	private static function findParameterType(MethodNode $hook, RuleContext $context): ?string
	{
		$names = $hook->parameters->getItems()[0]->type?->find(NameNode::class) ?? [];
		$type = count($names) === 1 && !$names[0]->isSpecialClass()
			? $context->getAnalysis(NameResolver::class)->resolveClass($names[0])
			: null;
		return $type === null || in_array(strtolower($type), self::GenericTypes, true) ? null : $type;
	}


	/** The type the class of nette/application or nette/forms monitored by itself: the presenter, or the form. */
	private static function findDefaultType(string $class, Types $types): ?string
	{
		return match (true) {
			$types->isSubtype($class, 'Nette\Application\UI\Component') => 'Nette\Application\UI\Presenter',
			$types->isSubtype($class, 'Nette\Forms\Controls\BaseControl'),
			$types->isSubtype($class, 'Nette\Forms\Container') && !$types->isSubtype($class, 'Nette\Forms\Form') => 'Nette\Forms\Form',
			default => null,
		};
	}


	private static function findConstructor(ClassNode $class): ?MethodNode
	{
		foreach ($class->members->getItems() as $member) {
			if ($member instanceof MethodNode && $member->isConstructor()) {
				return $member->body === null ? null : $member;
			}
		}

		return null;
	}


	/** Why no constructor can be added to the class: an ancestor declares one, which the added one would stop passing its arguments to. */
	private static function findConstructorRefusal(string $class, Types $types): ?string
	{
		return $types->findParameters(new Access(MemberKind::Constructor, '__construct', [$class], true)) === null
			? null
			: ', but the class declares no constructor, and one added would stop passing the arguments to that of its parent';
	}


	/**
	 * Writes the callbacks: into the call of monitor() of their type, else to the end of the constructor, which is added
	 * before the first method where the class has none, and removes the hooks.
	 * @param  array<string, MethodNode>  $hooks
	 * @param  array<string, array<string, array{string, list<StatementNode>}>>  $plans  hook → type → the type and the body of its callback
	 * @param  array<string, array{string, MethodCallNode}>  $monitors
	 */
	private static function apply(
		ClassNode $class,
		array $hooks,
		array $plans,
		array $monitors,
		?MethodNode $constructor,
		RuleContext $context,
	): void
	{
		$style = $context->getStyle();
		$callbacks = [];
		foreach ($plans as $name => $plan) {
			foreach ($plan as $key => [$type, $statements]) {
				$callbacks[$key][0] = $type;
				$callbacks[$key][self::Hooks[$name]] = [$hooks[$name], $statements];
			}
		}

		$added = [];
		foreach ($callbacks as $key => $callback) {
			$monitor = $monitors[$key][1] ?? null;
			$statement = $monitor?->parent;
			if ($statement instanceof ExpressionStatementNode) {
				$indentation = $statement->getFirstToken()?->getIndentation() ?? '';
				$argument = $monitor->arguments->items->getItems()[0]->text;
				$statement->replaceWith((new Parser)->parseStatement(self::buildMonitor($argument, $callback, $indentation, $class, $context)));
			} else {
				$added[] = $callback;
			}
		}

		if ($added !== []) {
			$indentation = ($constructor ?? array_values($hooks)[0])->getFirstToken()?->getIndentation() ?? '';
			$texts = array_map(
				fn(array $callback) => self::buildMonitor(CodeWriter::spellClass($callback[0], $class, $context) . '::class', $callback, $indentation . $style->indent, $class, $context),
				$added,
			);
			$constructor === null
				? self::addConstructor($class, $texts, $indentation, $context)
				: self::appendStatements($constructor, $texts, $indentation . $style->indent, $context);
		}

		foreach ($plans as $name => $plan) {
			CodeWriter::removeBetweenGaps($hooks[$name], $style->eol, CommentPolicy::Drop); // the statements are written anew with their comments
		}
	}


	/**
	 * The statement `$this->monitor(Type::class, attached, detached);` with the callbacks written as closures.
	 * @param  array{0: string, 1?: array{MethodNode, list<StatementNode>}, 2?: array{MethodNode, list<StatementNode>}}  $callback
	 */
	private static function buildMonitor(string $type, array $callback, string $indentation, ClassNode $class, RuleContext $context): string
	{
		$arguments = [$type];
		foreach ([1, 2] as $position) {
			if (isset($callback[$position])) {
				[$hook, $statements] = $callback[$position];
				$arguments[1] ??= 'null';
				$arguments[$position] = self::buildClosure($callback[0], $hook, $statements, $indentation, $class, $context);
			}
		}

		return '$this->monitor(' . implode(', ', $arguments) . ');';
	}


	/** @param  list<StatementNode>  $statements */
	private static function buildClosure(
		string $type,
		MethodNode $hook,
		array $statements,
		string $indentation,
		ClassNode $class,
		RuleContext $context,
	): string
	{
		$style = $context->getStyle();
		$parameter = $hook->parameters->getItems()[0]->variable->plainName ?? null;
		$head = 'function (' . ($parameter === null ? '' : CodeWriter::spellClass($type, $class, $context) . " \$$parameter") . ')'
			. (self::returnsValue($hook, $statements) ? '' : ': void') . ' {';
		if ($statements === []) {
			return "$head}";
		}

		// the statements are written anew one level deeper than the call of monitor(), comments and blank lines kept
		$from = $statements[0]->getFirstToken()?->getIndentation() ?? '';
		$lines = explode("\n", str_replace("\r\n", "\n", implode('', array_map(fn(StatementNode $statement) => Printer::print($statement), $statements))));
		while ($lines !== [] && trim($lines[0]) === '') {
			array_shift($lines);
		}

		while ($lines !== [] && trim(end($lines)) === '') {
			array_pop($lines);
		}

		$lines = array_map(
			fn(string $line) => rtrim($line) === '' ? '' : $indentation . $style->indent . (str_starts_with($line, $from) ? substr($line, strlen($from)) : ltrim($line)),
			$lines,
		);
		return $head . $style->eol . implode($style->eol, $lines) . $style->eol . $indentation . '}';
	}


	/**
	 * Whether the body returns a value, which a closure declared void cannot.
	 * @param  list<StatementNode>  $statements
	 */
	private static function returnsValue(MethodNode $hook, array $statements): bool
	{
		foreach ($statements as $statement) {
			foreach ($statement->find(ReturnNode::class) as $return) {
				$owner = $return->parent;
				while (
					$owner !== null
					&& $owner !== $hook
					&& !$owner instanceof ClosureNode
					&& !$owner instanceof ArrowFunctionNode
					&& !$owner instanceof FunctionNode
					&& !$owner instanceof MethodNode
				) {
					$owner = $owner->parent;
				}

				if ($owner === $hook && $return->expression !== null) {
					return true;
				}
			}
		}

		return false;
	}


	/**
	 * Appends the statements to the end of the body of the constructor.
	 * @param  list<string>  $texts
	 */
	private static function appendStatements(MethodNode $constructor, array $texts, string $indentation, RuleContext $context): void
	{
		$style = $context->getStyle();
		$body = $constructor->body;
		assert($body !== null);
		$trailing = $body->openBrace->trailingTrivia;
		if ($trailing === [] || !$trailing[count($trailing) - 1]->isEndOfLine()) {
			$body->openBrace->setTrailingTrivia([new Trivia(TriviaKind::EndOfLine, $style->eol)]);
			$body->closeBrace->setLeadingTrivia([new Trivia(TriviaKind::Whitespace, substr($indentation, 0, -strlen($style->indent)))]);
		}

		foreach ($texts as $text) {
			$statement = (new Parser)->parseStatement($text);
			$statement->setEdgeTrivia([new Trivia(TriviaKind::Whitespace, $indentation)], [new Trivia(TriviaKind::EndOfLine, $style->eol)]);
			$body->statements->append($statement);
		}
	}


	/**
	 * Adds a constructor holding the statements before the first method of the class, with the gap that method had.
	 * @param  list<string>  $texts
	 */
	private static function addConstructor(ClassNode $class, array $texts, string $indentation, RuleContext $context): void
	{
		$style = $context->getStyle();
		$inner = $indentation . $style->indent;
		$text = 'class Template' . $style->eol . '{' . $style->eol
			. $indentation . 'public function __construct()' . $style->eol
			. $indentation . '{' . $style->eol
			. implode('', array_map(fn(string $statement) => $inner . $statement . $style->eol, $texts))
			. $indentation . '}' . $style->eol
			. '}';
		$template = (new Parser)->parseStatement($text);
		assert($template instanceof ClassNode);
		$constructor = $template->members->getItems()[0]->withoutEdgeTrivia();

		$members = $class->members->getItems();
		$first = array_find($members, fn(Node $member) => $member instanceof MethodNode);
		assert($first !== null);
		$gap = [];
		foreach ($first->getFirstToken()->leadingTrivia ?? [] as $trivia) {
			if ($trivia->kind !== TriviaKind::EndOfLine && $trivia->kind !== TriviaKind::Whitespace) {
				break;
			}

			$gap[] = $trivia;
		}

		$constructor->setEdgeTrivia($gap, [new Trivia(TriviaKind::EndOfLine, $style->eol)]);
		$class->members->insert($class->members->indexOf($first), $constructor);
	}
}
