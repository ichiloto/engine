<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Waymarks;

use Ichiloto\Engine\Quests\QuestWaymarkSubject;

/** Finds waymark subjects on the active map from its live field state. */
interface FieldWaymarkLocatorInterface
{
  /**
   * Locates a subject: one anchor per separate placement, or none when the
   * subject is absent, hidden, or (for landmarks) not yet visible.
   *
   * @return list<FieldWaymarkAnchor>
   */
  public function locate(QuestWaymarkSubject $subject, string $id): array;
}
