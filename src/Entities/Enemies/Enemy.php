<?php

namespace Ichiloto\Engine\Entities\Enemies;

use Exception;
use Ichiloto\Engine\Battle\BattleRewards;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Stats;

/**
 * Class Enemy
 *
 * @package Ichiloto\Engine\Entities\Enemies
 */
class Enemy implements CharacterInterface
{
  public bool $isKnockedOut {
    get {
      return $this->stats->currentHp <= 0;
    }
  }

  /**
   * @var ActionPattern[] The action patterns of the enemy.
   */
  protected(set) array $actionPatterns = [];
  /**
   * @var array The image of the enemy.
   */
  protected(set) array $image = [];

  /**
   * Enemy constructor.
   *
   * @param string $name
   * @param int $level
   * @param Stats $stats
   * @param string $imagePath
   * @param BattleRewards $rewards
   * @param ActionPattern[] $actionPatters
   */
  public function __construct(
    protected(set) string $name,
    protected(set) int $level,
    protected(set) Stats $stats,
    protected(set) string $imagePath,
    protected(set) BattleRewards $rewards,
    array $actionPatters,
    protected(set) Vector2 $position = new Vector2()
  )
  {
    foreach ($actionPatters as $pattern) {
      if ($pattern instanceof ActionPattern) {
        $this->actionPatterns[] = $pattern;
      }
    }

    $this->image = graphics("Enemies/$imagePath.txt");
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs when trying to alert the user.
   */
  public function use(InventoryItem $item, int $quantity = 1): void
  {
    if (! $this->canUseItem($item) ) {
      alert(sprintf('%s cannot be used', $item->name));
      return;
    }

    if ($item instanceof Item) {
      if ($item->quantity < 1) {
        alert('%s is out of stock', $item->name);
        return;
      }

      foreach ($item->effects as $effect) {
        $effect->apply($this);
      }

      $item->quantity--;
    }
    alert(sprintf('Used %s on %s', $item->name, $this->name));
  }

  /**
   * @inheritDoc
   */
  public function canUseItem(InventoryItem $item): bool
  {
    if ($item instanceof Weapon) {
      return false;
    }

    if ($item instanceof Armor) {
      return false;
    }

    if ($item instanceof Accessory) {
      return false;
    }

    return true;
  }

  /**
   * @inheritDoc
   */
  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'level' => $this->level,
      'stats' => $this->stats,
      'imagePath' => $this->imagePath,
      'image' => $this->image,
      'rewards' => $this->rewards,
      'actionPatterns' => $this->actionPatterns,
    ];
  }

  /**
   * @inheritDoc
   */
  public function serialize(): string
  {
    return json_encode($this);
  }

  /**
   * @inheritDoc
   */
  public function unserialize(string $data): void
  {
    $this->bindDataToProperties(json_decode($data, true));
  }

  public function __serialize(): array
  {
    return $this->jsonSerialize();
  }

  public function __unserialize(array $data): void
  {
    $this->bindDataToProperties($data);
  }

  /**
   * @inheritDoc
   */
  public function jsonSerialize(): array
  {
    return $this->toArray();
  }
  /**
   * Bind data to the character's properties.
   *
   * @param array $data The data to bind.
   */
  protected function bindDataToProperties(array $data): void
  {
    foreach ($data as $key => $value) {
      if (property_exists($this, $key)) {
        $this->{$key} = match($key) {
          'stats' => is_array($value) ? Stats::fromArray($value) : $value,
          default => $value
        };
      }
    }
  }
}