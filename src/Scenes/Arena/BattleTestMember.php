<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use InvalidArgumentException;
use Ichiloto\Engine\Battle\BattleCommandLoadout;
use Ichiloto\Engine\Battle\BattleCommandType;

/**
 * One member of a battle test party: which actor, at what level, wearing
 * what. Equipment names items by definition id, keyed by slot name; a slot
 * left out or null is empty.
 */
final readonly class BattleTestMember
{
  /**
   * @param array<string, ?string> $equipment Item definition ids by equipment slot name.
   * @param list<BattleCommandType>|null $commands Null keeps the normal command menu.
   * @param list<string> $skills Additional learned skill or spell names, for this test only.
   * @param list<string> $summons Additional summon ids, available without story unlocks in this test only.
   */
  public function __construct(
    public string $actorId,
    public int $level,
    public array $equipment = [],
    public ?array $commands = null,
    public array $skills = [],
    public array $summons = [],
  )
  {
    if (trim($actorId) === '') {
      throw new InvalidArgumentException('A battle test member needs an actor.');
    }
    if ($level < 1) {
      throw new InvalidArgumentException('A battle test member\'s level is at least 1.');
    }
    new BattleCommandLoadout($commands);
    foreach (['skills' => $skills, 'summons' => $summons] as $kind => $references) {
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
   * `skills` and `summons`. Anything else is refused, never ignored.
   *
   * @param array<string, mixed> $data
   * @throws InvalidArgumentException When the data is not a member.
   */
  public static function fromArray(array $data): self
  {
    $unknown = array_diff(array_keys($data), ['actor', 'level', 'equipment', 'commands', 'skills', 'summons']);
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
    foreach (['skills', 'summons'] as $kind) {
      if (!is_array($data[$kind] ?? [])) {
        throw new InvalidArgumentException("A battle test member's {$kind} are a list.");
      }
    }

    return new self($data['actor'], $data['level'], $equipment, $commands, $data['skills'] ?? [], $data['summons'] ?? []);
  }

  /**
   * The member as project data writes it, leaving out what is at its default:
   * no equipment, the normal command menu, no skills, no summons.
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
    ], static fn(mixed $value, string $key): bool => in_array($key, ['actor', 'level'], true)
      || ($key === 'commands' ? $value !== null : $value !== []), ARRAY_FILTER_USE_BOTH);
  }

  /** Another actor in this place, at the same level and with nothing equipped, since what one actor wears another may not. */
  public function withActor(string $actorId): self
  {
    return new self($actorId, $this->level, []);
  }

  public function withLevel(int $level): self
  {
    return new self($this->actorId, max(1, $level), $this->equipment, $this->commands, $this->skills, $this->summons);
  }

  public function withEquipment(string $slotName, ?string $itemId): self
  {
    return new self($this->actorId, $this->level, [...$this->equipment, $slotName => $itemId], $this->commands, $this->skills, $this->summons);
  }

  /** @param list<BattleCommandType>|null $commands */
  public function withCommands(?array $commands): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $commands, $this->skills, $this->summons);
  }

  /** @param list<string> $skills */
  public function withSkills(array $skills): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $skills, $this->summons);
  }

  /** @param list<string> $summons */
  public function withSummons(array $summons): self
  {
    return new self($this->actorId, $this->level, $this->equipment, $this->commands, $this->skills, $summons);
  }
}
