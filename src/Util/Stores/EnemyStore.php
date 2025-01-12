<?php

namespace Ichiloto\Engine\Util\Stores;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;

/**
 * Represents the enemy store.
 *
 * @package Ichiloto\Engine\Util\Stores
 */
class EnemyStore implements ConfigInterface
{
  /**
   * @var array<string, Enemy> The enemies.
   */
  protected array $enemies = [];

  /**
   * Creates a new instance of the enemy store.
   */
  public function __construct()
  {
    $enemies = asset('Data/enemies.php');

    foreach ($enemies as $enemy) {
      if ($enemy instanceof Enemy) {
        $key = $enemy->name;
        $this->enemies[$key] = $enemy;
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function get(string $path, mixed $default = null): mixed
  {
    if (!$default instanceof Enemy) {
      $default = null;
    }

    $copy = clone $this->enemies[$path] ?? $default;
    return $copy;
  }

  /**
   * @inheritDoc
   */
  public function set(string $path, mixed $value): void
  {
    if (! $value instanceof Enemy) {
      throw new InvalidArgumentException('The value must be an instance of ' . Enemy::class);
    }

    $this->enemies[$path] = $value;
  }

  /**
   * @inheritDoc
   */
  public function has(string $path): bool
  {
    return isset($this->enemies[$path]);
  }

  /**
   * @inheritDoc
   */
  public function persist(): void
  {
    // Do nothing
  }

  /**
   * Loads a list of enemies.
   *
   * @param array<array{enemy: string, position: int[]} $data
   * @return Enemy[] The loaded enemies.
   * @throws NotFoundException If the enemy store is not found.
   * @throws RequiredFieldException If a required field is missing.
   */
  public function load(array $data): array
  {
    $enemies = [];
    $enemyStore = ConfigStore::get(EnemyStore::class);

    if (! $enemyStore instanceof EnemyStore) {
      throw new NotFoundException(EnemyStore::class);
    }

    foreach ($data as $datum) {
      $enemyName = $datum['enemy'] ?? throw new RequiredFieldException('enemy');
      $enemyPosition = $datum['position'] ?? throw new RequiredFieldException('position');

      if (! is_array($enemyPosition) ) {
        throw new InvalidArgumentException('The position must be an array.');
      }

      $enemyPosition = Vector2::fromArray($enemyPosition);

      /** @var Enemy $enemy */
      if ($enemy = $enemyStore->get($enemyName)) {
        $enemy->position->x = $enemyPosition->x;
        $enemy->position->y = $enemyPosition->y;
        $enemies[] = $enemy;
      }
    }

    return $enemies;
  }
}