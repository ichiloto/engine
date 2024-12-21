<?php

namespace Ichiloto\Engine\Entities;

use Exception;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Item\Item;
use Ichiloto\Engine\Entities\Inventory\Weapon;
use InvalidArgumentException;

/**
 * The Character class.
 *
 * @package Ichiloto\Engine\Entities
 */
class Character implements CharacterInterface
{
  /**
   * The maximum level.
   */
  const int DEFAULT_MAX_LEVEL = 100;

  /**
   * @var bool Whether the character is knocked out.
   */
  public bool $isKnockedOut {
    get {
      return $this->stats->currentHp > 0;
    }
  }

  /**
   * @var array The experience point thresholds for each level.
   */
  protected array $levelExpThresholds = [];

  /**
   * @var int The character's level.
   */
  public int $level {
    get {
      foreach ($this->levelExpThresholds as $level => $expThreshold) {
        if ($this->currentExp < $expThreshold) {
          return clamp($level - 1, 1, $this->maxLevel);
        }
      }

      return $this->maxLevel;
    }
  }

  /**
   * @var int The experience points required to reach the next level.
   */
  public int $nextLevelExp {
    get {
      # If maxed out, return 0.
      if ($this->level === $this->maxLevel) {
        return 0;
      }

      $nextLevelExp = $this->levelExpThresholds[$this->level + 1] ?? 0;
      return max(0, $nextLevelExp - $this->currentExp);
    }
  }

  /**
   * Character constructor.
   *
   * @param string $name The character's name.
   * @param int $currentExp The character's current experience points.
   * @param Stats $stats The character's stats.
   * @param CharacterSprites $images The character's images.
   * @param string $nickname The character's nickname.
   * @param object $job The character's job.
   * @param int $maxLevel The character's maximum level.
   * @param string $bio The character's biography.
   * @param string $note The character's note.
   * @param EquipmentSlot[] $equipment The character's equipment.
   */
  public function __construct(
    protected(set) string $name,
    protected(set) int $currentExp {
      set {
        if ($value < 0) {
          throw new InvalidArgumentException('Experience points cannot be negative.');
        }

        $this->currentExp = $value;
      }
    },
    protected(set) Stats $stats,
    protected(set) CharacterSprites $images = new CharacterSprites(),
    protected(set) string $nickname = '',
    public object $job = new \stdClass(),
    protected(set) int $maxLevel = self::DEFAULT_MAX_LEVEL,
    protected(set) string $bio = '',
    protected(set) string $note = '',
    protected(set) array $equipment = []
  )
  {
    $this->calculateLevelExpThresholds();
    if (!$this->equipment) {
      $this->equipment = [
        new EquipmentSlot('Weapon', "The actor's primary weapon", '⚔️', Weapon::class),
        new EquipmentSlot('Shield', "The actor's primary shield", '🛡️', Armor::class),
        new EquipmentSlot('Head', "The actor's head gear", '🛡️', Armor::class),
        new EquipmentSlot('Body', "The actor's body armor", '🛡️', Armor::class),
        new EquipmentSlot('Accessory', "The actor's special accessory", '📿', Accessory::class),
      ];
    }
  }

  /**
   * Calculates the experience point thresholds for each level.
   *
   * @return void
   */
  protected function calculateLevelExpThresholds(): void
  {
    for ($level = 0; $level <= $this->maxLevel; $level++) {
      $this->levelExpThresholds[$level] = $level === 1 ? 0 : pow($level - 1, 2) * 100;
    }
  }

  /**
   * Creates a character instance from an array.
   *
   * @param array $data The character data.
   * @return Character The character instance.
   */
  public static function fromArray(array $data): self
  {
    return new Character(
        $data['name'] ?? throw new InvalidArgumentException('Character name is required.'),
        $data['currentExp'] ?? throw new InvalidArgumentException('Current experience points are required.'),
        Stats::fromArray($data['stats'] ?? throw new InvalidArgumentException('Character stats are required.')),
        CharacterSprites::fromArray($data['images'] ?? [])
    );
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the user.
   */
  public function equip(Weapon|Accessory|Armor $item): void
  {
    // TODO: Implement equip() method.
    if (! $this->canEquip($item) ) {
      alert(sprintf('%s cannot be equipped.', $item->name));
      return;
    }

    foreach ($this->equipment as $slot) {
      if ($slot->acceptsType === $item::class) {
        $slot->item = $item;
        alert(sprintf("Equipped %s on %s", $item->name, $this->name));
        return;
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function canEquip(InventoryItem $item): bool
  {
    $canEquip = false;

    if ($item instanceof Item) {
      return false;
    }

    foreach ($this->equipment as $slot) {
      if ($slot->acceptsType === $item::class) {
        $canEquip = true;
        break;
      }
    }

    return $canEquip;
  }

  /**
   * @param InventoryItem $item
   * @param int $quantity
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the user.
   */
  public function use(InventoryItem $item, int $quantity = 1): void
  {
    if (! $this->canUseItem($item) ) {
      alert(sprintf('%s cannot be used.', $item->name));
      return;
    }

    assert($item instanceof Item);
    for ($uses = 0; $uses < $quantity; $uses++) {
      if ($item->quantity < 1) {
        alert(sprintf('%s is out of stock.', $item->name));
        return;
      }

      foreach ($item->effects as $effect) {
        $effect->apply($this);
      }

      $item->quantity--;
    }
    alert(sprintf("Used %s on %s", $item->name, $this->name));
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
}