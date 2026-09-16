<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Progression\ExperienceAwardResult;

/** Already-awarded facts. Rendering or replaying this value never grants rewards. */
final readonly class BattleRewards
{
  /** @var list<BattleProgression> */
  public array $progression;
  /**
   * @param list<ExperienceAwardResult|BattleProgression> $progression
   * @param list<array{id: string, name: string, description: string, quantity: int, received?: int}> $items Drop quantities and actual inventory deltas.
   * @param array<string, string> $summary Only metrics actually supplied by combat.
   * @param list<array{title: string, description: string}> $specialRewards Explicit authored rewards, never inferred rarity.
   */
  public function __construct(
    public int $experiencePerMember,
    public int $gold,
    array $progression,
    public array $items = [],
    public array $summary = [],
    public array $specialRewards = [],
  ) {
    $this->progression = array_map(static fn(ExperienceAwardResult|BattleProgression $award): BattleProgression =>
      $award instanceof BattleProgression ? $award : BattleProgression::fromAward($award), array_values($progression));
  }

  /** @param InventoryItem[] $items */
  public static function snapshotItems(array $items): array
  {
    $rows = [];
    foreach ($items as $item) {
      if (!isset($rows[$item->id])) {
        $rows[$item->id] = ['id' => $item->id, 'name' => $item->name,
          'description' => $item->description, 'quantity' => 0];
      }
      $rows[$item->id]['quantity'] += $item->quantity;
    }
    return array_values($rows);
  }
}
