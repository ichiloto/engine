<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Scenes\Arena;

use Closure;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;

/**
 * Composing a test troop in the battle test, apart from drawing and input
 * devices: its rows are its members, then Add an enemy, Fight and, where
 * someone keeps troops, Keep. Confirm on a member offers moving it (where a
 * graphical battle draws it) and removing it; Add opens the project's
 * enemies, and the one chosen joins where the Engine stands a new member on
 * the terminal field. Moving pins every member where it stands, so only the
 * one moved changes place; left and right move it across, up and down move
 * it back and forward on the ground, confirm keeps the place and cancel puts
 * it back. Cancel on the rows leaves the composer, which keeps the troop.
 */
final class TestTroopComposer
{
  public const string MEMBERS = 'members';
  public const string ENEMIES = 'enemies';
  public const string MEMBER = 'member';
  public const string PLACING = 'placing';
  /** What confirm or cancel asks of the battle test. */
  public const string FIGHT = 'fight';
  public const string KEEP = 'keep';
  public const string LEAVE = 'leave';
  /** How far one move takes a member being placed, in canvas units. */
  public const float PLACE_STEP = 15.0;

  public private(set) string $focus = self::MEMBERS;
  public private(set) int $rowIndex = 0;
  public private(set) int $enemyIndex = 0;
  public private(set) int $actionIndex = 0;
  public private(set) BattleTestTroop $troop;
  /** The troop as it was before a member was moved, to put back on cancel. */
  private ?BattleTestTroop $beforePlacing = null;

  /**
   * @param list<string> $enemies The project's enemies, by name.
   * @param Closure(BattleTestTroop, string): array{0: int, 1: int} $positionFor Where a new member fighting an enemy stands on the terminal field.
   * @param (Closure(BattleTestTroop): list<BattleStageSlot>)|null $placesFor Where each member stands in the graphical battle; null where none draws it.
   * @param bool $keeps Whether someone keeps a composed troop.
   */
  public function __construct(
    public readonly array $enemies,
    private readonly Closure $positionFor,
    private readonly ?Closure $placesFor = null,
    public readonly bool $keeps = false,
    ?BattleTestTroop $troop = null,
  ) {
    $this->troop = $troop ?? new BattleTestTroop();
  }

  /** @return list<string> The rows: `member:<n>` for each member, then `add`, and `fight` and `keep` once it has a member. */
  public array $rows {
    get => [...array_map(static fn(int $index): string => "member:{$index}", array_keys($this->troop->members)),
      ...($this->troop->isFull ? [] : ['add']),
      ...($this->troop->members === [] ? [] : ['fight', ...($this->keeps ? ['keep'] : [])])];
  }

  /** The member the cursor is on or acting on, or null on another row. */
  public ?int $memberIndex {
    get {
      $row = $this->rows[$this->rowIndex] ?? '';
      return str_starts_with($row, 'member:') ? (int) substr($row, 7) : null;
    }
  }

  /** @return list<string> What can be done to a member: `move` where a graphical battle draws it, and `remove`. */
  public array $actions {
    get => [...($this->placesFor === null ? [] : ['move']), 'remove'];
  }

  public function moveVertical(int $step): void
  {
    match ($this->focus) {
      self::MEMBERS => $this->rowIndex = max(0, min(count($this->rows) - 1, $this->rowIndex + $step)),
      self::ENEMIES => $this->enemyIndex = max(0, min(count($this->enemies) - 1, $this->enemyIndex + $step)),
      self::MEMBER => $this->actionIndex = max(0, min(count($this->actions) - 1, $this->actionIndex + $step)),
      // Up moves back, away from the camera; down moves forward.
      self::PLACING => $this->nudge(0, -$step * self::PLACE_STEP),
    };
  }

  /** Moves a member being placed across; false where left and right mean something else. */
  public function moveHorizontal(int $step): bool
  {
    if ($this->focus !== self::PLACING) { return false; }
    $this->nudge($step * self::PLACE_STEP, 0);
    return true;
  }

  /** @return self::FIGHT|self::KEEP|null What the battle test is asked to do. */
  public function confirm(): ?string
  {
    switch ($this->focus) {
      case self::MEMBERS:
        $row = $this->rows[$this->rowIndex] ?? '';
        if ($this->memberIndex !== null) {
          $this->focus = self::MEMBER;
          $this->actionIndex = 0;
        } elseif ($row === 'add' && $this->enemies !== []) {
          $this->focus = self::ENEMIES;
        }
        return match ($row) { 'fight' => self::FIGHT, 'keep' => self::KEEP, default => null };
      case self::ENEMIES:
        $enemy = $this->enemies[$this->enemyIndex] ?? null;
        if ($enemy !== null && !$this->troop->isFull) {
          $this->troop = $this->troop->withMember($enemy, ($this->positionFor)($this->troop, $enemy));
          $this->rowIndex = count($this->troop->members) - 1;
        }
        $this->focus = self::MEMBERS;
        return null;
      case self::MEMBER:
        $member = $this->memberIndex;
        if ($member === null) {
          $this->focus = self::MEMBERS;
        } elseif (($this->actions[$this->actionIndex] ?? '') === 'move' && $this->placesFor !== null) {
          $this->beforePlacing = $this->troop;
          $this->troop = $this->troop->withPlaces(($this->placesFor)($this->troop));
          $this->focus = self::PLACING;
        } else {
          $this->troop = $this->troop->withoutMember($member);
          $this->rowIndex = max(0, min(count($this->rows) - 1, $this->rowIndex));
          $this->focus = self::MEMBERS;
        }
        return null;
      case self::PLACING:
        $this->beforePlacing = null;
        $this->focus = self::MEMBERS;
        return null;
    }
    return null;
  }

  /** @return self::LEAVE|null Leaving the composer, when cancel on its rows asks for it. */
  public function cancel(): ?string
  {
    if ($this->focus === self::MEMBERS) { return self::LEAVE; }
    if ($this->focus === self::PLACING && $this->beforePlacing !== null) {
      $this->troop = $this->beforePlacing;
      $this->beforePlacing = null;
    }
    $this->focus = $this->focus === self::MEMBER || $this->focus === self::PLACING || $this->focus === self::ENEMIES
      ? self::MEMBERS : $this->focus;
    return null;
  }

  /** Moves the member being placed across and back by canvas units, never in front of the ground's near edge. */
  private function nudge(float $across, float $back): void
  {
    $member = $this->memberIndex;
    $place = $member === null ? null : $this->troop->members[$member]['place'];
    if ($place === null) { return; }
    $places = $this->troop->getPlaces();
    $places[$member] = new BattleStageSlot(max(0.0, $place->x + $across), max(0.0, $place->depth + $back),
      $place->width, $place->height, $place->displayScale);
    $this->troop = $this->troop->withPlaces($places);
  }
}
