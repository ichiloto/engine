<?php

namespace Ichiloto\Engine\Events\Interpreter\Commands;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The script commands registered beside the interpreter's built-in
 * vocabulary: the Engine's own and those a project declares.
 *
 * Built-in commands (CinematicCommandSchema::COMMAND_TYPES) stay in the
 * interpreter; registered commands may not reuse their types or each other's.
 *
 * @package Ichiloto\Engine\Events\Interpreter\Commands
 */
final class ScriptCommandCatalog
{
  /** @var list<string> The registered types, Engine commands first. */
  public array $types {
    get => array_keys($this->definitions);
  }

  /**
   * @param array<string, ScriptCommandDefinition> $definitions The definitions by type.
   */
  private function __construct(
    public readonly array $definitions,
  )
  {
  }

  /** The catalogue of a project that declares no commands of its own. */
  public static function createEngineCatalog(): self
  {
    return self::fromDeclarations([], 'the Engine');
  }

  /**
   * Builds the catalogue from a project's declared commands.
   *
   * @param mixed $declarations The project's list of command declarations.
   * @param string $source Where the declarations came from, for messages.
   * @throws InvalidArgumentException When a declaration is malformed or its type is taken.
   */
  public static function fromDeclarations(mixed $declarations, string $source): self
  {
    $definitions = [];

    foreach (EngineScriptCommands::DECLARATIONS as $index => $declaration) {
      $definition = ScriptCommandDefinition::fromArray($declaration, sprintf('Engine script command %d', $index + 1));
      $definitions[$definition->type] = $definition;
    }

    if (! is_array($declarations) || ! array_is_list($declarations)) {
      throw new InvalidArgumentException("{$source} must return a list of script command declarations.");
    }

    foreach ($declarations as $index => $declaration) {
      $definition = ScriptCommandDefinition::fromArray($declaration, sprintf('%s command %d', $source, $index + 1));

      if (in_array($definition->type, CinematicCommandSchema::COMMAND_TYPES, true)) {
        throw new InvalidArgumentException(sprintf('%s command %d uses "%s", a built-in command type.', $source, $index + 1, $definition->type));
      }

      if (isset($definitions[$definition->type])) {
        throw new InvalidArgumentException(sprintf('%s command %d uses "%s", which is already registered.', $source, $index + 1, $definition->type));
      }

      $definitions[$definition->type] = $definition;
    }

    return new self($definitions);
  }

  /** Finds the definition registered for a type. */
  public function findDefinition(string $type): ?ScriptCommandDefinition
  {
    return $this->definitions[$type] ?? null;
  }

  /** Whether a type is registered. */
  public function isRegistered(string $type): bool
  {
    return isset($this->definitions[$type]);
  }

  /**
   * Creates the handler for a registered type.
   *
   * @throws RuntimeException When the type is not registered or its handler cannot be created.
   */
  public function createHandler(string $type): ScriptCommandHandlerInterface
  {
    $definition = $this->findDefinition($type)
      ?? throw new RuntimeException(sprintf('No script command "%s" is registered.', $type));
    $class = $definition->handlerClass;

    if (! class_exists($class)) {
      throw new RuntimeException(sprintf('Script command "%s" names handler %s, which does not exist.', $type, $class));
    }

    if (! is_subclass_of($class, ScriptCommandHandlerInterface::class)) {
      throw new RuntimeException(sprintf(
        'Script command "%s" names handler %s, which does not implement %s.',
        $type,
        $class,
        ScriptCommandHandlerInterface::class,
      ));
    }

    try {
      return new $class();
    } catch (Throwable $throwable) {
      throw new RuntimeException(sprintf('Script command "%s" handler %s could not be created: %s', $type, $class, $throwable->getMessage()), previous: $throwable);
    }
  }

  /**
   * Confirms every handler exists and implements the contract, so a
   * misdeclared command fails when the game starts rather than mid-script.
   *
   * @throws RuntimeException When a handler cannot be used.
   */
  public function assertHandlersLoadable(): void
  {
    foreach ($this->definitions as $type => $definition) {
      $class = $definition->handlerClass;

      if (! class_exists($class) || ! is_subclass_of($class, ScriptCommandHandlerInterface::class)) {
        throw new RuntimeException(sprintf(
          'Script command "%s" names handler %s, which must exist and implement %s.',
          $type,
          $class,
          ScriptCommandHandlerInterface::class,
        ));
      }
    }
  }
}
