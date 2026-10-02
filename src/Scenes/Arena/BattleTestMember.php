<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use InvalidArgumentException;

/**
 * One member of a battle test party: which actor, at what level, wearing
 * what. Equipment names items by definition id, keyed by slot name; a slot
 * left out or null is empty.
 */
final readonly class BattleTestMember
{
  /**
   * @param array<string, ?string> $equipment Item definition ids by equipment slot name.
   */
  public function __construct(public string $actorId, public int $level, public array $equipment = [])
  {
    if (trim($actorId) === '') {
      throw new InvalidArgumentException('A battle test member needs an actor.');
    }
    if ($level < 1) {
      throw new InvalidArgumentException('A battle test member\'s level is at least 1.');
    }
  }

  /** Another actor in this place, at the same level and with nothing equipped, since what one actor wears another may not. */
  public function withActor(string $actorId): self
  {
    return new self($actorId, $this->level, []);
  }

  public function withLevel(int $level): self
  {
    return new self($this->actorId, max(1, $level), $this->equipment);
  }

  public function withEquipment(string $slotName, ?string $itemId): self
  {
    return new self($this->actorId, $this->level, [...$this->equipment, $slotName => $itemId]);
  }
}