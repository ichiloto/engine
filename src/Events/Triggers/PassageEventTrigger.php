<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Events\Enumerations\EventEngagement;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use InvalidArgumentException;

/** An Action-operated passage whose existing map-event completion permanently opens its cells. */
class PassageEventTrigger extends ScriptEventTrigger
{
  /** Validate the source without loading scripts or touching runtime state. */
  public static function validateDefinition(array $definition, ?string $mapId): void
  {
    if (trim($mapId ?? '') === '' || !is_string($definition['marker'] ?? null)
      || trim($definition['marker']) === '') {
      throw new InvalidArgumentException('An opening passage requires a stable map id and event marker.');
    }
    $data = $definition['data'] ?? [];
    if (!is_array($data) || ($data['reusable'] ?? null) !== false
      || ($data['mode'] ?? self::MODE_ACTION) !== self::MODE_ACTION
      || ($definition['engagement'] ?? null) !== EventEngagement::FACING->value) {
      throw new InvalidArgumentException('An opening passage requires facing engagement, Action mode and reusable false.');
    }
  }

  public function configure(): void
  {
    self::validateDefinition(['data' => (array) $this->data, 'marker' => $this->marker,
      'engagement' => $this->engagement->value], $this->mapId);
    parent::configure();
    if ($this->scriptSource !== null && !is_file($this->scriptSource)) {
      throw new InvalidArgumentException('The passage opening script is missing.');
    }
  }

  /** The event owns access; its underlying physical cells must support walking once it opens. */
  public static function validateFloor(CellArea $area, array $collisions): void
  {
    foreach ($area->cells as [$x, $y]) {
      if (!isset($collisions[$y][$x]) || (CollisionType::tryFrom($collisions[$y][$x])?->blocksMovement() ?? true)) {
        throw new InvalidArgumentException('An opening passage requires walkable physical floor beneath every event cell.');
      }
    }
  }

  /** A closed passage blocks entry even while its reveal interaction is available. */
  public function getEntryRefusal(Vector2 $origin, Vector2 $destination): ?string
  {
    return !$this->isComplete && !$this->area->contains($origin) && $this->area->contains($destination)
      ? ($this->whenBlocked ?? '') : null;
  }
}
