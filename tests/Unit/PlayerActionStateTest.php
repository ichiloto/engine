<?php

use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\States\ActiveTimeFlowState;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\PlayerActionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
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
use Ichiloto\Engine\Entities\Enemies\ActionCondition;
use Ichiloto\Engine\Entities\Enemies\ActionPattern;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
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

class BattleCharacterStatusWindowTargetingTestProxy extends BattleCharacterStatusWindow
{
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

it('keeps authored enemy formations out of the player-party render zone', function () {
  $fieldWindow = (new ReflectionClass(BattleFieldWindowTargetingTestProxy::class))->newInstanceWithoutConstructor();
  setTestProperty($fieldWindow, 'partyBattlerPositions', new PartyBattlerPositions());

  $wolf = createTargetingTestEnemy('Wolf');
  setTestProperty($wolf, 'image', [str_repeat('W', 31)]);
  setTestProperty($wolf, 'position', new Vector2(99, 7));

  $safePosition = $fieldWindow->resolveTroopIdlePositionForTest($wolf);
  $partyZoneLeft = $fieldWindow->getPartyZoneLeftForTest();

  expect($safePosition->x)->toBe(62.0)
    ->and($safePosition->x + BattleFieldWindow::TROOP_STEP_X_OFFSET + 31)->toBeLessThanOrEqual(
      $partyZoneLeft - BattleFieldWindow::BATTLE_SIDE_GAP,
    )
    ->and($fieldWindow->getTroopAvailableWidthForTest($safePosition))->toBe(34);

  setTestProperty($wolf, 'position', new Vector2(10, 7));

  expect($fieldWindow->resolveTroopIdlePositionForTest($wolf)->x)->toBe(10.0);
});

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

it('shows helpful info for the focused battle command and submenu option', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 120, attack: 14, defence: 8, speed: 8)));

  $slime = createTargetingTestEnemy('Slime A');
  $troop = new Troop('Slimes', [$slime]);
  $screen = createTargetingTestScreen();

  $engine = new TraditionalTurnBasedBattleEngine($game);
  $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen));

  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $context->setTurns([new Turn($party->battlers->toArray()[0])]);

  $state = new PlayerActionStateTestProxy($engine);
  $state->setActiveCharacterIndexForTest(0);
  $state->loadCharacterActionsForTest($context);
  $state->showFocusedInfoForTest($context);

  expect($screen->lastAlert)->toContain('physical attack');

  $state->beginSubmenuSelectionForTest($context);
  $state->showFocusedInfoForTest($context);

  expect($screen->lastAlert)->toContain('Strike a single enemy')
    ->and($screen->recomposeCount)->toBe(2);
});

class ActiveTimePlayerActionStateProxy extends PlayerActionState
{
  public function setActiveCharacterIndexForTest(int $index): void
  {
    $this->activeCharacterIndex = $index;
  }

  public function selectNextCharacterForTest(TurnStateExecutionContext $context): void
  {
    $this->selectNextCharacter($context);
  }
}

it('returns control to the active-time flow when there is no enemy phase to hand to', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 120, attack: 14, defence: 8, speed: 8)));

  $troop = new Troop('Lake', [createTargetingTestEnemy('Lochness Monster')]);
  $screen = createTargetingTestScreen();

  // The active-time engine drives every battler from its flow state, so it has
  // no separate player and enemy phases at all.
  $engine = new ActiveTimeBattleEngine($game);
  $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen));

  expect($engine->enemyActionState)->toBeNull();

  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $context->setTurns([new Turn($party->battlers->toArray()[0])]);

  $state = new ActiveTimePlayerActionStateProxy($engine);
  $state->setActiveCharacterIndexForTest(0);

  // Running out of characters to ask, which a failed escape does, used to hand
  // off to a null enemy phase and crash the battle.
  expect(fn() => $state->selectNextCharacterForTest($context))->not->toThrow(Throwable::class);
});

class ActiveTimeBattleEngineCaptureProxy extends ActiveTimeBattleEngine
{
  public ?CharacterInterface $capturedBattler = null;
  public ?BattleAction $capturedAction = null;
  /** @var CharacterInterface[] */
  public array $capturedTargets = [];

  public function queueImmediateTurn(
    TurnStateExecutionContext $context,
    CharacterInterface $battler,
    BattleAction $action,
    array $targets,
  ): void
  {
    $this->capturedBattler = $battler;
    $this->capturedAction = $action;
    $this->capturedTargets = $targets;
  }
}

class ActiveTimeFlowStateTestProxy extends ActiveTimeFlowState
{
  public function setActiveCharacterIndexForTest(int $index): void
  {
    $this->activeCharacterIndex = $index;
  }

  public function queueGuardForActiveCharacterForTest(TurnStateExecutionContext $context): void
  {
    $this->queueGuardForActiveCharacter($context);
  }

  public function executeEnemyTurnForTest(TurnStateExecutionContext $context, Enemy $enemy): void
  {
    $this->executeEnemyTurn($context, $enemy);
  }
}

it('advances active-time rounds whenever the flow cycles', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 120, speed: 8)));
  $troop = new Troop('Lake', [createTargetingTestEnemy('Lochness Monster')]);
  $screen = createTargetingTestScreen();
  $engine = new ActiveTimeBattleEngineCaptureProxy($game);
  $engine->configure(new ActiveTimeBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $state = new ActiveTimeFlowStateTestProxy($engine);

  $state->enter($context);
  $state->enter($context);

  expect($context->roundNumber)->toBe(2);
});

it('queues Guard immediately for a ready active-time battler', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $kaelion = new Character('Kaelion', 0, new Stats(currentHp: 120, attack: 14, defence: 8, speed: 8));
  $party->addMember($kaelion);
  $troop = new Troop('Lake', [createTargetingTestEnemy('Lochness Monster')]);
  $screen = createTargetingTestScreen();
  $engine = new ActiveTimeBattleEngineCaptureProxy($game);
  $engine->configure(new ActiveTimeBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $state = new ActiveTimeFlowStateTestProxy($engine);
  $state->setActiveCharacterIndexForTest(0);

  $state->queueGuardForActiveCharacterForTest($context);

  expect($engine->capturedBattler)->toBe($kaelion)
    ->and($engine->capturedAction)->toBeInstanceOf(GuardAction::class)
    ->and($engine->capturedTargets)->toBe([$kaelion]);
});

it('queues an authored enemy action in active-time battles', function () {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $party = new Party();
  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 120, attack: 14, defence: 8, speed: 8)));
  $enemy = createTargetingTestEnemy('Great Wolf');
  $howl = new BasicSkill(
    'Savage Howl',
    '',
    '',
    0,
    0,
    new ItemScope(ItemScopeSide::USER),
    Occasion::BATTLE_SCREEN,
    new SkillInvocation(),
  );
  setTestProperty($enemy, 'actionPatterns', [new ActionPattern($howl, 6, new ActionCondition())]);
  $troop = new Troop('Wolves', [$enemy]);
  $screen = createTargetingTestScreen();
  $engine = new ActiveTimeBattleEngineCaptureProxy($game);
  $engine->configure(new ActiveTimeBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $context->roundNumber = 1;
  $state = new ActiveTimeFlowStateTestProxy($engine);

  $state->executeEnemyTurnForTest($context, $enemy);

  expect($engine->capturedBattler)->toBe($enemy)
    ->and($engine->capturedAction)->toBeInstanceOf(SkillBattleAction::class)
    ->and($engine->capturedAction?->name)->toBe('Savage Howl')
    ->and($engine->capturedTargets)->toBe([$enemy]);
});
