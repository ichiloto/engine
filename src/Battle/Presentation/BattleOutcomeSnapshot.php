<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\ActionHint;
use InvalidArgumentException;

/**
 * One read-only view of the battle result window: the page it shows now and the controls that act on it.
 * The window keeps the result, its reveal, its pages and its input; every presentation reads this view.
 */
final readonly class BattleOutcomeSnapshot
{
  /** @var list<string> */
  public array $lines;
  /** @var list<ActionHint> */
  public array $hints;

  /**
   * @param list<string> $lines The page's lines, wrapped by the window, without trailing blank rows.
   * @param list<ActionHint> $hints What the window's controls do now.
   * @param int $pageIndex The page shown, from zero.
   * @param int $pageCount How many pages the result has.
   */
  public function __construct(public string $title, array $lines, array $hints = [],
    public int $pageIndex = 0, public int $pageCount = 1)
  {
    if (!array_is_list($lines) || !array_all($lines, static fn($line): bool => is_string($line))) {
      throw new InvalidArgumentException('A battle outcome reads as an ordered list of lines.');
    }
    if (!array_is_list($hints) || !array_all($hints, static fn($hint): bool => $hint instanceof ActionHint)) {
      throw new InvalidArgumentException('Battle outcome hints require typed descriptors.');
    }
    if ($pageCount < 1 || $pageIndex < 0 || $pageIndex >= $pageCount) {
      throw new InvalidArgumentException('A battle outcome shows one of its pages.');
    }
    $this->lines = $lines;
    $this->hints = $hints;
  }
}
