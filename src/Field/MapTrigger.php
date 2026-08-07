<?php

namespace Ichiloto\Engine\Field;

use Exception;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\MovementEventType;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Exceptions\IchilotoException;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Serializable;

/**
 * Class Trigger. Represents a trigger.
 *
 * @package Ichiloto\Engine\Field
 * @deprecated Superseded by {@see \Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger}.
 *             Still referenced by {@see MapManager} for the legacy map trigger format.
 */
class MapTrigger implements Serializable, ObserverInterface
{
  /**
   * Trigger constructor.
   *
   * @param string $destinationMap The destination map.
   * @param Rect $area The trigger area.
   * @param Vector2 $spawnPoint The spawn point.
   */
  public function __construct(
    protected(set) string $destinationMap,
    protected(set) Rect $area,
    protected(set) Vector2 $spawnPoint,
    protected(set) ?array $spawnSprite = null
  )
  {
  }

  /**
   * Converts an array to a trigger.
   *
   * @param array{destinationMap: string, trigger_area: array{x: int, y: int, width: int, height: int}, spawn_point: array{x: int, y: int}, spawn_sprite: string[]|null} $data The data.
   * @return MapTrigger
   * @throws IchilotoException If the trigger cannot be created from the array.
   */
  public static function tryFromArray(array $data): MapTrigger
  {
    try {
      return new MapTrigger(
        $data['destinationMap'],
        new Rect(
          $data['trigger_area']['x'] ?? 0,
          $data['trigger_area']['y'] ?? 0,
          $data['trigger_area']['width'] ?? 1,
          $data['trigger_area']['height'] ?? 1
        ),
        new Vector2(
          $data['spawn_point']['x'] ?? 0,
          $data['spawn_point']['y'] ?? 0
        ),
        $data['spawn_sprite'] ?? null
      );
    } catch (Exception $e) {
      throw new IchilotoException('Failed to create trigger from array.', IchilotoException::RUNTIME, $e);
    }
  }

  /**
   * Converts the trigger to an array.
   *
   * @return array{destinationMap: string, trigger_area: array{x: int, y: int, width: int, height: int}, spawn_point: array{x: int, y: int}, spawn_sprite: string[]|null} The array.
   */
  private function toArray(): array
  {
    return [
      'destinationMap' => $this->destinationMap,
      'trigger_area' => [
        'x' => $this->area->getX(),
        'y' => $this->area->getY(),
        'width' => $this->area->getWidth(),
        'height' => $this->area->getHeight(),
      ],
      'spawn_point' => [
        'x' => $this->spawnPoint->x,
        'y' => $this->spawnPoint->y,
      ],
      'spawn_sprite' => $this->spawnSprite,
    ];
  }

  /**
   * @inheritDoc
   */
  public function serialize(): ?string
  {
    return serialize($this->toArray());
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    $this->__unserialize(unserialize($data));
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
    $this->destinationMap = $data['destinationMap'];
    $this->area = new Rect(
      $data['trigger_area']['x'] ?? 0,
      $data['trigger_area']['y'] ?? 0,
      $data['trigger_area']['width'] ?? 1,
      $data['trigger_area']['height'] ?? 1
    );
    $this->spawnPoint = new Vector2(
      $data['spawn_point']['x'] ?? 0,
      $data['spawn_point']['y'] ?? 0
    );
    $this->spawnSprite = $data['spawn_sprite'] ?? null;
  }

  /**
   * @inheritDoc
   * @throws NotFoundException If the entity is not a game scene.
   * @throws IchilotoException If the entity is not a game scene.
   */
  public function onNotify(object $entity, EventInterface $event): void
  {
    if ($event instanceof MovementEvent && $event->movementEventType === MovementEventType::PLAYER_MOVE) {
      if ($entity instanceof GameScene) {
        $gameScene = $entity;
        // If the player has moved within the trigger's area, transport the player to the trigger's destination.
        if ( $this->area->contains($event->destination) ) {
          $gameScene->transferPlayer(
            new Location(
              $this->destinationMap,
              $this->spawnPoint,
              $this->spawnSprite
            )
          );
        }
      }
    }
  }
}