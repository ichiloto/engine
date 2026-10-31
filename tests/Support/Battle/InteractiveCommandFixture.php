<?php

declare(strict_types=1);

namespace Tests\Support\Battle;

use ReflectionClass;
use ReflectionObject;
use RuntimeException;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Stats;


/**
 * Shared interactive battle-command windows and subjects. Terminal painting is
 * suppressed; command selection, held controls, targeting and queueing use the
 * production state. Engine and game integration tests share this single source.
 * Requiring this file registers no tests and launches no renderer or audio.
 */
class BattleCommandWindowTargetingTestProxy extends BattleCommandWindow
{
  public function updateContent(): void
  {
    // Skip terminal rendering for battle target-selection tests.
  }
}

class BattleCharacterNameWindowTargetingTestProxy extends BattleCharacterNameWindow
{
  public function updateContent(): void
  {
    // Skip terminal rendering for battle target-selection tests.
  }
}

class BattleCharacterStatusWindowTargetingTestProxy extends BattleCharacterStatusWindow
{
  public function updateContent(): void
  {
    // These tests exercise state transitions, not terminal painting.
  }

  public function setAtbPercentages(array $atbPercentages): void
  {
    // Skip terminal rendering for active-time flow tests.
  }
}

class BattleCommandContextWindowTargetingTestProxy extends BattleCommandContextWindow
{
  public function updateContent(): void
  {
    // Skip terminal rendering for battle target-selection tests.
  }

  public function clear(): void
  {
    $this->items = [];
    $this->activeIndex = -1;
    $this->scrollOffset = 0;
    $this->blinkActiveSelection = false;
    $this->titleBase = '';
    $this->emptyMessage = '';
    $this->setTitle('');
    $this->setHelp('');
    $this->setContent(array_fill(0, self::HEIGHT - 2, ''));
  }
}

class BattleFieldWindowTargetingTestProxy extends BattleFieldWindow
{
  public array $renderedFocus = [];

  protected function renderTroopFocusMarker(Enemy $battler, bool $blink): void
  {
    $this->renderedFocus[] = $battler;
  }

  protected function renderPartyFocusMarker(Character $battler, int $index, bool $blink): void
  {
    $this->renderedFocus[] = $battler;
  }

  public function getQueuedTroopTargets(): array
  {
    return $this->queuedTroopTargets;
  }

  public function getFocusedTroopIndex(): ?int
  {
    return $this->focusedTroopIndexes[0] ?? null;
  }

  public function getFocusedIndexes(ItemScopeSide $side): array
  {
    return $side === ItemScopeSide::ALLY ? $this->focusedPartyIndexes : $this->focusedTroopIndexes;
  }

  public function isBlinkingTroopFocus(): bool
  {
    return $this->blinkFocusedTroop;
  }

  public function resolveTroopIdlePositionForTest(Enemy $battler): Vector2
  {
    return $this->getTroopIdlePosition($battler);
  }

  public function getPartyZoneLeftForTest(): int
  {
    return $this->getPartyZoneLeft();
  }

  public function getTroopAvailableWidthForTest(Vector2 $position): int
  {
    return $this->getTroopAvailableWidth($position);
  }
}

class BattleScreenTargetingTestProxy extends BattleScreen
{
  public string $lastAlert = '';
  public int $recomposeCount = 0;

  public function refreshField(): void
  {
    // Field refresh is not needed for this state-only unit test.
  }

  public function recomposeField(): void
  {
    $this->recomposeCount++;
  }

  public function setState(\Ichiloto\Engine\Battle\UI\States\BattleScreenState $state): void
  {
  }

  public function hideMessage(): void
  {
  }

  public function refresh(): void
  {
  }

  public function alert(string $text): void
  {
    $this->lastAlert = $text;
  }
}

class GameTargetingTestProxy extends Game
{
  public function __destruct()
  {
    // Avoid full terminal teardown for isolated battle-state tests.
  }
}

class PlayerActionStateTestProxy extends PlayerActionState
{
  public function setActiveCharacterIndexForTest(int $index): void
  {
    $this->activeCharacterIndex = $index;
  }

  public function loadCharacterActionsForTest(TurnStateExecutionContext $context): void
  {
    $this->loadCharacterActions($context);
  }

  public function beginSubmenuSelectionForTest(TurnStateExecutionContext $context): void
  {
    $this->beginSubmenuSelection($context);
  }

  public function selectSubmenuOptionForTest(TurnStateExecutionContext $context): void
  {
    $this->selectSubmenuOption($context);
  }

  public function cycleTargetForTest(TurnStateExecutionContext $context, int $step): void
  {
    $this->cycleTarget($context, $step);
  }

  public function queueActionForActiveCharacterForTest(TurnStateExecutionContext $context): void
  {
    $this->queueActionForActiveCharacter($context);
  }

  public function showFocusedInfoForTest(TurnStateExecutionContext $context): void
  {
    $this->showFocusedInfo($context);
  }
}

function setTestProperty(object $object, string $property, mixed $value): void
{
  $reflection = new ReflectionObject($object);

  while (! $reflection->hasProperty($property)) {
    $reflection = $reflection->getParentClass();

    if (! $reflection) {
      throw new RuntimeException("Property {$property} not found.");
    }
  }

  $reflectionProperty = $reflection->getProperty($property);
  $reflectionProperty->setValue($object, $value);
}

function createTargetingTestEnemy(string $name): Enemy
{
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();

  setTestProperty($enemy, 'name', $name);
  setTestProperty($enemy, 'level', 1);
  setTestProperty($enemy, 'stats', new Stats(currentHp: 30, attack: 4, defence: 2, speed: 1));
  setTestProperty($enemy, 'imagePath', '');
  setTestProperty($enemy, 'image', ['@']);
  setTestProperty($enemy, 'position', new Vector2(10, 5));

  return $enemy;
}

function createTargetingTestScreen(): BattleScreenTargetingTestProxy
{
  $screen = (new ReflectionClass(BattleScreenTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $commandWindow = (new ReflectionClass(BattleCommandWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $characterNameWindow = (new ReflectionClass(BattleCharacterNameWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $commandContextWindow = (new ReflectionClass(BattleCommandContextWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $fieldWindow = (new ReflectionClass(BattleFieldWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $characterStatusWindow = (new ReflectionClass(BattleCharacterStatusWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();

  setTestProperty($screen, 'commandWindow', $commandWindow);
  setTestProperty($screen, 'characterNameWindow', $characterNameWindow);
  setTestProperty($screen, 'commandContextWindow', $commandContextWindow);
  setTestProperty($screen, 'fieldWindow', $fieldWindow);
  setTestProperty($screen, 'characterStatusWindow', $characterStatusWindow);
  setTestProperty(
    $screen,
    'playerActionState',
    new \Ichiloto\Engine\Battle\UI\States\PlayerActionState($screen),
  );

  return $screen;
}

