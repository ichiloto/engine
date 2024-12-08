<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use InvalidArgumentException;

class EventTriggerFactory
{
  private function __construct()
  {
  }

  /**
   * Creates an event trigger.
   *
   * @param array $args The arguments.
   * @return EventTrigger The event trigger.
   * @throws NotFoundException If the class does not exist.
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function create(array $args): EventTrigger
  {
    $class = $args['class'] ?? throw new RequiredFieldException('class');
    if (!class_exists($class)) {
      throw new NotFoundException($class);
    }

    if (! $class instanceof EventTrigger) {
      throw new InvalidArgumentException("The class, $class, must be an instance of " . EventTrigger::class);
    }

    if (!isset($args['area'])) {
      throw new RequiredFieldException('area');
    }

    $area = new Rect(
      $args['area']['x'] ?? throw new RequiredFieldException('area.x'),
      $args['area']['y'] ?? throw new RequiredFieldException('area.y'),
      $args['area']['width'] ?? throw new RequiredFieldException('area.width'),
      $args['area']['height'] ?? throw new RequiredFieldException('area.height')
    );

    $data = $area['data'] ?? throw new RequiredFieldException('data');

    return new $class($area, $data);
  }
}