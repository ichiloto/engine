<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use InvalidArgumentException;

/** Selection is retained state, not a claim that this panel owns input focus. */
final readonly class BattleHudListSnapshot
{
  /** @var list<BattleHudRow> */
  public array $rows;

  /** @param list<BattleHudRow> $rows Only populated visible rows; never padding rows. */
  public function __construct(
    public string $title,
    public string $help,
    array $rows,
    public int $activeIndex,
    public int $scrollOffset,
    public int $visibleRowCount,
    public int $totalRows,
    public int $currentPage,
    public int $totalPages,
    public string $emptyMessage = '',
  ) {
    if (!array_is_list($rows) || $visibleRowCount < 0 || $visibleRowCount > 4 || count($rows) > $visibleRowCount) {
      throw new InvalidArgumentException('Battle HUD lists must fit their four-row viewport.');
    }
    $copy = [];
    foreach ($rows as $row) {
      if (!$row instanceof BattleHudRow) {
        throw new InvalidArgumentException('Battle HUD lists require typed BattleHudRow entries.');
      }
      // Appending each value detaches caller-owned PHP reference aliases.
      $copy[] = $row;
    }
    $this->rows = $copy;
  }
}
