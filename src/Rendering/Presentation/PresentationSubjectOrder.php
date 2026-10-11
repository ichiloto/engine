<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation;

use InvalidArgumentException;

/** One stable ground-row rank shared by actors and atomic local canvases. */
final class PresentationSubjectOrder
{
  /** @param list<PresentationSprite> $sprites @param list<PresentationWorldCanvas> $worldCanvases
   * @return list<PresentationSprite|PresentationWorldCanvas>
   */
  public static function getOrderedSubjects(array $sprites, array $worldCanvases): array
  {
    if (count($sprites) + count($worldCanvases) > 1024) {
      throw new InvalidArgumentException('Field sprites and world canvases share at most 1024 subjects.');
    }
    $subjects = [...PresentationSprite::orderedList($sprites), ...PresentationWorldCanvas::getOrderedList($worldCanvases)];
    // Existing actors precede groups at exact ties; each source list keeps its stable authored order.
    usort($subjects, static fn($a, $b) => [$a->layer, $a->y] <=> [$b->layer, $b->y]);
    return $subjects;
  }
}
