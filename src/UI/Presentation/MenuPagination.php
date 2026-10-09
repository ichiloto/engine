<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Stable, whole-record pages measured without reserving space inside the record viewport. */
final readonly class MenuPagination
{
  /** @var non-empty-list<array{first:int, last:int}> */
  public array $pages;

  /** @param non-empty-list<int> $heights */
  public function __construct(array $heights, float $availableHeight)
  {
    if (!array_is_list($heights) || $heights === [] || !is_finite($availableHeight) || $availableHeight <= 0) {
      throw new InvalidArgumentException('Menu pagination requires ordered records and a finite positive viewport.');
    }
    $pages = [];
    $first = 0;
    $height = 0;
    foreach ($heights as $index => $recordHeight) {
      if (!is_int($recordHeight) || $recordHeight <= 0 || $recordHeight > $availableHeight) {
        throw new InvalidArgumentException('Every menu record must fit wholly inside the page viewport.');
      }
      if ($height + $recordHeight > $availableHeight) {
        $pages[] = ['first' => $first, 'last' => $index - 1];
        $first = $index;
        $height = 0;
      }
      $height += $recordHeight;
    }
    $pages[] = ['first' => $first, 'last' => count($heights) - 1];
    $this->pages = $pages;
  }

  public function getPageIndex(int $recordIndex): int
  {
    foreach ($this->pages as $index => $page) {
      if ($recordIndex >= $page['first'] && $recordIndex <= $page['last']) { return $index; }
    }
    throw new InvalidArgumentException('The selected menu record must belong to a measured page.');
  }

  public function getAdjacentRecordIndex(int $recordIndex, int $direction): int
  {
    if ($direction !== -1 && $direction !== 1) {
      throw new InvalidArgumentException('Menu page navigation requires a previous or next direction.');
    }
    $index = $this->getPageIndex($recordIndex);
    $targetIndex = max(0, min(count($this->pages) - 1, $index + $direction));
    if ($targetIndex === $index) { return $recordIndex; }
    $target = $this->pages[$targetIndex];
    return min($target['last'], $target['first'] + $recordIndex - $this->pages[$index]['first']);
  }
}
