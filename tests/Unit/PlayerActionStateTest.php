<?php

use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;

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
  public function getQueuedTroopTargets(): array
  {
    return $this->queuedTroopTargets;
  }

  public function getFocusedTroopIndex(): ?int
  {
    return $this->focusedTroopIndex;
  }

  public function isBlinkingTroopFocus(): bool
  {
    return $this->blinkFocusedTroop;
  }
}

class BattleScreenTargetingTestProxy extends BattleScreen
{
  public string $lastAlert = '';

  public function refreshField(): void
  {
    // Field refresh is not needed for this state-only unit test.
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
  $reflectionProperty->setAccessible(true);
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

  setTestProperty($screen, 'commandWindow', $commandWindow);
  setTestProperty($screen, 'characterNameWindow', $characterNameWindow);
  setTestProperty($screen, 'commandContextWindow', $commandContextWindow);
  setTestProperty($screen, 'fieldWindow', $fieldWindow);

  return $screen;
}

it('queues the player action against the selected target and keeps a queued target marker visible', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 120, attack: 14, defence: 8, speed: 8)));
  $party->addMember(new Character('Liora', 0, new Stats(currentHp: 110, attack: 12, defence: 9, speed: 7)));

  $slimeA = createTargetingTestEnemy('Slime A');
  $slimeB = createTargetingTestEnemy('Slime B');
  $troop = new Troop('Slimes', [$slimeA, $slimeB]);
  $screen = createTargetingTestScreen();

  $engine = new TraditionalTurnBasedBattleEngine($game);
  $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen));

  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $firstTurn = new Turn($party->battlers->toArray()[0]);
  $secondTurn = new Turn($party->battlers->toArray()[1]);
  $context->setTurns([$firstTurn, $secondTurn]);

  $state = new PlayerActionStateTestProxy($engine);
  $state->setActiveCharacterIndexForTest(0);
  $state->loadCharacterActionsForTest($context);
  $state->beginSubmenuSelectionForTest($context);
  $state->selectSubmenuOptionForTest($context);
  $state->cycleTargetForTest($context, 1);

  /** @var BattleFieldWindowTargetingTestProxy $fieldWindow */
  $fieldWindow = $screen->fieldWindow;

  expect($fieldWindow->getFocusedTroopIndex())->toBe(1)
    ->and($fieldWindow->isBlinkingTroopFocus())->toBeTrue();

  $state->queueActionForActiveCharacterForTest($context);

  expect($firstTurn->targets)->toHaveCount(1)
    ->and($firstTurn->targets[0])->toBe($slimeB)
    ->and($screen->lastAlert)->toContain('Slime B')
    ->and($fieldWindow->getQueuedTroopTargets())->toBe([1 => 1])
    ->and($fieldWindow->getFocusedTroopIndex())->toBeNull();
});
