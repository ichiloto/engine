<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\GroupInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\EnemyStore;

/**
 * Represents a group of enemies in a battle.
 *
 * @package Ichiloto\Engine\Entities
 * @extends BattleGroup<Enemy>
 */
class Troop extends BattleGroup
{
  protected static int $count = 0;
  /**
   * @var int The ID of the troop.
   */
  protected(set) int $id = 0;

  /**
   * Creates a new troop.
   *
   * @param string $name The name of the troop.
   * @param array|null $enemies The enemies in the troop.
   * @param array $events The events of the troop.
   * @param array $config The configuration of the troop.
   */
  public function __construct(
    protected string $name,
    ?array $enemies = null,
    protected array $events = [],
    array $config = []
  )
  {
    self::$count++;
    $this->id = self::$count;

    parent::__construct($config);

    if ($enemies) {
      foreach ($enemies as $enemy) {
        if ($enemy instanceof Enemy) {
          $this->members->add($enemy);
        }
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function configure(array $config = []): void
  {
    // Do nothing
  }

  /**
   * Instantiates a troop from an array.
   *
   * @param array $data The data to instantiate the troop from.
   * @return self
   * @throws RequiredFieldException If a required field is missing.
   */
  public static function fromArray(array $data): self
  {
    $enemiesStore = ConfigStore::get(EnemyStore::class);

    $name = $data['name'] ?? throw new RequiredFieldException('name');
    $enemyDataList = $data['enemies'] ?? throw new RequiredFieldException('enemies');
    $events = $data['events'] ?? [];

    $enemies = [];

    foreach ($enemyDataList as $enemyData) {
      $enemy = $enemiesStore->get($enemyData['enemy'] ?? throw new RequiredFieldException('enemy'));
      if (!$enemy instanceof Enemy) {
        continue;
      }

      $enemyClone = clone $enemy;
      $enemyPosition = Vector2::fromArray($enemyData['position'] ?? []);
      $enemyClone->position->x = $enemyPosition->x;
      $enemyClone->position->y = $enemyPosition->y;

      Debug::log('Enemy position: ' . $enemyClone->position);
      $enemies[] = $enemyClone;
    }

    return new self($name, $enemies, $events);
  }
}