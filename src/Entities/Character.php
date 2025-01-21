<?php

namespace Ichiloto\Engine\Entities;

use Exception;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Entities\Interfaces\CanEquip;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Roles\Role;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;

/**
 * The Character class.
 *
 * @package Ichiloto\Engine\Entities
 */
class Character implements CharacterInterface, CanEquip
{
  /**
   * The maximum level.
   */
  const int DEFAULT_MAX_LEVEL = 100;

  protected(set) int $maxLevel = self::DEFAULT_MAX_LEVEL;

    /**
   * @var bool Whether the character is knocked out. This is when the character's HP is 0.
   */
  public bool $isKnockedOut {
    get {
      return ! $this->isConscious;
    }
  }

  /**
   * @var bool Whether the character is conscious. This is when the character's HP is greater than 0.
   */
  public bool $isConscious {
    get {
      return $this->stats->currentHp > 0;
    }
  }
  /**
   * @var bool Whether the character is wounded. This is when the character's HP is less than their total HP.
   */
  public bool $isWounded {
    get {
      return $this->stats->currentHp < $this->stats->totalHp;
    }
  }
  /**
   * @var bool Whether the character is critical. This is when the character's HP is less than 25% of their total HP.
   */
  public bool $isCritical {
    get {
      return $this->stats->currentHp < $this->stats->totalHp / 4;
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
   * @var int The character's current experience points.
   */
  protected(set) int $currentExp {
    set {
      if ($value < 0) {
        throw new InvalidArgumentException('Experience points cannot be negative.');
      }

      $this->currentExp = $value;
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
   * @var Stats The character's effective stats.
   */
  public Stats $effectiveStats {
    get {
      return $this->stats->getEffectiveStats($this);
    }
  }

  /**
   * @var BattleAction[] The character's command abilities.
   */
  public array $commandAbilities {
    get {
      return [
        new AttackAction('Attack'),
        new AttackAction('Magic'),
        new AttackAction('Summon'),
        new AttackAction('Item'),
      ];
    }
  }
  /**
   * @var array The character's equipment.
   */
  protected(set) array $equipment = [];
    /**
   * @var Role The character's role.
   */
  public Role $role;
  /**
   * @var int[] $totalHpCurve
   */
  protected(set) array $totalHpCurve = [];
  /**
   * @var int[] $totalMpCurve
   */
  protected(set) array $totalMpCurve = [];
  /**
   * @var int[] $totalApCurve
   */
  protected(set) array $totalApCurve = [];
  /**
   * @var int[] $attackCurve
   */
  protected(set) array $attackCurve = [];
  /**
   * @var int[] $defenceCurve
   */
  protected(set) array $defenceCurve = [];
  /**
   * @var int[] $magicAttackCurve
   */
  protected(set) array $magicAttackCurve = [];
  /**
   * @var int[] $magicDefenceCurve
   */
  protected(set) array $magicDefenceCurve = [];
  /**
   * @var int[] $speedCurve
   */
  protected(set) array $speedCurve = [];
  /**
   * @var int[] $graceCurve
   */
  protected(set) array $graceCurve = [];
  /**
   * @var int[] $evasionCurve
   */
  protected(set) array $evasionCurve = [];

  /**
   * Character constructor.
   *
   * @param string $name The character's name.
   * @param int $currentExp The character's current experience points.
   * @param Stats $stats The character's stats.
   * @param CharacterSprites $images The character's images.
   * @param string $nickname The character's nickname.
   * @param int $maxLevel The character's maximum level.
   * @param string $bio The character's biography.
   * @param string $note The character's note.
   * @param EquipmentSlot[] $equipment The character's equipment.
   */
  public function __construct(
    protected(set) string $name,
    int $currentExp,
    protected(set) Stats $stats,
    protected(set) CharacterSprites $images = new CharacterSprites(),
    protected(set) string $nickname = '',
    int $maxLevel = self::DEFAULT_MAX_LEVEL,
    protected(set) string $bio = '',
    protected(set) string $note = '',
    array $equipment = [],
    ?Role $role = null
  )
  {
    $this->maxLevel = $maxLevel;
    $this->currentExp = $currentExp;
    $this->equipment = $equipment;
    if (!$role) {
      $role = new Role($this, 'Hero');
    }

    $this->role = $role;
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
    $this->generateParameterCurves();
    $this->adjustStatTotals();
  }

  /**
   * Calculates the experience point thresholds for each level.
   *
   * @return void
   */
  protected function calculateLevelExpThresholds(): void
  {
    $this->levelExpThresholds = $this->role->experienceCurveGenerator->generateCurve();
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
  public function equip(?Equipment $equipment): void
  {
    if (is_null($equipment)) {
      alert('No equipment to equip.');
      return;
    }

    if (! $this->canEquip($equipment) ) {
      alert(sprintf('%s cannot be equipped.', $equipment->name));
      return;
    }

    foreach ($this->equipment as $slot) {
      if ($slot->acceptsType === $equipment::class) {
        $slot->equipment = $equipment;
        $this->adjustStatTotals($equipment);
        alert(sprintf("Equipped %s on %s", $equipment->name, $this->name));
        return;
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function unequip(EquipmentSlot $slot): void
  {
    foreach ($this->equipment as $equipmentSlot) {
      if ($equipmentSlot->name === $slot->name) {
        $equipmentSlot->equipment = null;
        $this->adjustStatTotals($equipmentSlot->equipment);
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

  /**
   * @return void
   * @throws Exception
   */
  public function clearEquipment(): void
  {
    foreach ($this->equipment as $equipmentSlot) {
      $equipmentSlot->equipment = null;
    }
    $this->adjustStatTotals();
    alert('Equipment cleared!');
  }

  /**
   * Optimizes the character's equipment.
   *
   * @param Inventory $inventory The character's inventory.
   * @return void
   * @throws Exception If an error occurs while alerting the user.
   */
  public function optimizeEquipment(Inventory $inventory): void
  {
    // Optimization algorithm will be simple for now. We will just equip the best equipment available.
    foreach ($this->equipment as $equipmentSlot) {
      $optimalEquipment = null;

      foreach ($inventory->equipment as $index => $equipment) {
        if ($index === 0) {
          $optimalEquipment = $equipment;
          continue;
        }

        $optimalEquipment = Equipment::getBetterRated($optimalEquipment, $equipment);
      }

      $equipmentSlot->equipment = $optimalEquipment;
    }
    alert('Equipment optimized!');
  }

  /**
   * Adjusts the character's stat totals after equipping an item.
   *
   * @param Equipment|null $equipment The equipment being equipped.
   * @return void
   */
  protected function adjustStatTotals(?Equipment $equipment = null): void
  {
    $this->stats->totalHp      = $this->totalHpCurve[$this->level] ?? 0;
    $this->stats->totalMp      = $this->totalMpCurve[$this->level] ?? 0;
    $this->stats->attack       = $this->attackCurve[$this->level] ?? 0;
    $this->stats->defence      = $this->defenceCurve[$this->level] ?? 0;
    $this->stats->magicAttack  = $this->magicAttackCurve[$this->level] ?? 0;
    $this->stats->magicDefence = $this->magicDefenceCurve[$this->level] ?? 0;
    $this->stats->evasion      = $this->evasionCurve[$this->level] ?? 0;
    $this->stats->grace        = $this->graceCurve[$this->level] ?? 0;
    $this->stats->speed        = $this->speedCurve[$this->level] ?? 0;

    if ($equipment) {
      $this->stats->totalHp       += ($equipment->parameterChanges->totalHp ?? 0);
      $this->stats->totalMp       += ($equipment->parameterChanges->totalMp ?? 0);
      $this->stats->attack        += ($equipment->parameterChanges->attack ?? 0);
      $this->stats->defence       += ($equipment->parameterChanges->defence ?? 0);
      $this->stats->magicAttack   += ($equipment->parameterChanges->magicAttack ?? 0);
      $this->stats->magicDefence  += ($equipment->parameterChanges->magicDefence ?? 0);
      $this->stats->evasion       += ($equipment->parameterChanges->evasion ?? 0);
      $this->stats->grace         += ($equipment->parameterChanges->grace ?? 0);
      $this->stats->speed         += ($equipment->parameterChanges->speed ?? 0);
    }
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
          'images' => is_array($value) ? CharacterSprites::fromArray($value) : $value,
          'stats' => is_array($value) ? Stats::fromArray($value) : $value,
          default => $value
        };
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function toArray(): array
  {
    return [
      'name' => $this->name,
      'currentExp' => $this->currentExp,
      'stats' => $this->stats,
      'images' => $this->images,
      'nickname' => $this->nickname,
      'maxLevel' => $this->maxLevel,
      'bio' => $this->bio,
      'note' => $this->note,
      'equipment' => $this->equipment,
      'role' => $this->role
    ];
  }

  /**
   * Generates the parameter curves for the character.
   *
   * @return void
   */
  protected function generateParameterCurves(): void
  {
    $this->totalHpCurve = $this->role->totalHpCurveGenerator->generateCurve();
    $this->totalMpCurve = $this->role->totalMpCurveGenerator->generateCurve();
    $this->attackCurve = $this->role->totalAttackCurveGenerator->generateCurve();
    $this->defenceCurve = $this->role->totalDefenceCurveGenerator->generateCurve();
    $this->magicAttackCurve = $this->role->totalMagicAttackCurveGenerator->generateCurve();
    $this->magicDefenceCurve = $this->role->totalMagicDefenceCurveGenerator->generateCurve();
    $this->speedCurve = $this->role->totalSpeedCurveGenerator->generateCurve();
    $this->graceCurve = $this->role->totalGraceCurveGenerator->generateCurve();
    $this->evasionCurve = $this->role->totalEvasionCurveGenerator->generateCurve();
    $this->adjustStatTotals();
  }

  /**
   * Adds experience points to the character.
   *
   * @param int $exp The experience points to add.
   * @return void
   */
  public function addExperience(int $exp): void
  {
    $this->currentExp += $exp;
    $this->adjustStatTotals();
  }
}