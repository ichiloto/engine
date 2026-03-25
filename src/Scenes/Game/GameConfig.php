<?php

namespace Ichiloto\Engine\Scenes\Game;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;

/**
 * Class GameConfig. Represents the configuration of a game.
 *
 * @package Ichiloto\Engine\Scenes\Game
 */
class GameConfig implements SceneConfigurationInterface
{
  /**
   * GameConfig constructor.
   *
   * @param string $mapId The ID of the map.
   * @param Party $party The party.
   * @param Vector2 $playerPosition The position of the player.
   * @param Rect $playerShape The size of the player.
   * @param MovementHeading $playerHeading The heading of the player.
   * @param array $playerStats The stats of the player.
   * @param array $events The events of the game.
   * @param array $playerSprite The sprite of the player.
   * @param array<string, string[]> $playerSprites The directional player sprites.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   */
  public function __construct(
    protected(set) string $mapId,
    protected(set) Party $party,
    protected(set) Vector2 $playerPosition,
    protected(set) Rect $playerShape,
    protected(set) MovementHeading $playerHeading,
    protected(set) array $playerStats = [],
    protected(set) array $events = [],
    protected(set) array $playerSprite = ['v'],
    protected(set) array $playerSprites = [],
    protected(set) int $playTimeSeconds = 0,
  )
  {
  }

  /**
   * @inheritDoc
   */
  public function __toString(): string
  {
    return json_encode($this->getData());
  }

  /**
   * @inheritDoc
   */
  public function serialize(): ?string
  {
    return serialize($this->getData());
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    $this->setData(unserialize($data));
  }

  /**
   * Method called during serialization.
   *
   * @return array The data of the game configuration.
   */
  public function __serialize(): array
  {
    return $this->getData();
  }

  /**
   * Method called during deserialization.
   *
   * @param array $data The data of the game configuration.
   * @return void
   */
  public function __unserialize(array $data): void
  {
    $this->setData($data);
  }

  /**
   * Returns the data of the game configuration.
   *
   * @return array{mapId: string, party: Party, playerPosition: Vector2, playerShape: Rect, playerHeading: MovementHeading, playerStats: array, events: array, playerSprite: array, playerSprites: array<string, string[]>, playTimeSeconds: int}
   */
  protected function getData(): array
  {
    return [
      'mapId' => $this->mapId,
      'party' => $this->party,
      'playerPosition' => $this->playerPosition,
      'playerShape' => $this->playerShape,
      'playerHeading' => $this->playerHeading,
      'playerStats' => $this->playerStats,
      'events' => $this->events,
      'playerSprite' => $this->playerSprite,
      'playerSprites' => $this->playerSprites,
      'playTimeSeconds' => $this->playTimeSeconds,
    ];
  }

  /**
   * @param array{mapId: string, party?: Party, playerPosition: Vector2, playerShape?: Rect, playerSize?: Rect, playerHeading: MovementHeading, playerStats: array, events: array, playerSprite?: array, playerSprites?: array<string, string[]>, playTimeSeconds?: int} $data
   * @return void
   */
  protected function setData(array $data): void
  {
    foreach ($data as $key => $value) {
      if ($key === 'playerSize') {
        $key = 'playerShape';
      }

      $this->$key = $value;
    }
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return $this->getData();
  }
}
