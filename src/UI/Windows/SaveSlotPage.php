<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Windows;

use InvalidArgumentException;

/** Shared Save/Load page ownership across Terminal and graphical surfaces. */
final class SaveSlotPage
{
  public const int SIZE = 5;

  public static function getAdjacentIndex(int $slotCount, int $activeIndex, int $direction, int $capacity = self::SIZE): int
  {
    self::validateBounds($slotCount, $capacity);
    if ($direction !== -1 && $direction !== 1) { throw new InvalidArgumentException('Save page navigation requires previous or next.'); }
    return max(0, min($slotCount - 1, $activeIndex + $direction * $capacity));
  }

  /** @return array{int, int} First inclusive, last exclusive. */
  public static function getVisibleRange(int $slotCount, int $activeIndex, int $capacity = self::SIZE): array
  {
    self::validateBounds($slotCount, $capacity);
    $index = max(0, min($activeIndex, $slotCount - 1));
    $first = min(intdiv($index, $capacity) * $capacity, max(0, $slotCount - $capacity));
    return [$first, min($slotCount, $first + $capacity)];
  }
  private static function validateBounds(int $slotCount, int $capacity): void
  {
    if ($slotCount < 0 || $capacity < 1 || $capacity > self::SIZE) {
      throw new InvalidArgumentException('Save pages require a nonnegative count and capacity between one and five.');
    }
  }
}
