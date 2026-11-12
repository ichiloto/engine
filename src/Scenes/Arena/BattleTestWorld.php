<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionType;
use Ichiloto\Engine\Entities\Character;
use InvalidArgumentException;

/**
 * Story facts a battle test fight starts with, such as the value of a
 * variable a character's form depends on: world variables, switches and
 * story events. They seed a fresh world state for each test fight only;
 * the campaign and its saves never see them. Project data writes them as
 * `['variables' => [name => value], 'switches' => [name => bool], 'events' => [name, ...]]`,
 * leaving out what is empty.
 */
final readonly class BattleTestWorld
{
  /**
   * @param array<string, int|float|string> $variables
   * @param array<string, bool> $switches
   * @param list<string> $events
   */
  public function __construct(public array $variables = [], public array $switches = [], public array $events = [])
  {
    foreach ($variables as $name => $value) {
      if (!is_string($name) || trim($name) === '' || (!is_int($value) && !is_float($value) && !is_string($value))) {
        throw new InvalidArgumentException('A battle test world variable is named and holds a number or text.');
      }
    }
    foreach ($switches as $name => $value) {
      if (!is_string($name) || trim($name) === '' || !is_bool($value)) {
        throw new InvalidArgumentException('A battle test world switch is named and is on or off.');
      }
    }
    if (!array_is_list($events) || array_any($events, static fn(mixed $event): bool => !is_string($event) || trim($event) === '')
      || count(array_unique($events)) !== count($events)) {
      throw new InvalidArgumentException('Battle test world events are a list of unique story event names.');
    }
    if ($variables === [] && $switches === [] && $events === []) {
      throw new InvalidArgumentException('A battle test world sets at least one story fact.');
    }
  }

  /**
   * The facts that make a list of world conditions hold, where each can be
   * set: a variable compared with `==`, a switch, or a story event. None
   * when a condition is of another kind (items, quests, the party), since a
   * test fight's world state cannot set it.
   *
   * @param list<array<string, mixed>> $conditions
   */
  public static function fromConditions(array $conditions): ?self
  {
    $variables = [];
    $switches = [];
    $events = [];
    foreach ($conditions as $condition) {
      $type = WorldConditionType::tryFrom(strval($condition['type'] ?? ''));
      $name = trim(strval($condition['name'] ?? ''));
      $value = $condition['value'] ?? null;
      $settable = $name !== '' && match ($type) {
        WorldConditionType::VARIABLE => strval($condition['op'] ?? '==') === '=='
          && (is_int($value) || is_float($value) || is_string($value))
          && (!array_key_exists($name, $variables) || $variables[$name] === $value),
        WorldConditionType::SWITCH => !array_key_exists($name, $switches) || $switches[$name] === (bool) ($value ?? true),
        WorldConditionType::EVENT => true,
        default => false,
      };
      if (!$settable) {
        return null;
      }
      match ($type) {
        WorldConditionType::VARIABLE => $variables[$name] = $value,
        WorldConditionType::SWITCH => $switches[$name] = (bool) ($value ?? true),
        default => $events[] = $name,
      };
    }

    return $variables === [] && $switches === [] && $events === [] ? null : new self($variables, $switches, array_values(array_unique($events)));
  }

  /**
   * The forms a character's techniques take by story facts a test fight can
   * set: each form of its crisis ability chosen by world conditions, with
   * the facts that choose it and the technique it offers, in authored order.
   * None for a character whose techniques do not depend on such facts.
   *
   * @return list<array{world: self, technique: string}>
   */
  public static function listCharacterForms(Character $character): array
  {
    $forms = [];
    foreach ($character->crisisAbility?->variants ?? [] as $variant) {
      $world = $variant['conditions'] === [] ? null : self::fromConditions($variant['conditions']);
      if ($world !== null && !array_any($forms, static fn(array $form): bool => $form['world']->equals($world))) {
        $forms[] = ['world' => $world, 'technique' => $variant['skill']];
      }
    }

    return $forms;
  }

  /**
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a battle test world.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['variables', 'switches', 'events']);
    if ($unknown !== []) {
      throw new InvalidArgumentException('A battle test world has no ' . implode(', ', $unknown) . '.');
    }
    foreach (['variables', 'switches', 'events'] as $key) {
      if (!is_array($data[$key] ?? [])) {
        throw new InvalidArgumentException("A battle test world's {$key} are a list or a map.");
      }
    }

    return new self($data['variables'] ?? [], $data['switches'] ?? [], $data['events'] ?? []);
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return array_filter(['variables' => $this->variables, 'switches' => $this->switches, 'events' => $this->events],
      static fn(array $facts): bool => $facts !== []);
  }

  /** Whether another world sets exactly the same facts. */
  public function equals(self $other): bool
  {
    $normalize = static function (self $world): array {
      $variables = array_map(WorldConditionEvaluator::normalizeEqualityOperand(...), $world->variables);
      ksort($variables);
      $switches = $world->switches;
      ksort($switches);
      $events = $world->events;
      sort($events);
      return [$variables, $switches, $events];
    };

    return $normalize($this) === $normalize($other);
  }

  /**
   * Both worlds' facts together; none when they set one fact differently.
   */
  public function merge(self $other): ?self
  {
    foreach ($other->variables as $name => $value) {
      if (array_key_exists($name, $this->variables)
        && WorldConditionEvaluator::normalizeEqualityOperand($this->variables[$name]) !== WorldConditionEvaluator::normalizeEqualityOperand($value)) {
        return null;
      }
    }
    foreach ($other->switches as $name => $value) {
      if (array_key_exists($name, $this->switches) && $this->switches[$name] !== $value) {
        return null;
      }
    }

    return new self([...$this->variables, ...$other->variables], [...$this->switches, ...$other->switches],
      array_values(array_unique([...$this->events, ...$other->events])));
  }

  /** A fresh world state holding these facts, for one test fight. */
  public function createGameState(): GameState
  {
    $state = new GameState();
    foreach ($this->variables as $name => $value) { $state->setVariable($name, $value); }
    foreach ($this->switches as $name => $value) { $state->setSwitch($name, $value); }
    foreach ($this->events as $event) { $state->recordStoryEvent($event); }

    return $state;
  }

  /** The facts as a reader reads them: "Kaelion curse state: Vampiric", one after another. */
  public function describe(): string
  {
    $humanize = static fn(string $name): string => ucfirst(trim(preg_replace('/[\s_\-.]+/', ' ', $name) ?? $name));
    return implode(', ', [
      ...array_map(static fn(string $name, int|float|string $value): string => sprintf('%s: %s', $humanize($name),
        is_string($value) ? $humanize($value) : (string) $value), array_keys($this->variables), $this->variables),
      ...array_map(static fn(string $name, bool $value): string => sprintf('%s: %s', $humanize($name), $value ? 'On' : 'Off'),
        array_keys($this->switches), $this->switches),
      ...array_map($humanize, $this->events),
    ]);
  }
}
