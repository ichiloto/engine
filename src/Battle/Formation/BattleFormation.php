<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Formation;

use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use InvalidArgumentException;
use SplObjectStorage;

/** One battle's mutable positions, keyed by participant instances and owned independently of saves/renderers. */
final class BattleFormation
{
  /** @var SplObjectStorage<CharacterInterface, array{side: string, initial: BattlePosition, current: BattlePosition}> */
  private SplObjectStorage $places;

  /** @param list<CharacterInterface> $party @param list<CharacterInterface> $troop
   * @param list<BattlePosition|null> $partyPositions @param list<BattlePosition|null> $troopPositions */
  public function __construct(array $party, array $troop, array $partyPositions = [], array $troopPositions = [])
  {
    $this->places = new SplObjectStorage();
    $this->synchronizeSide('party', $party, $partyPositions);
    $this->synchronizeSide('troop', $troop, $troopPositions);
  }

  /** Roster replacements keep existing instances' positions and give new instances distinct authored/default places.
   * @param list<CharacterInterface> $members @param list<BattlePosition|null> $positions */
  public function synchronizeSide(string $side, array $members, array $positions = []): void
  {
    if (!in_array($side, ['party', 'troop'], true) || !array_is_list($members) || !array_is_list($positions)
      || ($positions !== [] && count($positions) !== count($members))) {
      throw new InvalidArgumentException('A formation requires a side, participants and one optional logical position per participant.');
    }
    $seen = [];
    BattlePosition::validateDistinct($positions);
    foreach ($members as $member) {
      if (!$member instanceof CharacterInterface || isset($seen[spl_object_id($member)])
        || ($this->places->offsetExists($member) && $this->places[$member]['side'] !== $side)) {
        throw new InvalidArgumentException('Battle participants must be distinct instances belonging to one side.');
      }
      $seen[spl_object_id($member)] = true;
    }
    $candidate = clone $this->places;
    foreach ($this->places as $member) {
      if ($this->places[$member]['side'] === $side && !in_array($member, $members, true)) { $candidate->offsetUnset($member); }
    }
    $occupied = [];
    foreach ($candidate as $member) {
      if ($candidate[$member]['side'] === $side) {
        $position = $candidate[$member]['current'];
        $occupied[$position->row->value . ':' . $position->lane] = true;
      }
    }
    $ordered = array_keys($members);
    usort($ordered, static fn(int $a, int $b): int => (($positions[$a] ?? null) === null ? 1 : 0)
      <=> (($positions[$b] ?? null) === null ? 1 : 0));
    foreach ($ordered as $index) {
      $member = $members[$index];
      $authored = $positions[$index] ?? null;
      if ($authored !== null && !$authored instanceof BattlePosition) { throw new InvalidArgumentException('Formation entries must be logical battle positions or null.'); }
      if ($candidate->offsetExists($member)) { continue; }
      $position = $authored ?? new BattlePosition(BattleRow::FRONT, $index);
      if ($authored === null) {
        while (isset($occupied[$position->row->value . ':' . $position->lane])) { $position = new BattlePosition($position->row, $position->lane + 1); }
      }
      $key = $position->row->value . ':' . $position->lane;
      if (isset($occupied[$key])) { throw new InvalidArgumentException('Two participants on one side cannot occupy the same logical battle position.'); }
      $occupied[$key] = true;
      $candidate[$member] = ['side' => $side, 'initial' => $position, 'current' => $position];
    }
    $this->places = $candidate;
  }

  public function getPosition(CharacterInterface $member): ?BattlePosition
  {
    return $this->places->offsetExists($member) ? $this->places[$member]['current'] : null;
  }

  public function getInitialPosition(CharacterInterface $member): ?BattlePosition
  {
    return $this->places->offsetExists($member) ? $this->places[$member]['initial'] : null;
  }

  /** @param list<CharacterInterface> $eligible @return list<CharacterInterface> */
  public function getLineTargets(CharacterInterface $anchor, array $eligible): array
  {
    $place = $this->getPosition($anchor);
    if ($place === null) { return []; }
    $side = $this->places[$anchor]['side'];
    $targets = array_values(array_filter($eligible, fn(CharacterInterface $member): bool =>
      $this->places->offsetExists($member) && $this->places[$member]['side'] === $side
        && $this->places[$member]['current']->lane === $place->lane));
    usort($targets, fn(CharacterInterface $a, CharacterInterface $b): int =>
      ($this->places[$a]['current']->row === BattleRow::FRONT ? 0 : 1)
        <=> ($this->places[$b]['current']->row === BattleRow::FRONT ? 0 : 1));
    return $targets;
  }

  /** @param list<CharacterInterface> $eligible @return list<CharacterInterface> */
  public function getRowTargets(BattleRow $row, array $eligible): array
  {
    return array_values(array_filter($eligible, fn(CharacterInterface $member): bool => $this->getPosition($member)?->row === $row));
  }

  /** Permutes only the selected side's occupied places once, preserving every other participant.
   * @param list<CharacterInterface> $targets */
  public function shufflePositions(array $targets, CombatRandomSource $random): void
  {
    $targets = $this->requireOneSide($targets);
    $positions = array_map($this->getPosition(...), $targets);
    for ($i = count($positions) - 1; $i > 0; --$i) {
      $other = $random->nextInt(0, $i);
      [$positions[$i], $positions[$other]] = [$positions[$other], $positions[$i]];
    }
    foreach ($targets as $index => $target) { $this->setPosition($target, $positions[$index]); }
  }

  /** Moves back-row targets to distinct front-row places, keeping their lane when free.
   * @param list<CharacterInterface> $targets */
  public function moveToFront(array $targets): void
  {
    foreach ($this->requireOneSide($targets) as $target) {
      $position = $this->getPosition($target);
      if ($position->row === BattleRow::FRONT) { continue; }
      $occupied = [];
      foreach ($this->places as $member) {
        $data = $this->places[$member];
        if ($data['side'] === $this->places[$target]['side'] && $data['current']->row === BattleRow::FRONT) {
          $occupied[$data['current']->lane] = true;
        }
      }
      $lane = $position->lane;
      if (isset($occupied[$lane])) { $lane = 0; while (isset($occupied[$lane])) { ++$lane; } }
      $this->setPosition($target, new BattlePosition(BattleRow::FRONT, $lane));
    }
  }

  /** @param list<CharacterInterface> $targets @return list<CharacterInterface> */
  private function requireOneSide(array $targets): array
  {
    $side = null;
    $seen = [];
    foreach ($targets as $target) {
      if (!$target instanceof CharacterInterface || !$this->places->offsetExists($target) || isset($seen[spl_object_id($target)])) {
        throw new InvalidArgumentException('Formation effects require distinct current battle participants.');
      }
      $current = $this->places[$target]['side'];
      if ($side !== null && $side !== $current) { throw new InvalidArgumentException('A formation effect operates on one side.'); }
      $side = $current;
      $seen[spl_object_id($target)] = true;
    }
    return $targets;
  }

  private function setPosition(CharacterInterface $member, BattlePosition $position): void
  {
    $data = $this->places[$member];
    $data['current'] = $position;
    $this->places[$member] = $data;
  }
}
