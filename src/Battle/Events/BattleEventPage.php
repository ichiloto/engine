<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Events;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandField;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;
use InvalidArgumentException;

/**
 * One troop event page: when it runs and the event commands it runs over the
 * paused battle. Troops author pages under `events`:
 *
 * ```php
 * 'events' => [[
 *   'name' => 'Concession',
 *   'span' => 'battle',
 *   'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25],
 *   'commands' => [['type' => 'text', 'name' => 'Djin', 'text' => '…'], ['type' => 'concede', 'enemy' => 0]],
 * ]]
 * ```
 *
 * @package Ichiloto\Engine\Battle\Events
 */
final readonly class BattleEventPage
{
  public const array KEYS = ['name', 'span', 'conditions', 'commands'];

  /**
   * @param list<array<string, mixed>> $commands The event commands.
   */
  public function __construct(
    public string $name,
    public BattleEventSpan $span,
    public BattleEventConditions $conditions,
    public array $commands,
  )
  {
  }

  /**
   * Reads and validates a troop's authored pages.
   *
   * @param mixed $events The troop's `events` entry.
   * @param string $where Where the pages are, for messages.
   * @param int $memberCount The troop's authored member count.
   * @param ScriptCommandCatalog $catalog The registered commands.
   * @return list<self>
   * @throws InvalidArgumentException When a page is malformed.
   */
  public static function readPages(mixed $events, string $where, int $memberCount, ScriptCommandCatalog $catalog): array
  {
    if ($events === null || $events === []) {
      return [];
    }

    if (! is_array($events) || ! array_is_list($events)) {
      throw new InvalidArgumentException("{$where} events must be a list of event pages.");
    }

    return array_map(
      static fn(mixed $page, int $index): self => self::fromArray($page, "{$where} events[{$index}]", $memberCount, $catalog),
      $events,
      array_keys($events),
    );
  }

  /**
   * Reads and validates one authored page.
   *
   * @throws InvalidArgumentException When the page is malformed.
   */
  public static function fromArray(mixed $data, string $where, int $memberCount, ScriptCommandCatalog $catalog): self
  {
    if (! is_array($data) || array_is_list($data)) {
      throw new InvalidArgumentException("{$where} must be a keyed event page.");
    }

    $unknown = array_diff(array_keys($data), self::KEYS);

    if ($unknown !== []) {
      throw new InvalidArgumentException(sprintf('%s has unknown key "%s"; a page holds %s.',
        $where, strval(reset($unknown)), implode(', ', self::KEYS)));
    }

    $name = $data['name'] ?? '';

    if (! is_string($name)) {
      throw new InvalidArgumentException("{$where} name must be text.");
    }

    $spanValue = $data['span'] ?? BattleEventSpan::BATTLE->value;
    $span = is_string($spanValue) ? BattleEventSpan::tryFrom(trim($spanValue)) : null;

    if ($span === null) {
      throw new InvalidArgumentException(sprintf('%s span must be battle, turn or moment, not "%s".',
        $where, is_scalar($spanValue) ? strval($spanValue) : get_debug_type($spanValue)));
    }

    $conditions = BattleEventConditions::fromArray($data['conditions'] ?? [], "{$where}.conditions", $memberCount);
    $commands = $data['commands'] ?? [];

    // An empty page is allowed, as an author adds a page before its commands; it runs nothing.
    if (! is_array($commands) || ! array_is_list($commands)) {
      throw new InvalidArgumentException("{$where} commands must be a list of commands.");
    }

    $problems = self::findCommandProblems($commands, "{$where}.commands", $memberCount, $catalog);

    if ($problems !== []) {
      throw new InvalidArgumentException(implode(' ', $problems));
    }

    return new self(trim($name), $span, $conditions, $commands);
  }

  /** @return array<string, mixed> The authored shape. */
  public function toArray(): array
  {
    return array_filter([
      'name' => $this->name === '' ? null : $this->name,
      'span' => $this->span->value,
      'conditions' => $this->conditions->toArray(),
      'commands' => $this->commands,
    ], static fn(mixed $value): bool => $value !== null);
  }

  /**
   * Finds the commands a battle page may not run, recursing into branch,
   * choice and sequence arms. Common Events are read when they run, and the
   * interpreter refuses their field-only commands there.
   *
   * @param array<int, mixed> $commands The commands.
   * @return list<string> The problems; empty when every command can run in battle.
   */
  public static function findCommandProblems(array $commands, string $where, int $memberCount, ScriptCommandCatalog $catalog): array
  {
    $problems = [];

    foreach ($commands as $index => $command) {
      $path = "{$where}[{$index}]";

      if (! is_array($command) || array_is_list($command)) {
        $problems[] = "{$path} must be a keyed command.";
        continue;
      }

      $type = is_string($command['type'] ?? null) ? trim($command['type']) : '';

      if (EventExecutionScope::BATTLE->allowsBuiltInCommand($type)) {
        $problems = [...$problems, ...self::findArmProblems($command, $type, $path, $memberCount, $catalog)];
        continue;
      }

      $definition = $catalog->findDefinition($type);

      if ($definition === null) {
        $problems[] = in_array($type, CinematicCommandSchema::COMMAND_TYPES, true)
          ? "{$path} uses \"{$type}\", which runs only in field scripts, not in battle events."
          : sprintf('%s has unknown command type "%s".', $path, $type === '' ? '(empty)' : $type);
        continue;
      }

      if (! $definition->runsIn(EventExecutionScope::BATTLE)) {
        $problems[] = "{$path} uses \"{$type}\", which runs only in field scripts, not in battle events.";
        continue;
      }

      foreach ($definition->findProblems($command) as $problem) {
        $problems[] = "{$path}: {$problem}";
      }

      foreach ($definition->fields as $field) {
        $member = $field->reference === ScriptCommandReference::TROOP_MEMBER
          ? ScriptCommandField::readPath($command, $field->key) : null;

        if (is_int($member) && $member >= $memberCount) {
          $problems[] = sprintf('%s names troop member %d, but the troop has %d.', $path, $member, $memberCount);
        }
      }
    }

    return $problems;
  }

  /** @return list<string> */
  private static function findArmProblems(array $command, string $type, string $path, int $memberCount, ScriptCommandCatalog $catalog): array
  {
    $problems = [];
    $lists = [];

    switch ($type) {
      case 'branch':
        try {
          WorldConditionEvaluator::validateAll((array) ($command['conditions'] ?? []), "{$path}.conditions");
        } catch (InvalidArgumentException $error) {
          $problems[] = $error->getMessage();
        }
        $lists = ['then' => $command['then'] ?? [], 'else' => $command['else'] ?? []];
        break;
      case 'sequence':
        $lists = ['commands' => $command['commands'] ?? []];
        break;
      case 'choice':
        foreach ((array) ($command['options'] ?? []) as $option => $entry) {
          $lists["options[{$option}].then"] = is_array($entry) ? ($entry['then'] ?? []) : [];
        }
        $lists['cancel'] = $command['cancel'] ?? [];
        break;
    }

    foreach ($lists as $arm => $commands) {
      if (! is_array($commands)) {
        $problems[] = "{$path}.{$arm} must be a list of commands.";
        continue;
      }

      $problems = [...$problems, ...self::findCommandProblems($commands, "{$path}.{$arm}", $memberCount, $catalog)];
    }

    return $problems;
  }
}
