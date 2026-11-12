<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/**
 * Where a random battle met on a cell is fought, as the map's encounter block
 * decides it ({@see EncounterTable::resolveBattleArenaAt()}): the battle
 * settings it starts with, the encounter terrain of the cell, and which part
 * of the block decided.
 */
final readonly class EncounterArenaUse
{
  /**
   * @param string $scope What decided: `troop <name>`, a rule's scope ({@see EncounterArenaRule::$scope}), `map` or `default`.
   * @param array<string, mixed> $settings The battle's presentation settings: its arena and, to refine it, the terrain.
   * @param string|null $terrain The encounter terrain of the cell, or null for none.
   */
  public function __construct(public string $scope, public array $settings, public ?string $terrain)
  {
  }
}
