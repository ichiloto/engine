<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

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
   */
  public function __construct(
    public string $type,
    public string $handlerClass,
    public string $label,
    public array $fields = [],
    public string $description = '',
  )
  {
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

    return new self(
      $type,
      $handlerClass,
      $label,
      ScriptCommandField::readFields($declaration['fields'] ?? null, "{$where} ({$type}) fields"),
      trim(strval($declaration['description'] ?? '')),
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
