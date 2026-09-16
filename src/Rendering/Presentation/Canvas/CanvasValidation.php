<?php

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use InvalidArgumentException;

/** Limits specific to the negotiated canvas contract, not legacy sprite geometry. */
final class CanvasValidation
{
  public const int MAX_EXTENT = 16384;

  public static function id(string $id): void
  {
    if ($id === '' || strlen($id) > 256 || preg_match('//u', $id) !== 1
      || preg_match('/\p{Cc}/u', $id) === 1) {
      throw new InvalidArgumentException('Canvas IDs require nonempty UTF-8 without controls, at most 256 bytes.');
    }
  }

  /** @template T of CanvasImage|CanvasIndicator|CanvasTextLayer
   * @param list<T> $items
   * @param class-string<T> $type
   * @return list<T>
   */
  public static function orderedList(array $items, string $type, int $limit): array
  {
    if (!array_is_list($items) || count($items) > $limit) {
      throw new InvalidArgumentException("Canvas collection must be a list of at most {$limit} entries.");
    }
    $ids = $copy = [];
    foreach ($items as $item) {
      if (!$item instanceof $type || isset($ids[$item->id])) {
        throw new InvalidArgumentException('Canvas collections require typed entries with unique IDs.');
      }
      $ids[$item->id] = true;
      $copy[] = $item;
    }
    // Copy elements individually to detach caller-owned PHP reference aliases.
    usort($copy, static fn($a, $b) => $a->layer <=> $b->layer);
    return $copy;
  }
}
