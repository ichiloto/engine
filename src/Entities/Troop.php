<?php

namespace Ichiloto\Engine\Entities;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Battle\BattleClassification;
use Ichiloto\Engine\Battle\EscapePolicy;
use Ichiloto\Engine\Battle\Events\BattleEventPage;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandRegistry;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\GroupInterface;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use InvalidArgumentException;
use RuntimeException;
use SplObjectStorage;

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
  /** Stable project-authored identity, when one exists. */
  protected(set) ?string $definitionId = null;
  /** Explicit typed classification; omission defaults to ordinary. */
  protected(set) BattleClassification $classification = BattleClassification::ORDINARY;

  /**
   * The battle music this troop declares (e.g. a boss theme), or null to use
   * the project-wide battle theme.
   *
   * @var string|null
   */
  protected(set) ?string $backgroundMusic = null;

  /**
   * An optional authored retreat rule. Null preserves the project default.
   */
  protected(set) ?EscapePolicy $escapePolicy = null;
  /** @var SplObjectStorage<Enemy, ?BattleStageSlot> Places follow instances, never names or mutable roster indices. */
  private SplObjectStorage $graphicalFormation;
  /** @var SplObjectStorage<Enemy, \Ichiloto\Engine\Battle\Formation\BattlePosition>|null */
  private ?SplObjectStorage $battlePositions = null;
  /**
   * @var list<BattleEventPage> The troop's event pages, which name members by their index in the troop.
   */
  protected(set) array $eventPages = [];
  private ?string $graphicalFormationError = null;

  /**
   * Creates a new troop.
   *
   * @param string $name The name of the troop.
   * @param array|null $enemies The enemies in the troop.
   * @param list<BattleEventPage> $eventPages The troop's validated event pages.
   * @param array $config The configuration of the troop.
   * @param string|null $backgroundMusic The troop's battle music, or null to
   *   use the project-wide battle theme.
   * @param EscapePolicy|null $escapePolicy The troop's optional retreat rule.
   */
  public function __construct(
    protected(set) string $name,
    ?array $enemies = null,
    array $eventPages = [],
    array $config = [],
    ?string $backgroundMusic = null,
    ?EscapePolicy $escapePolicy = null,
    ?string $definitionId = null,
    BattleClassification $classification = BattleClassification::ORDINARY,
    array $graphicalFormation = [],
    array $battlePositions = [],
  )
  {
    $backgroundMusic = is_string($backgroundMusic) ? trim($backgroundMusic) : '';
    $this->backgroundMusic = $backgroundMusic === '' ? null : $backgroundMusic;
    $this->escapePolicy = $escapePolicy;
    $definitionId = is_string($definitionId) ? trim($definitionId) : '';
    $this->definitionId = $definitionId === '' ? null : $definitionId;
    $this->classification = $classification;
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
    if (!array_is_list($graphicalFormation) || count($graphicalFormation) > 64
      || ($graphicalFormation !== [] && count($graphicalFormation) !== count($this->members))) {
      throw new InvalidArgumentException('Troop graphical formation must provide one stage place or null per enemy instance, at most 64.');
    }
    foreach ($eventPages as $page) {
      if (! $page instanceof BattleEventPage) {
        throw new InvalidArgumentException('Troop event pages must be validated BattleEventPage entries.');
      }
    }
    $this->eventPages = array_values($eventPages);
    $this->graphicalFormation = new SplObjectStorage();
    if (!array_is_list($battlePositions) || ($battlePositions !== [] && count($battlePositions) !== count($this->members))) {
      throw new InvalidArgumentException('Troop battle positions require one logical position or null per enemy instance.');
    }
    $this->battlePositions = new SplObjectStorage();
    \Ichiloto\Engine\Battle\Formation\BattlePosition::validateDistinct($battlePositions);
    foreach ($this->members as $index => $enemy) {
      $slot = $graphicalFormation[$index] ?? null;
      if ($slot !== null && !$slot instanceof BattleStageSlot) {
        throw new InvalidArgumentException('Troop graphical placements must be BattleStageSlot entries or null.');
      }
      $this->graphicalFormation[$enemy] = $slot;
      $place = $battlePositions[$index] ?? null;
      if ($place !== null && !$place instanceof \Ichiloto\Engine\Battle\Formation\BattlePosition) {
        throw new InvalidArgumentException('Troop battle positions must be logical positions or null.');
      }
      if ($place !== null) { $this->battlePositions[$enemy] = $place; }
    }
  }

  public function getBattlePosition(Enemy $enemy): ?\Ichiloto\Engine\Battle\Formation\BattlePosition
  {
    return $this->battlePositions?->offsetExists($enemy) ? $this->battlePositions[$enemy] : null;
  }

  /**
   * Returns the enemy at an authored member index, as event pages name it.
   */
  public function getMember(int $index): ?Enemy
  {
    $member = $this->members->toArray()[$index] ?? null;

    return $member instanceof Enemy ? $member : null;
  }

  /**
   * The member's authored place in the troop's graphical formation, or null
   * when nobody has placed it: the battle then arranges it beside the others.
   */
  public function getStagePlace(Enemy $enemy): ?BattleStageSlot
  {
    if ($this->graphicalFormationError !== null) {
      throw new RuntimeException($this->graphicalFormationError);
    }
    return $this->graphicalFormation->offsetExists($enemy) ? $this->graphicalFormation[$enemy] : null;
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
  public static function fromArray(array $data, string $source = 'troop data'): self
  {
    $enemiesStore = ConfigStore::get(EnemyStore::class);

    $name = $data['name'] ?? throw new RequiredFieldException('name');
    if ($source === 'troop data') { $source .= sprintf(' troop "%s"', $name); }
    $enemyDataList = $data['enemies'] ?? throw new RequiredFieldException('enemies');
    $events = $data['events'] ?? [];

    $enemies = [];
    $formation = [];
    $battlePositions = [];
    $formationErrors = [];

    foreach ($enemyDataList as $index => $enemyData) {
      $slot = null;
      if (array_key_exists('graphicalPlacement', $enemyData)) {
        try {
          $slot = BattleStageSlot::fromArray($enemyData['graphicalPlacement'], "{$source}.enemies[{$index}].graphicalPlacement");
        } catch (InvalidArgumentException $error) {
          // Refuse only optional graphics, preserving the authored combat and terminal positions.
          $formationErrors[] = $error->getMessage();
          Debug::warn('Invalid troop graphical formation: ' . $error->getMessage());
        }
      }
      $enemy = $enemiesStore->get($enemyData['enemy'] ?? throw new RequiredFieldException('enemy'));
      if (!$enemy instanceof Enemy) {
        if ($events !== []) {
          // Event pages name members by index, so every member must resolve.
          throw new InvalidArgumentException(sprintf('%s.enemies[%d] names unknown enemy "%s"; its event pages cannot name members.',
            $source, $index, strval($enemyData['enemy'])));
        }
        continue;
      }

      $enemyClone = clone $enemy;
      $enemyPosition = Vector2::fromArray($enemyData['position'] ?? []);
      $enemyClone->position->x = $enemyPosition->x;
      $enemyClone->position->y = $enemyPosition->y;
      $floor = $enemyData['knockoutFloorPercent'] ?? 0;
      if (!is_int($floor) || $floor < 0 || $floor > 99) {
        throw new InvalidArgumentException(sprintf('%s.enemies[%d].knockoutFloorPercent must be a whole number from 0 to 99.', $source, $index));
      }
      $enemyClone->setKnockoutFloorPercent($floor);

      $enemies[] = $enemyClone;
      $formation[] = $slot;
      $battlePositions[] = array_key_exists('battlePosition', $enemyData)
        ? \Ichiloto\Engine\Battle\Formation\BattlePosition::fromArray($enemyData['battlePosition'], "{$source}.enemies[{$index}].battlePosition") : null;
    }

    if (count($formation) > 64) {
      $error = "{$source}: graphical formation exceeds 64 enemy placements; terminal troop preserved.";
      $formationErrors[] = $error;
      Debug::warn($error);
      $formation = [];
    }

    $backgroundMusic = $data['bgm'] ?? null;
    $escapePolicy = array_key_exists('escapePolicy', $data)
      ? EscapePolicy::resolve($data['escapePolicy'])
      : null;
    $classification = BattleClassification::resolve($data['classification'] ?? null, $source);

    $eventPages = BattleEventPage::readPages($events, $source, count($enemies), ScriptCommandRegistry::getCatalog());
    $troop = new self(
      $name,
      $enemies,
      $eventPages,
      backgroundMusic: is_string($backgroundMusic) ? $backgroundMusic : null,
      escapePolicy: $escapePolicy,
      definitionId: is_string($data['id'] ?? null) ? $data['id'] : null,
      classification: $classification,
      graphicalFormation: $formation,
      battlePositions: $battlePositions,
    );
    $troop->graphicalFormationError = $formationErrors === [] ? null : implode('; ', $formationErrors);
    return $troop;
  }
}
