<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use InvalidArgumentException;

/**
 * A troop composed in the battle test, for testing only until it is kept:
 * each member's enemy, its terminal position and, once placed, its stage
 * place in the graphical battle (an unplaced member is arranged by the
 * battle). It becomes a troop as Data/troops.php holds one, so a fight and
 * a kept troop read the same data.
 */
final class BattleTestTroop
{
  /** The most members a test troop has, as RPG Maker's troops hold. */
  public const int MAX_MEMBERS = 8;

  /** @var list<array{enemy: string, position: array{0: int, 1: int}, place: ?BattleStageSlot}> */
  public readonly array $members;

  /** @param list<array{enemy: string, position: array{0: int, 1: int}, place: ?BattleStageSlot}> $members */
  public function __construct(array $members = [])
  {
    if (!array_is_list($members) || count($members) > self::MAX_MEMBERS) {
      throw new InvalidArgumentException(sprintf('A test troop holds up to %d members.', self::MAX_MEMBERS));
    }
    foreach ($members as $member) {
      if (!is_string($member['enemy'] ?? null) || trim($member['enemy']) === ''
        || !is_array($member['position'] ?? null) || !array_is_list($member['position']) || count($member['position']) !== 2
        || !is_int($member['position'][0]) || !is_int($member['position'][1])
        || !(($member['place'] ?? null) === null || $member['place'] instanceof BattleStageSlot)) {
        throw new InvalidArgumentException('A test troop member is an enemy, a terminal position and a stage place or none.');
      }
    }
    $this->members = array_map(static fn(array $member): array => ['enemy' => $member['enemy'],
      'position' => $member['position'], 'place' => $member['place'] ?? null], $members);
  }

  /** Whether another member may join. */
  public bool $isFull {
    get => count($this->members) >= self::MAX_MEMBERS;
  }

  /** @param array{0: int, 1: int} $position Its terminal position. */
  public function withMember(string $enemy, array $position): self
  {
    return new self([...$this->members, ['enemy' => $enemy, 'position' => $position, 'place' => null]]);
  }

  public function withoutMember(int $index): self
  {
    $members = $this->members;
    array_splice($members, $index, 1);
    return new self($members);
  }

  /** @param list<?BattleStageSlot> $places One per member, in member order. */
  public function withPlaces(array $places): self
  {
    if (!array_is_list($places) || count($places) !== count($this->members)) {
      throw new InvalidArgumentException('A test troop takes one stage place, or none, per member.');
    }
    return new self(array_map(static fn(array $member, ?BattleStageSlot $place): array => [...$member, 'place' => $place],
      $this->members, $places));
  }

  /** @return list<string> Each member's enemy, in order. */
  public function getEnemies(): array
  {
    return array_column($this->members, 'enemy');
  }

  /** @return list<?BattleStageSlot> Each member's stage place, or none. */
  public function getPlaces(): array
  {
    return array_column($this->members, 'place');
  }

  /**
   * The members as Data/troops.php holds a troop's `enemies`.
   *
   * @return list<array<string, mixed>>
   */
  public function toMembers(): array
  {
    return array_map(static fn(array $member): array => [
      'enemy' => $member['enemy'],
      'position' => $member['position'],
      ...($member['place'] === null ? [] : ['graphicalPlacement' => $member['place']->toArray()]),
    ], $this->members);
  }

  /** @return array<string, mixed> The troop as Data/troops.php holds one, under a name. */
  public function toTroopData(string $name): array
  {
    return ['name' => $name, 'enemies' => $this->toMembers()];
  }
}
