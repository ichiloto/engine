<?php

namespace Ichiloto\Engine\Scenes\Battle;

use Ichiloto\Engine\Battle\Interfaces\BattleEngineInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Interfaces\SceneConfigurationInterface;

/**
 * Represents the battle configuration.
 *
 * @package Ichiloto\Engine\Scenes\Battle
 */
class BattleConfig implements SceneConfigurationInterface
{
  /**
   * Creates a new instance of the battle configuration.
   *
   * @param Party $party The party of player characters.
   * @param Troop $troop The troop of enemies.
   * @param array $events The battle events.
   */
  public function __construct(
    protected(set) Party $party,
    protected(set) Troop $troop,
    protected(set) array $events = []
  )
  {
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
   * @inheritDoc
   */
  public function __toString(): string
  {
    return json_encode($this);
  }

  /**
   * Serializes the battle configuration.
   *
   * @return array<string, mixed> The serialized data.
   */
  public function __serialize(): array
  {
    return $this->getData();
  }

  /**
   * Deserializes the battle configuration.
   *
   * @param array $data The data to unserialize.
   * @return void
   */
  public function __unserialize(array $data): void
  {
    $this->setData($data);
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return $this->getData();
  }

  protected function getData(): array
  {
    return [
      'engine' => $this->engine,
      'party' => $this->party,
      'troop' => $this->troop,
      'events' => $this->events,
    ];
  }

  /**
   * Sets the data for the battle configuration.
   *
   * @param array $data The data to set.
   */
  protected function setData(array $data): void
  {
    foreach ($data as $key => $value) {
      if (property_exists($this, $key)) {
        $this->{$key} = $value;
      }
    }
  }
}