<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

final readonly class BattleHudStatusSnapshot
{
  /** @var list<BattleHudStatusRow> */
  public array $rows;

  /** @param list<BattleHudStatusRow> $rows */
  public function __construct(
    public string $title,
    public string $help,
    array $rows,
    public int $visibleRowCount = 4,
  ) {
    if (!array_is_list($rows) || $visibleRowCount < 0 || $visibleRowCount > 4 || count($rows) > $visibleRowCount) {
      throw new InvalidArgumentException('Battle HUD stats must fit their four-row viewport.');
    }
    $copy = [];
    foreach ($rows as $row) {
      if (!$row instanceof BattleHudStatusRow) {
        throw new InvalidArgumentException('Battle HUD stats require typed BattleHudStatusRow entries.');
      }
      // Appending each value detaches caller-owned PHP reference aliases.
      $copy[] = $row;
    }
    $this->rows = $copy;
  }
}
