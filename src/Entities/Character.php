<?php

namespace Ichiloto\Engine\Entities;

use Exception;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Interfaces\CanEquip;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Accessory;
use Ichiloto\Engine\Entities\Inventory\Armor;
use Ichiloto\Engine\Entities\Inventory\Equipment;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\States\HasStates;
use Ichiloto\Engine\Entities\States\HasStatStages;
use Ichiloto\Engine\Entities\States\StateInstance;
use Ichiloto\Engine\Entities\States\StateRegistry;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ClassStore;
use Ichiloto\Engine\Exceptions\PersistentStateRestoreException;
use Ichiloto\Engine\IO\SaveCompatibility\SaveHydrationContext;
use InvalidArgumentException;

/**
 * The Character class.
 *
 * @package Ichiloto\Engine\Entities
 */
class Character implements CharacterInterface, CanEquip
{
  use HasStates;
  use HasStatStages;

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
   * Raw character data retained while save aliases and tombstones are being
   * resolved. It is never included in a newly written save.
   *
   * @var array<string, mixed>|null
   */
  private ?array $deferredSaveData = null;

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
        new AttackAction(BattleCommandType::ATTACK->label()),
        new AttackAction(BattleCommandType::SKILL->label()),
        new AttackAction(BattleCommandType::MAGIC->label()),
        new AttackAction(BattleCommandType::SUMMON->labelForRole($this->role->name)),
        new AttackAction(BattleCommandType::ITEM->label()),
        new AttackAction(BattleCommandType::GUARD->label()),
        new AttackAction(BattleCommandType::ESCAPE->label()),
      ];
    }
  }
  /**
   * @var array The character's equipment.
   */
  protected(set) array $equipment = [];
  /**
   * @var string[] The ids of the summons assigned to this character.
   */
  protected(set) array $summons = [];
  /**
   * @var AbilityBook The character's managed ability data.
   */
  protected(set) AbilityBook $abilityBook;
  /**
   * @var Spellbook The character's managed magic data.
   */
  protected(set) Spellbook $spellbook;
    /**
   * @var CharacterRole The character's role.
   */
  public CharacterRole $role;
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
   * @param AbilityBook|null $abilityBook The character's ability book.
   * @param Spellbook|null $spellbook The character's spellbook.
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
    ?CharacterRole $role = null,
    ?AbilityBook $abilityBook = null,
    ?Spellbook $spellbook = null,
  )
  {
    $this->maxLevel = $maxLevel;
    $this->currentExp = $currentExp;
    $this->equipment = $equipment;
    $this->abilityBook = $abilityBook ?? new AbilityBook();
    $this->spellbook = $spellbook ?? new Spellbook();
    if (!$role) {
      $role = new CharacterRole($this, 'Hero');
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
    // The class name lives inside the actor's `data` block. A `role` entry
    // may instead hold a hydrated CharacterRole (older saves), so only a
    // string names a class here.
    $className = is_string($data['class'] ?? null)
      ? trim($data['class'])
      : (is_string($data['role'] ?? null) ? trim($data['role']) : '');
    $character = new Character(
      $data['name'] ?? throw new InvalidArgumentException('Character name is required.'),
      $data['currentExp'] ?? throw new InvalidArgumentException('Current experience points are required.'),
      Stats::fromArray($data['stats'] ?? throw new InvalidArgumentException('Character stats are required.')),
      CharacterSprites::fromArray($data['images'] ?? []),
      $data['nickname'] ?? '',
      intval($data['maxLevel'] ?? self::DEFAULT_MAX_LEVEL),
      strval($data['bio'] ?? $data['description'] ?? ''),
      strval($data['note'] ?? ''),
      is_array($data['equipment'] ?? null) ? $data['equipment'] : [],
      (($data['role'] ?? null) instanceof CharacterRole) ? $data['role'] : null,
      AbilityBook::fromArray(
        is_array($data['abilities'] ?? null)
          ? $data['abilities']
          : (is_array($data['abilityBook'] ?? null) ? $data['abilityBook'] : [])
      ),
      Spellbook::fromArray(
        is_array($data['magic'] ?? null)
          ? $data['magic']
          : (is_array($data['spellbook'] ?? null) ? $data['spellbook'] : [])
      ),
    );

    // Actors reference a class by name (`'class' => 'Vanguard'`); the role
    // is built once the character exists, since its curves are seeded from
    // that character's level and stats.
    if ($className !== '') {
      $character->applyClass($className);
    }

    foreach (array_filter(is_array($data['summons'] ?? null) ? $data['summons'] : [], 'is_string') as $summonId) {
      $character->assignSummon($summonId);
    }

    return $character;
  }

  /**
   * Applies an authored character class, replacing this character's role
   * with the class's curves, traits, and level-gated skill grants.
   *
   * @param string $className The class name from `assets/Data/classes.php`.
   * @return bool True when the class was found and applied.
   */
  public function applyClass(string $className): bool
  {
    $role = ClassStore::createRole($className, $this);

    if ($role === null) {
      Debug::warn(sprintf('Unknown character class: %s', $className));
      return false;
    }

    $this->role = $role;
    $this->calculateLevelExpThresholds();
    $this->generateParameterCurves();
    $this->adjustStatTotals();

    return true;
  }

  /**
   * @inheritDoc
   * @throws Exception If an error occurs while alerting the user.
   */
  public function equip(Equipment $equipment): void
  {
    if (! $this->canEquip($equipment) ) {
      alert(sprintf('%s cannot be equipped.', $equipment->name));
      return;
    }

    foreach ($this->equipment as $slot) {
      if ($slot->acceptsType === $equipment::class) {
        $this->equipInSlot($slot, $equipment);
        return;
      }
    }
  }

  /**
   * Equips an item into a specific slot.
   *
   * @param EquipmentSlot $slot The slot to equip into.
   * @param Equipment $equipment The equipment to place in the slot.
   * @return void
   * @throws Exception If the equipment cannot be equipped.
   */
  public function equipInSlot(EquipmentSlot $slot, Equipment $equipment): void
  {
    if (! $this->canEquip($equipment) || $slot->acceptsType !== $equipment::class) {
      alert(sprintf('%s cannot be equipped.', $equipment->name));
      return;
    }

    foreach ($this->equipment as $equipmentSlot) {
      if ($equipmentSlot->name !== $slot->name) {
        continue;
      }

      $equipmentSlot->equipment = $equipment;
      $this->adjustStatTotals();
      alert(sprintf("Equipped %s on %s", $equipment->name, $this->name));
      return;
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
        $this->adjustStatTotals();
        return;
      }
    }
  }

  /**
   * Assigns a summon to this character.
   *
   * Eligibility and tenancy rules live on the party — use
   * {@see Party::assignSummon()} when those rules should be enforced.
   *
   * @param string $summonId The summon id to assign.
   * @return void
   */
  public function assignSummon(string $summonId): void
  {
    $summonId = strtolower(trim($summonId));

    if ($summonId === '' || $this->hasSummon($summonId)) {
      return;
    }

    $this->summons[] = $summonId;
  }

  /**
   * Removes a summon assignment from this character.
   *
   * @param string $summonId The summon id to remove.
   * @return void
   */
  public function unassignSummon(string $summonId): void
  {
    $summonId = strtolower(trim($summonId));
    $this->summons = array_values(array_filter(
      $this->summons,
      static fn(string $assigned): bool => $assigned !== $summonId
    ));
  }

  /**
   * Determines whether this character currently holds the given summon.
   *
   * @param string $summonId The summon id to check.
   * @return bool True when the summon is assigned to this character.
   */
  public function hasSummon(string $summonId): bool
  {
    return in_array(strtolower(trim($summonId)), $this->summons, true);
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

    // A class may restrict which weapon/armor types its members can wear.
    if ($item instanceof Equipment && ! $this->role->allowsEquipmentType($item->equipmentType)) {
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
   * Learns a skill, filing it in the book that owns its kind.
   *
   * A character keeps magic in their spellbook and everything else in their
   * ability book: the two are stored, serialized and surfaced in the UI
   * separately. Routing here keeps every caller — level-up grants, events,
   * items — from having to know that rule, and stops a spell being misfiled
   * as an ability (where it would never reach the magic menu and would break
   * saving).
   *
   * @param Skill $skill The skill to learn.
   * @return bool True when newly learned; false when already known.
   */
  public function learnSkill(Skill $skill): bool
  {
    if ($skill instanceof MagicSkill) {
      return $this->spellbook->learnSpellDirectly($skill);
    }

    return $this->abilityBook->learnSkillDirectly($skill);
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
   */
  public function clearEquipment(): void
  {
    foreach ($this->equipment as $equipmentSlot) {
      $equipmentSlot->equipment = null;
    }
    $this->adjustStatTotals();
  }

  /**
   * Optimizes the character's equipment.
   *
   * Each slot is filled with the highest-rated compatible equipment that is
   * still available, i.e. not already worn by another party member.
   *
   * @param Inventory $inventory The party's inventory.
   * @param Party|null $party The party, used to respect equipment worn by other members.
   * @return void
   */
  public function optimizeEquipment(Inventory $inventory, ?Party $party = null): void
  {
    // Release this character's gear first so it competes for slots on merit.
    foreach ($this->equipment as $equipmentSlot) {
      $equipmentSlot->equipment = null;
    }

    $assignedCounts = [];

    foreach ($this->equipment as $equipmentSlot) {
      $optimalEquipment = null;

      foreach ($inventory->equipment as $equipment) {
        assert($equipment instanceof Equipment);

        if (! is_a($equipment, $equipmentSlot->acceptsType)) {
          continue;
        }

        $equipmentKey = $equipment::class . ':' . $equipment->name;
        $availableQuantity = $party
          ? $party->getAvailableEquipmentQuantity($equipment)
          : $equipment->quantity;
        $availableQuantity -= $assignedCounts[$equipmentKey] ?? 0;

        if ($availableQuantity < 1) {
          continue;
        }

        if (! $optimalEquipment || $equipment->rating > $optimalEquipment->rating) {
          $optimalEquipment = $equipment;
        }
      }

      if ($optimalEquipment) {
        $equipmentSlot->equipment = $optimalEquipment;
        $optimalKey = $optimalEquipment::class . ':' . $optimalEquipment->name;
        $assignedCounts[$optimalKey] = ($assignedCounts[$optimalKey] ?? 0) + 1;
      }
    }

    $this->adjustStatTotals();
  }

  /**
   * Adjusts the character's stat totals after equipping an item.
   *
   * @return void
   */
  protected function adjustStatTotals(): void
  {
    $curveLevel = $this->resolveCurveLevel($this->level);

    $this->stats->totalHp      = ($this->totalHpCurve[$curveLevel] ?? 0) + $this->getEquipmentTotalHpBonus();
    $this->stats->totalMp      = ($this->totalMpCurve[$curveLevel] ?? 0) + $this->getEquipmentTotalMpBonus();
    $this->stats->attack       = $this->attackCurve[$curveLevel] ?? 0;
    $this->stats->defence      = $this->defenceCurve[$curveLevel] ?? 0;
    $this->stats->magicAttack  = $this->magicAttackCurve[$curveLevel] ?? 0;
    $this->stats->magicDefence = $this->magicDefenceCurve[$curveLevel] ?? 0;
    $this->stats->evasion      = $this->evasionCurve[$curveLevel] ?? 0;
    $this->stats->grace        = $this->graceCurve[$curveLevel] ?? 0;
    $this->stats->speed        = $this->speedCurve[$curveLevel] ?? 0;

    // Re-apply the clamps after a level or equipment refresh.
    $this->stats->currentHp = $this->stats->currentHp;
    $this->stats->currentMp = $this->stats->currentMp;
    $this->stats->currentAp = $this->stats->currentAp;
  }

  /**
   * Resolves the safest shared curve level for all stat curves.
   *
   * @param int $preferredLevel The desired level.
   * @return int
   */
  protected function resolveCurveLevel(int $preferredLevel): int
  {
    $curveMaxLevels = [];

    foreach ([
      $this->totalHpCurve,
      $this->totalMpCurve,
      $this->attackCurve,
      $this->defenceCurve,
      $this->magicAttackCurve,
      $this->magicDefenceCurve,
      $this->evasionCurve,
      $this->graceCurve,
      $this->speedCurve,
    ] as $curve) {
      if (!$curve) {
        continue;
      }

      $curveMaxLevels[] = max(array_keys($curve));
    }

    if (!$curveMaxLevels) {
      return max(1, $preferredLevel);
    }

    return min(max(1, $preferredLevel), min($curveMaxLevels));
  }

  /**
   * Returns the total HP bonus granted by currently equipped gear.
   *
   * @return int
   */
  protected function getEquipmentTotalHpBonus(): int
  {
    $bonus = 0;

    foreach ($this->equipment as $equipmentSlot) {
      $bonus += $equipmentSlot->equipment?->parameterChanges->totalHp ?? 0;
    }

    return $bonus;
  }

  /**
   * Returns the total MP bonus granted by currently equipped gear.
   *
   * @return int
   */
  protected function getEquipmentTotalMpBonus(): int
  {
    $bonus = 0;

    foreach ($this->equipment as $equipmentSlot) {
      $bonus += $equipmentSlot->equipment?->parameterChanges->totalMp ?? 0;
    }

    return $bonus;
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
    $this->rehydrateDerivedState();
  }

  public function __serialize(): array
  {
    return $this->jsonSerialize();
  }

  public function __unserialize(array $data): void
  {
    if (SaveHydrationContext::shouldDeferCharacters()) {
      $this->deferredSaveData = $data;
      return;
    }

    $this->bindDataToProperties($data);
    $this->rehydrateDerivedState();
  }

  /**
   * Returns the raw save array retained during compatibility processing.
   *
   * @return array<string, mixed>|null
   */
  public function getDeferredSaveData(): ?array
  {
    return $this->deferredSaveData;
  }

  /**
   * Completes Character hydration after content compatibility has been
   * applied to the raw save array.
   *
   * @param array<string, mixed>|null $data The compatibility-normalized data.
   */
  public function completeDeferredSaveHydration(?array $data = null): void
  {
    $data ??= $this->deferredSaveData;

    if (! is_array($data)) {
      return;
    }

    $this->deferredSaveData = null;
    $this->bindDataToProperties($data);
    $this->rehydrateDerivedState();
  }

  /** Applies an explicit actor-identity alias to this restored character. */
  public function applySaveIdentity(string $name): void
  {
    $this->name = trim($name);
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
    $persistentStates = is_array($data['states'] ?? null) ? $data['states'] : [];

    foreach ($data as $key => $value) {
      if ($key === 'states') {
        continue;
      }

      if ($key === 'abilities' || $key === 'abilityBook') {
        $this->abilityBook = is_array($value)
          ? AbilityBook::fromArray($value)
          : ($value instanceof AbilityBook ? $value : new AbilityBook());
        continue;
      }

      if ($key === 'magic' || $key === 'spellbook') {
        $this->spellbook = is_array($value)
          ? Spellbook::fromArray($value)
          : ($value instanceof Spellbook ? $value : new Spellbook());
        continue;
      }

      if (property_exists($this, $key)) {
        $this->{$key} = match($key) {
          'images' => is_array($value) ? CharacterSprites::fromArray($value) : $value,
          'stats' => is_array($value) ? Stats::fromArray($value) : $value,
          default => $value
        };
      }
    }

    $this->restorePersistentStates($persistentStates);
  }

  /**
   * Rebuilds derived state that is intentionally omitted from serialization.
   *
   * @return void
   */
  protected function rehydrateDerivedState(): void
  {
    $hasInvalidSavedVitals = isset($this->stats) && $this->stats->totalHp <= 0;

    if (! isset($this->abilityBook)) {
      $this->abilityBook = new AbilityBook();
    }

    if (! isset($this->spellbook)) {
      $this->spellbook = new Spellbook();
    }

    if (! isset($this->role) || ! $this->role instanceof CharacterRole) {
      $this->role = new CharacterRole($this, 'Hero');
    }

    $this->calculateLevelExpThresholds();
    $this->generateParameterCurves();

    if ($hasInvalidSavedVitals) {
      // Recover legacy saves created while the level-cap bug had zeroed the stored vital totals.
      $this->stats->currentHp = $this->stats->totalHp;
      $this->stats->currentMp = $this->stats->totalMp;
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
      'summons' => $this->summons,
      'role' => $this->role,
      'abilities' => $this->abilityBook->toArray(),
      'magic' => $this->spellbook->toArray(),
      'states' => array_map(
        static fn(StateInstance $instance): array => [
          'id' => $instance->state->id,
          'remainingTurns' => $instance->remainingTurns,
        ],
        array_values(array_filter(
          $this->states,
          static fn(StateInstance $instance): bool => $instance->state->persistsAfterBattle
        ))
      ),
    ];
  }

  /**
   * Restores only authored states whose definitions explicitly persist beyond
   * battle. Unknown saved state ids are compatibility failures, not objects to
   * silently invent or discard.
   *
   * @param array<int, mixed> $stateData
   */
  private function restorePersistentStates(array $stateData): void
  {
    $this->states = [];

    foreach ($stateData as $entry) {
      if (! is_array($entry)) {
        throw new PersistentStateRestoreException('A saved persistent-state entry is not an array.');
      }

      $stateId = trim(strval($entry['id'] ?? ''));
      $state = StateRegistry::get($stateId);

      if ($stateId === '' || $state === null) {
        throw new PersistentStateRestoreException(sprintf(
          'Persistent state "%s" cannot be restored because the project does not define it.',
          $stateId
        ));
      }

      if (! $state->persistsAfterBattle) {
        continue;
      }

      $remainingTurns = $entry['remainingTurns'] ?? null;

      if ($remainingTurns !== null && ! is_int($remainingTurns)) {
        throw new PersistentStateRestoreException(sprintf(
          'Persistent state "%s" has an invalid remainingTurns value.',
          $stateId
        ));
      }

      $this->states[] = new StateInstance($state, $remainingTurns);
    }
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
