<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Serializable;

/**
 * Class Trigger. Represents a trigger.
 *
 * @package Ichiloto\Engine\Field
 */
class Trigger implements Serializable
{
  /**
   * Trigger constructor.
   *
   * @param string $to The destination.
   * @param Rect $area The trigger area.
   * @param Vector2 $spawnPoint The spawn point.
   */
  public function __construct(
    protected(set) string $to,
    protected(set) Rect $area,
    protected(set) Vector2 $spawnPoint
  )
  {
  }

  /**
   * Converts an array to a trigger.
   *
   * @param array $data The data.
   * @return Trigger
   */
  public static function fromArray(array $data): Trigger
  {
    return new Trigger(
      $data['to'],
      new Rect(
        $data['trigger_area']['x'] ?? 0,
        $data['trigger_area']['y'] ?? 0,
        $data['trigger_area']['width'] ?? 1,
        $data['trigger_area']['height'] ?? 1
      ),
      new Vector2(
        $data['spawn_point']['x'] ?? 0,
        $data['spawn_point']['y'] ?? 0
      )
    );
  }

  /**
   * Converts the trigger to an array.
   *
   * @return array The array.
   */
  private function toArray(): array
  {
    return [
      'to' => $this->to,
      'trigger_area' => [
        'x' => $this->area->getX(),
        'y' => $this->area->getY(),
        'width' => $this->area->getWidth(),
        'height' => $this->area->getHeight(),
      ],
      'spawn_point' => ['x' => $this->to, 'y' => $this->spawnPoint],
    ];
  }

  /**
   * @inheritDoc
   */
  public function serialize(): void
  {
    serialize($this->toArray());
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    unserialize($data);
  }

  /**
   * Serializes the trigger.
   *
   * @return array The serialized trigger.
   */
  public function __serialize(): array
  {
    return $this->toArray();
  }

  /**
   * Deserializes the trigger.
   *
   * @param array $data The data.
   * @return void
   */
  public function __unserialize(array $data): void
  {
    $this->to = $data['to'];
    $this->area = new Rect($data['x'], $data['y'], $data['width'], $data['height']);
    $this->spawnPoint = new Vector2($data['x'], $data['y']);
  }
}