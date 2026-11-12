<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use InvalidArgumentException;

/**
 * A script command registered by the Engine or a project: its type, the
 * handler that runs it and the fields authors give it.
 *
 * Definitions are plain data, so authoring tools read and validate them
 * without loading the handler class.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final readonly class ScriptCommandDefinition
{
  /** Lower-case words joined by underscores, like the built-in commands. */
  public const string TYPE_PATTERN = '/\A[a-z][a-z0-9]*(_[a-z0-9]+)*\z/';

  /**
   * @param string $type The command type authors write.
   * @param class-string<ScriptCommandHandlerInterface> $handlerClass The handler that runs it.
   * @param string $label The name authors see.
   * @param list<ScriptCommandField> $fields The authored fields.
   * @param string $description What the command does, for authors.
   * @param list<EventExecutionScope> $scopes Where scripts may use the command; field scripts when not declared.
   */
  public function __construct(
    public string $type,
    public string $handlerClass,
    public string $label,
    public array $fields = [],
    public string $description = '',
    public array $scopes = [EventExecutionScope::FIELD],
  )
  {
  }

  /** Whether scripts running in the scope may use this command. */
  public function runsIn(EventExecutionScope $scope): bool
  {
    return in_array($scope, $this->scopes, true);
  }

  /**
   * Reads a command declaration.
   *
   * @param mixed $declaration The declared command.
   * @param string $where Where the declaration is, for messages.
   * @throws InvalidArgumentException When the declaration is malformed.
   */
  public static function fromArray(mixed $declaration, string $where): self
  {
    if (! is_array($declaration) || array_is_list($declaration)) {
      throw new InvalidArgumentException("{$where} must be a keyed array.");
    }

    $type = trim(strval($declaration['type'] ?? ''));

    if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
      throw new InvalidArgumentException("{$where} needs a type of lower-case words joined by underscores, such as \"hire_carriage\".");
    }

    $handlerClass = ltrim(trim(strval($declaration['class'] ?? '')), '\\');

    if ($handlerClass === '') {
      throw new InvalidArgumentException("{$where} ({$type}) needs the class of its handler.");
    }

    $label = trim(strval($declaration['label'] ?? ''));

    if ($label === '') {
      throw new InvalidArgumentException("{$where} ({$type}) needs a label.");
    }

    $scopes = $declaration['scopes'] ?? [EventExecutionScope::FIELD->value];

    if (! is_array($scopes) || $scopes === [] || ! array_is_list($scopes)) {
      throw new InvalidArgumentException("{$where} ({$type}) scopes must list where the command runs: field, battle or both.");
    }

    $scopes = array_map(static fn(mixed $scope): EventExecutionScope => (is_string($scope)
      ? EventExecutionScope::tryFrom(trim($scope)) : null)
      ?? throw new InvalidArgumentException(sprintf('%s (%s) has unknown scope "%s"; use field or battle.',
        $where, $type, is_scalar($scope) ? strval($scope) : get_debug_type($scope))), $scopes);

    return new self(
      $type,
      $handlerClass,
      $label,
      ScriptCommandField::readFields($declaration['fields'] ?? null, "{$where} ({$type}) fields"),
      trim(strval($declaration['description'] ?? '')),
      array_map(EventExecutionScope::from(...), array_values(array_unique(array_map(
        static fn(EventExecutionScope $scope): string => $scope->value, $scopes)))),
    );
  }

  /**
   * Finds what is wrong with an authored command of this type.
   *
   * @param array<string, mixed> $command The authored command.
   * @return list<string> The problems; empty when the command is acceptable.
   */
  public function findProblems(array $command): array
  {
    $problems = [];

    foreach ($this->fields as $field) {
      $problems = [...$problems, ...$field->findProblems($command)];
    }

    return $problems;
  }
}
