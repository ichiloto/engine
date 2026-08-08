<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Core\GameState;
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
   * @throws JsonException If the data cannot be serialized.
   */
  final public function __construct(
    protected(set) Rect $area,
    array $data = [],
    array $conditions = [],
    array $sets = [],
    ?string $mapId = null,
    ?string $marker = null,
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
   * - `['type' => 'quest',    'name' => 'quest-id', 'status' => 'completed'|'active']`
   *
   * @param array<string, mixed> $condition The condition entry.
   * @return bool True when the condition holds.
   */
  protected function evaluateCondition(array $condition): bool
  {
    $name = trim(strval($condition['name'] ?? ''));

    if ($name === '') {
      return true;
    }

    $result = match (strval($condition['type'] ?? '')) {
      'switch' => $this->gameState->getSwitch($name) === (bool) ($condition['value'] ?? true),
      'event' => $this->gameState->hasStoryEvent($name),
      'variable' => $this->compare(
        $this->gameState->getVariable($name),
        strval($condition['op'] ?? '=='),
        $condition['value'] ?? 0
      ),
      'item' => ($this->party?->inventory?->getQuantityByName($name) ?? 0) >= max(1, intval($condition['quantity'] ?? 1)),
      'quest' => QuestManager::current()?->questStatusMatches($name, strval($condition['status'] ?? 'completed')) ?? false,
      default => true,
    };

    return ($condition['negate'] ?? false) ? ! $result : $result;
  }

  /**
   * Compares a variable value against an expectation.
   *
   * @param int|float|string $actual The stored value.
   * @param string $operator The comparison operator.
   * @param mixed $expected The expected value.
   * @return bool True when the comparison holds.
   */
  private function compare(int|float|string $actual, string $operator, mixed $expected): bool
  {
    return match ($operator) {
      '!=' => $actual != $expected,
      '>' => $actual > $expected,
      '>=' => $actual >= $expected,
      '<' => $actual < $expected,
      '<=' => $actual <= $expected,
      default => $actual == $expected,
    };
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

    foreach ($this->sets as $set) {
      $name = trim(strval($set['name'] ?? ''));

      if ($name === '') {
        continue;
      }

      match (strval($set['type'] ?? '')) {
        'switch' => $this->gameState->setSwitch($name, (bool) ($set['value'] ?? true)),
        'event' => $this->gameState->recordStoryEvent($name),
        'variable' => strval($set['op'] ?? 'set') === 'add'
          ? $this->gameState->addToVariable($name, is_numeric($set['value'] ?? 1) ? $set['value'] + 0 : 1)
          : $this->gameState->setVariable($name, $set['value'] ?? 0),
        'quest' => QuestManager::current()?->acceptQuest($name),
        default => null,
      };
    }

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