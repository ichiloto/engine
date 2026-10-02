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
