<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldStateWriter;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerInterface;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

/**
 * The EventTrigger class.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
abstract class EventTrigger implements EventTriggerInterface
{
  /**
   * @var object The data.
   */
  protected(set) object $data;
  /**
   * @var bool Whether the trigger is reusable.
   */
  protected(set) bool $isReusable = true;
  /**
   * @var bool Whether the trigger is completed.
   */
  protected bool $completed = false;
  /**
   * @var bool Whether the trigger is complete.
   */
  public bool $isComplete {
    get {
      return !$this->isReusable && $this->completed;
    }
  }
  /**
   * @var array<int, array<string, mixed>> The world-state conditions gating this trigger.
   */
  protected(set) array $conditions = [];
  /**
   * @var array<int, array<string, mixed>> The world-state writes applied on completion.
   */
  protected(set) array $sets = [];
  /**
   * @var string|null The id of the map that owns this trigger.
   */
  protected(set) ?string $mapId = null;
  /**
   * @var string|null The event marker that identifies this trigger on its map.
   */
  protected(set) ?string $marker = null;
  /**
   * @var GameState|null The bound world state.
   */
  protected ?GameState $gameState = null;
  /**
   * @var Party|null The bound party (for item conditions).
   */
  protected ?Party $party = null;

  /**
   * EventTrigger constructor.
   *
   * @param Rect $area The trigger area. The area on the map where the trigger is activated.
   * @param array $data The data.
   * @param array<int, array<string, mixed>> $conditions World-state conditions that must hold for the trigger to be active.
   * @param array<int, array<string, mixed>> $sets World-state writes applied when the trigger completes.
   * @param string|null $mapId The owning map's id.
   * @param string|null $marker The trigger's event marker on that map.
   * @param string|null $whenBlocked Message shown when the player enters the
   * area while the conditions do not hold. Without one a gated trigger is
   * simply absent, which is indistinguishable from a bug.
   * @throws JsonException If the data cannot be serialized.
   */
  final public function __construct(
    protected(set) Rect $area,
    array $data = [],
    array $conditions = [],
    array $sets = [],
    ?string $mapId = null,
    ?string $marker = null,
    protected(set) ?string $whenBlocked = null,
  )
  {
    $serializedData = json_encode($data, JSON_THROW_ON_ERROR);
    $this->data = json_decode($serializedData) ?? throw new RuntimeException('Failed to parse trigger data.');
    $this->conditions = array_values(array_filter($conditions, 'is_array'));
    $this->sets = array_values(array_filter($sets, 'is_array'));
    $this->mapId = $mapId !== null && trim($mapId) !== '' ? trim($mapId) : null;
    $this->marker = $marker !== null && trim($marker) !== '' ? trim($marker) : null;
    $this->configure();
  }

  /**
   * Binds the world state (and optionally the party) used to evaluate
   * conditions and apply completion writes.
   *
   * @param GameState $gameState The world state.
   * @param Party|null $party The party, for item conditions.
   * @return void
   */
  public function bind(GameState $gameState, ?Party $party = null): void
  {
    $this->gameState = $gameState;
    $this->party = $party;
  }

  /**
   * Determines whether every declared condition currently holds.
   *
   * A trigger with no conditions, or one that has not been bound to a world
   * state, is always available.
   *
   * @return bool True when the trigger may activate.
   */
  public function isAvailable(): bool
  {
    if ($this->conditions === [] || $this->gameState === null) {
      return true;
    }

    foreach ($this->conditions as $condition) {
      if (! $this->evaluateCondition($condition)) {
        return false;
      }
    }

    return true;
  }

  /**
   * Restores a persisted completion without re-applying completion writes.
   *
   * Used when a map loads and the world state already records this trigger
   * as completed.
   *
   * @return void
   */
  public function restoreCompleted(): void
  {
    $this->completed = true;
  }

  /**
   * Evaluates one condition entry against the bound state.
   *
   * Supported shapes (all accept `'negate' => true` to invert the result):
   * - `['type' => 'switch',   'name' => 'x', 'value' => true]`
   * - `['type' => 'event',    'name' => 'story_flag']`
   * - `['type' => 'variable', 'name' => 'n', 'op' => '>=', 'value' => 5]`
   * - `['type' => 'item',     'name' => 'Rusty Key', 'quantity' => 1]`
   * - `['type' => 'key_item', 'name' => 'Rusty Key']`
   * - `['type' => 'quest',    'name' => 'quest-id', 'status' => 'completed'|'active']`
   *
   * @param array<string, mixed> $condition The condition entry.
   * @return bool True when the condition holds.
   */
  protected function evaluateCondition(array $condition): bool
  {
    return WorldConditionEvaluator::allHold([$condition], $this->gameState, $this->party);
  }

  /**
   * Applies the declared completion writes to the bound world state and
   * persists one-shot completion.
   *
   * Supported set shapes:
   * - `['type' => 'switch',   'name' => 'x', 'value' => true]`
   * - `['type' => 'event',    'name' => 'story_flag']`
   * - `['type' => 'variable', 'name' => 'n', 'op' => 'set'|'add', 'value' => 1]`
   * - `['type' => 'quest',    'name' => 'quest-id']` — accepts the quest
   *
   * @return void
   */
  protected function applyCompletionState(): void
  {
    if ($this->gameState === null) {
      return;
    }

    WorldStateWriter::applyAll($this->sets, $this->gameState);

    if (! $this->isReusable && $this->mapId !== null && $this->marker !== null) {
      $this->gameState->markEventComplete($this->mapId, $this->marker);
    }
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    // Do nothing
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
    Debug::info("Trigger entered: " . get_class($this) . " at " . $context->coordinates);
  }

  /**
   * @inheritDoc
   */
  public function stay(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
    Debug::info("Trigger stayed: " . get_class($this) . " at " . $context->coordinates);
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    // Do nothing. This method should be overridden by the subclass.
    Debug::info("Trigger exited: " . get_class($this) . " at " . $context->coordinates);
  }

  /**
   * @inheritDoc
   */
  public function complete(): void
  {
    $this->completed = true;
    $this->applyCompletionState();
  }
}