<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use InvalidArgumentException;

/** Immutable recorded results on the visual command clock; sampling never resolves combat. */
final readonly class BattleRecordedHitTimeline
{
  /** @param list<int> $frames @param list<CombatHitResult> $hits */
  public function __construct(public array $frames, public array $hits)
  {
    self::readFrames($frames, PHP_INT_MAX);
    if (count($frames) !== count($hits) || !array_is_list($hits)
      || array_any($hits, static fn(mixed $hit): bool => !$hit instanceof CombatHitResult)) {
      throw new InvalidArgumentException('Recorded hit feedback requires one frame per recorded hit.');
    }
  }

  /** @return list<int> */
  public static function readFrames(mixed $frames, int $length): array
  {
    if (!is_array($frames) || !array_is_list($frames) || $frames === [] || count($frames) > 10000) {
      throw new InvalidArgumentException('Hit feedback requires a non-empty ordered frame list.');
    }
    $previous = -1;
    foreach ($frames as $frame) {
      if (!is_int($frame) || $frame <= $previous || $frame >= $length) {
        throw new InvalidArgumentException('Hit feedback frames must be distinct increasing integers inside the effect.');
      }
      $previous = $frame;
    }
    return $frames;
  }

  /** @return array{hit: CombatHitResult, frame: int, index: int}|null */
  public function getFeedbackAtFrame(int $frame): ?array
  {
    for ($index = count($this->frames) - 1; $index >= 0; $index--) {
      if ($this->frames[$index] <= $frame) {
        return ['hit' => $this->hits[$index], 'frame' => $this->frames[$index], 'index' => $index];
      }
    }
    return null;
  }
}
