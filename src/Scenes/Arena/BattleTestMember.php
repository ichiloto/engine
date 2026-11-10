<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use InvalidArgumentException;
use Ichiloto\Engine\Battle\BattleCommandLoadout;
use Ichiloto\Engine\Battle\BattleCommandType;

/**
 * One member of a battle test party: which actor, at what level, wearing
 * what, and how it starts the fight. Equipment names items by definition
 * id, keyed by slot name; a slot left out or null is empty. A member starts
 * with full HP and MP and no states unless its starting HP, MP or states
 * are set.
 */
final readonly class BattleTestMember
{
  /**
   * @param array<string, ?string> $equipment Item definition ids by equipment slot name.
   * @param list<BattleCommandType>|null $commands Null keeps the normal command menu.
   * @param list<string> $skills Additional learned skill or spell names, for this test only.
   * @param list<string> $summons Additional summon ids, available without story unlocks in this test only.
   * @param BattleTestVital|null $hp The HP the member starts with; null for full.
   * @param BattleTestVital|null $mp The MP the member starts with; null for full.
   * @param list<string> $states The states the member starts with, by state id.
   * @param BattleTestWorld|null $world The story facts the member's form depends on, for test fights only; null for none.
   */
  public function __construct(
    public string $actorId,
    public int $level,
    public array $equipment = [],
    public ?array $commands = null,
    public array $skills = [],
    public array $summons = [],
    public ?BattleTestVital $hp = null,
    public ?BattleTestVital $mp = null,
    public array $states = [],
    public ?BattleTestWorld $world = null,
  )
  {
    if (trim($actorId) === '') {
      throw new InvalidArgumentException('A battle test member needs an actor.');
    }
    if ($level < 1) {
      throw new InvalidArgumentException('A battle test member\'s level is at least 1.');
    }
    new BattleCommandLoadout($commands);
    foreach (['skills' => $skills, 'summons' => $summons, 'states' => $states] as $kind => $references) {
      if (!array_is_list($references)) {
        throw new InvalidArgumentException("Battle test {$kind} must be a list.");
      }
      $seen = [];
      foreach ($references as $reference) {
        if (!is_string($reference) || trim($reference) === '' || isset($seen[$reference])) {
          throw new InvalidArgumentException("Battle test {$kind} must contain unique, nonempty references.");
        }
        $seen[$reference] = true;
      }
    }
  }

  /**
   * A member as project data writes one: `actor` and `level`, and optionally
   * `equipment` (slot name to item definition id, or null for empty),
   * `commands` (command type values; omitted keeps the normal menu),
   * `skills`, `summons`, `hp` and `mp` (each `['percent' => int]` or
   * `['points' => int]`; omitted is full), `states` (state ids) and `world`
   * ({@see BattleTestWorld::fromArray()}, the story facts its form depends on).
   * Anything else is refused, never ignored.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a member.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['actor', 'level', 'equipment', 'commands', 'skills', 'summons', 'hp', 'mp', 'states', 'world']);
    if ($unknown !== []) {
      throw new InvalidArgumentException('A battle test member has no ' . implode(', ', $unknown) . '.');
    }
    if (!is_string($data['actor'] ?? null) || !is_int($data['level'] ?? null)) {
      throw new InvalidArgumentException('A battle test member needs an actor id and a whole-number level.');
    }
    $equipment = $data['equipment'] ?? [];
    if (!is_array($equipment) || array_any($equipment, static fn(mixed $item, mixed $slot): bool =>
      !is_string($slot) || ($item !== null && !is_string($item)))) {
      throw new InvalidArgumentException('A battle test member\'s equipment maps slot names to item ids or null.');
    }
    $commands = $data['commands'] ?? null;
    if ($commands !== null) {
      if (!is_array($commands) || !array_is_list($commands) || array_any($commands, static fn(mixed $command): bool =>
        !is_string($command) || BattleCommandType::tryFrom($command) === null)) {
        throw new InvalidArgumentException('A battle test member\'s commands are a list of command types: '
          . implode(', ', array_column(BattleCommandType::cases(), 'value')) . '.');
      }
      $commands = array_map(BattleCommandType::from(...), $commands);
    }
    foreach (['skills', 'summons', 'states'] as $kind) {
      if (!is_array($data[$kind] ?? [])) {
        throw new InvalidArgumentException("A battle test member's {$kind} are a list.");
      }
    }
    $vitals = [];
    foreach (['hp', 'mp'] as $vital) {
      $value = $data[$vital] ?? null;
      if ($value !== null && !is_array($value)) {
        throw new InvalidArgumentException("A battle test member's starting {$vital} is written as percent or points.");
      }
      $vitals[$vital] = $value === null ? null : BattleTestVital::fromArray($value);
    }

    $world = $data['world'] ?? null;
    if ($world !== null && !is_array($world)) {
      throw new InvalidArgumentException("A battle test member's world is a map of story facts.");
    }

    return new self($data['actor'], $data['level'], $equipment, $commands, $data['skills'] ?? [], $data['summons'] ?? [],
      $vitals['hp'], $vitals['mp'], $data['states'] ?? [], $world === null ? null : BattleTestWorld::fromArray($world));
  }

  /**
   * The member as project data writes it, leaving out what is at its default:
   * no equipment, the normal command menu, no skills, no summons, full HP and
   * MP, no states, no story facts.
   *
   * @return array<string, mixed>
   */
  public function toArray(): array
  {
    return array_filter([
      'actor' => $this->actorId,
      'level' => $this->level,
      'equipment' => $this->equipment,
      'commands' => $this->commands === null ? null : array_map(static fn(BattleCommandType $command): string => $command->value, $this->commands),
      'skills' => $this->skills,
      'summons' => $this->summons,
      'hp' => $this->hp?->toArray(),
      'mp' => $this->mp?->toArray(),
      'states' => $this->states,
      'world' => $this->world?->toArray(),
    ], static fn(mixed $value, string $key): bool => in_array($key, ['actor', 'level'], true)
      || (in_array($key, ['commands', 'hp', 'mp', 'world'], true) ? $value !== null : $value !== []), ARRAY_FILTER_USE_BOTH);
  }

  /**
   * Another actor in this place, at the same level and starting condition,
   * with nothing equipped, no test loadout and no form, since what one actor
   * wears, is granted or becomes another may not.
   */
  public function withActor(string $actorId): self
  {
    return new self($actorId, $this->level, hp: $this->hp, mp: $this->mp, states: $this->states);
  }

  public function withLevel(int $level): self
  {
    return new self($this->actorId, max(1, $level), $this->equipment, $this->commands, $this->skills, $this->summons,
      $this->hp, $this->mp, $this->states, $this->world);
  }

  public function withEquipment(string $slotName, ?string $itemId): self
  {
    return new self($this->actorId, $this->level, [...$this->equipment, $slotName => $itemId], $this->commands, $this->skills,
      $this->summons, $this->hp, $this->mp, $this->states, $this->world);
  }

  /** @param list<BattleCommandType>|null $commands */
  public function withCommands(?array $commands): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $commands, $this->skills, $this->summons,
      $this->hp, $this->mp, $this->states, $this->world);
  }

  /** @param list<string> $skills */
  public function withSkills(array $skills): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $skills, $this->summons,
      $this->hp, $this->mp, $this->states, $this->world);
  }

  /** @param list<string> $summons */
  public function withSummons(array $summons): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $summons,
      $this->hp, $this->mp, $this->states, $this->world);
  }

  /** The member starting with this HP; null, or the whole maximum, for full. */
  public function withHp(?BattleTestVital $hp): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $this->summons,
      $hp === null || $hp->isFull ? null : $hp, $this->mp, $this->states, $this->world);
  }

  /** The member starting with this MP; null, or the whole maximum, for full. */
  public function withMp(?BattleTestVital $mp): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $this->summons,
      $this->hp, $mp === null || $mp->isFull ? null : $mp, $this->states, $this->world);
  }

  /** The member whose form depends on these story facts in test fights; null for none. */
  public function withWorld(?BattleTestWorld $world): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $this->summons,
      $this->hp, $this->mp, $this->states, $world);
  }

  /** @param list<string> $states The states the member starts with, by state id. */
  public function withStates(array $states): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $this->summons,
      $this->hp, $this->mp, $states, $this->world);
  }
}
