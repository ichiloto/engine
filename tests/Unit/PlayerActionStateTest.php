<?php

use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\States\ActiveTimeFlowState;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\BattleCommandLoadout;
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
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Abilities\AbilityBook;
use Ichiloto\Engine\Entities\Magic\Spellbook;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope as InventoryItemScope;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Roles\CharacterRole;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Audio\AudioManager;
use Tests\Support\Input\FakeInputSource;

use Tests\Support\Battle\BattleCommandWindowTargetingTestProxy;
use Tests\Support\Battle\BattleCharacterNameWindowTargetingTestProxy;
use Tests\Support\Battle\BattleCharacterStatusWindowTargetingTestProxy;
use Tests\Support\Battle\BattleCommandContextWindowTargetingTestProxy;
use Tests\Support\Battle\BattleFieldWindowTargetingTestProxy;
use Tests\Support\Battle\BattleScreenTargetingTestProxy;
use Tests\Support\Battle\GameTargetingTestProxy;
use Tests\Support\Battle\PlayerActionStateTestProxy;
use function Tests\Support\Battle\setTestProperty;
use function Tests\Support\Battle\createTargetingTestEnemy;
use function Tests\Support\Battle\createTargetingTestScreen;

require_once __DIR__ . '/../Support/Battle/InteractiveCommandFixture.php';
require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

it('opens role-aware battle commands and routes renamed labels by identity in both battle engines', function (bool $activeTime) {
  $priorConfig = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  putSceneAudioConfig(['locale' => 'alternate', 'vocab' => [
    'command' => ['attack' => 'Magic', 'magic' => 'Attack', 'summon_by_role' => ['Synthetic Role' => 'Petition']],
    'alternate' => ['command' => ['summon_by_role' => ['Synthetic Role' => 'Entreat']]],
  ]]);
  try {
    $game = new ReflectionClass(GameTargetingTestProxy::class)->newInstanceWithoutConstructor();
    $party = new Party();
    $actor = new Character('Synthetic actor', 1, new Stats(currentHp: 100, currentMp: 10, totalMp: 10),
      spellbook: new Spellbook([new MagicSkill('Authored spell', '', '', 2, 0)]));
    $actor->role = new CharacterRole($actor, 'Synthetic Role');
    expect($actor->commandAbilities[3]->name)->toBe('Entreat')
      ->and($actor->commandAbilities[3]->type)->toBe(BattleCommandType::SUMMON);
    $party->addMember($actor);
    $troop = new Troop('Synthetic', [createTargetingTestEnemy('Enemy')]);
    $screen = createTargetingTestScreen();
    setTestProperty($screen->commandWindow, 'height', BattleCommandWindow::HEIGHT);
    $engine = $activeTime ? new ActiveTimeBattleEngine($game) : new TraditionalTurnBasedBattleEngine($game);
    $engine->configure($activeTime ? new ActiveTimeBattleConfig($party, $troop, $screen)
      : new TurnBasedBattleConfig($party, $troop, $screen));
    $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
    $context->setTurns([new Turn($actor)]);
    if ($activeTime) {
      $state = $engine->turnInitState;
      // This is the readiness -> command loading path from the reported crash.
      new ReflectionMethod($state, 'activateReadyCharacter')->invoke($state, $context, $actor);
    } else {
      $state = new PlayerActionStateTestProxy($engine);
      $state->setActiveCharacterIndexForTest(0);
      $state->loadCharacterActionsForTest($context);
    }
    expect($screen->commandWindow->getActiveCommand()->type)->toBe(BattleCommandType::ATTACK)
      ->and($screen->commandWindow->getActiveCommand()->name)->toBe('Magic');
    new ReflectionMethod($state, 'beginSubmenuSelection')->invoke($state, $context);
    expect($screen->commandContextWindow->getActiveItem()->type)->toBe(BattleCommandType::ATTACK);
    new ReflectionMethod($state, 'returnToCommandSelection')->invoke($state, $context);
    $screen->commandWindow->selectNext();
    $screen->commandWindow->selectNext();
    expect($screen->commandWindow->getActiveCommand()->type)->toBe(BattleCommandType::MAGIC)
      ->and($screen->commandWindow->getActiveCommand()->name)->toBe('Attack');
    new ReflectionMethod($state, 'beginSubmenuSelection')->invoke($state, $context);
    expect($screen->commandContextWindow->getActiveItem()->action->name)->toBe('Authored spell');
    $actor->battleCommandLoadout = new BattleCommandLoadout([BattleCommandType::ATTACK]);
    expect(array_column(BattleCommandCatalog::buildCommands($actor, $party), 'type'))->toBe([BattleCommandType::ATTACK]);
  } finally {
    foreach ($priorConfig as $key => $value) { new ReflectionProperty(ConfigStore::class, $key)->setValue(null, $value); }
  }
})->with(['traditional' => false, 'active time' => true]);

it('applies shared opening advantages only to the traditional first round', function (string $opening) {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $screen = createTargetingTestScreen();
  $party = new Party();
  $hero = new Character('Hero', 1, new Stats(currentHp: 100, speed: 7));
  $party->addMember($hero);
  $enemy = createTargetingTestEnemy('Enemy');
  $troop = new Troop('Test', [$enemy]);
  $engine = new class($game) extends TraditionalTurnBasedBattleEngine {
    public function setState(\Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState $state): void
    {
      $this->state = $state;
    }
  };
  $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen, settings: ['opening' => [
    'preemptiveChancePercent' => $opening === 'party' ? 100 : 0,
    'ambushChancePercent' => $opening === 'troop' ? 100 : 0,
  ]]));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $state = $engine->turnInitState;
  $state->update($context);
  $turnActors = fn() => array_map(fn($turn) => $turn->battler, $context->getTurns());
  expect($turnActors())->toBe($opening === 'party' ? [$hero] : [$enemy]);
  $state->update($context);
  expect($turnActors())->toHaveCount(2)->toContain($hero, $enemy);
})->with(['party', 'troop']);

it('keeps authored enemy formations out of the player-party render zone', function () {
  $screen = createTargetingTestScreen(); $fieldWindow = $screen->fieldWindow;
  $game = new ReflectionClass(GameTargetingTestProxy::class)->newInstanceWithoutConstructor();
  $engine = new TraditionalTurnBasedBattleEngine($game);
  $engine->configure(new TurnBasedBattleConfig(new Party(), new Troop('Geometry', []), $screen));
  $scene = new ReflectionClass(\Ichiloto\Engine\Scenes\Battle\BattleScene::class)->newInstanceWithoutConstructor();
  setTestProperty($scene, 'config', $engine->battleConfig); setTestProperty($screen, 'battleScene', $scene);
  setTestProperty($fieldWindow, 'battleScreen', $screen);
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

it('includes current authored conditions and stages in the existing battle Info action', function () {
  $game = new ReflectionClass(GameTargetingTestProxy::class)->newInstanceWithoutConstructor();
  $party = new Party();
  $actor = new Character('Synthetic actor', 1, new Stats(currentHp: 100));
  $actor->addState(new \Ichiloto\Engine\Entities\States\State('condition', 'Condition name', 'C', 'Authored explanation.'));
  $actor->setStatStage('speed', -2);
  $party->addMember($actor);
  $troop = new Troop('Synthetic', [createTargetingTestEnemy('Enemy')]);
  $screen = createTargetingTestScreen();
  $engine = new TraditionalTurnBasedBattleEngine($game);
  $engine->configure(new TurnBasedBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  $context->setTurns([new Turn($actor)]);
  $state = new PlayerActionStateTestProxy($engine);
  $state->setActiveCharacterIndexForTest(0);
  $state->loadCharacterActionsForTest($context);
  $state->showFocusedInfoForTest($context);
  expect($screen->lastAlert)->toContain('physical attack', 'Condition name', 'Authored explanation.', 'Speed -2');
  $actor->removeState('condition');
  $actor->resetStatStages();
  $state->showFocusedInfoForTest($context);
  expect($screen->lastAlert)->not->toContain('Condition name', 'Authored explanation.', 'Speed -2');
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
    ?CharacterInterface $targetAnchor = null,
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

it('keeps the active-time round stable while returning to command flow', function () {
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

  expect($context->roundNumber)->toBe(1);
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

it('requires separate target confirmation for submenu commands and allows cancellation', function (bool $activeTime, ItemScopeSide $side, ItemScopeNumber $number, ItemScopeStatus $status, array $indexes, string $source) {
  $game = (new ReflectionClass(GameTargetingTestProxy::class))->newInstanceWithoutConstructor();
  $audio = $this->createStub(AudioManager::class);
  setTestProperty($game, 'audioManager', $audio);
  $skill = new MagicSkill('Targeted Magic', 'Affects the confirmed targets.', '', 5, 0, new ItemScope($side, $number, $status, 2));
  $ability = new SpecialSkill('Targeted Skill', 'Affects the confirmed targets.', '', 5, 0, new ItemScope($side, $number, $status, 2));
  $item = new Item('Targeted Item', 'Affects the confirmed targets.', '!', 10, 2, scope: new InventoryItemScope($side, $number, $status, 2));
  $party = new Party();
  foreach (['Caster', 'Ally', 'Fallen Ally'] as $name) {
    $party->addMember(new Character($name, 0, new Stats(currentHp: 120, currentMp: 30),
      spellbook: new Spellbook([$skill]), abilityBook: new AbilityBook([$ability])));
  }
  $party->inventory->addItems($item);
  $members = $party->battlers->toArray();
  $members[2]->stats->currentHp = 0;
  $enemies = array_map(createTargetingTestEnemy(...), ['Enemy A', 'Enemy B', 'Fallen Enemy']);
  $enemies[2]->stats->currentHp = 0;
  $troop = new Troop('Targets', $enemies);
  $screen = createTargetingTestScreen();
  $engine = $activeTime ? new ActiveTimeBattleEngineCaptureProxy($game) : new TraditionalTurnBasedBattleEngine($game);
  $engine->configure($activeTime ? new ActiveTimeBattleConfig($party, $troop, $screen) : new TurnBasedBattleConfig($party, $troop, $screen));
  $scene = new ReflectionClass(\Ichiloto\Engine\Scenes\Battle\BattleScene::class)->newInstanceWithoutConstructor();
  setTestProperty($scene, 'config', $engine->battleConfig);
  setTestProperty($screen, 'battleScene', $scene);
  setTestProperty($screen->fieldWindow, 'battleScreen', $screen);
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  setTestProperty($engine, 'turnStateExecutionContext', $context);
  $actorIndex = $side === ItemScopeSide::USER ? 1 : 0;
  $turn = new Turn($members[$actorIndex]);
  $context->setTurns([$turn, new Turn($members[1 - $actorIndex])]);
  $state = $activeTime ? new ActiveTimeFlowState($engine) : new PlayerActionState($engine);
  setTestProperty($state, 'activeCharacterIndex', $actorIndex);
  setTestProperty($state, 'selectionMode', 'submenu');
  $command = match ($source) { 'item' => 'Item', 'skill' => 'Skill', default => 'Magic' };
  $options = BattleCommandCatalog::buildOptions($members[$actorIndex], $party, $command);
  expect($options)->toHaveCount(1)->and($options[0]->targetNumber)->toBe($number);
  $option = $options[0];
  $action = $option->action;
  $screen->commandContextWindow->setItems($options, $command);
  $oldSource = InputManager::getInputSource();
  $oldBindings = InputManager::getBindings();
  $oldEvents = new ReflectionProperty(InputManager::class, 'eventManager')->getValue();

  try {
    new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
    InputManager::setBindings(['action' => ['keys' => [KeyCode::ENTER]]]);
    $press = function (KeyCode $key) use ($state, $context): void {
      InputManager::setInputSource(new FakeInputSource($key));
      InputManager::handleInput();
      new ReflectionMethod($state, 'handleActions')->invoke($state, $context);
    };
    $assertUncommitted = function () use ($turn, $engine, $members, $enemies, $action, $item, $actorIndex): void {
      expect($turn->action)->toBeNull()->and($turn->targets)->toBe([])
        ->and($members[$actorIndex]->stats->currentMp)->toBe(30)
        ->and($members[1]->stats->currentHp)->toBe(120)
        ->and($enemies[0]->stats->currentHp)->toBe(30)
        ->and($item->quantity)->toBe(2)
        ->and($action->lastResult)->toBeNull();
      if ($engine instanceof ActiveTimeBattleEngineCaptureProxy) {
        expect($engine->capturedAction)->toBeNull();
      }
    };

    $press(KeyCode::ENTER);
    $assertUncommitted();
    $focusSide = $side === ItemScopeSide::USER ? ItemScopeSide::ALLY : $side;
    $pool = match ($focusSide) {
      ItemScopeSide::ALLY => $members,
      ItemScopeSide::ENEMY_ALLY => [...$members, ...$enemies],
      default => $enemies,
    };
    $expected = array_map(fn(int $index) => $pool[$index], $indexes);
    $partyFocus = $troopFocus = [];
    foreach ($expected as $target) {
      $partyIndex = array_search($target, $members, true);
      $troopIndex = array_search($target, $enemies, true);
      if (is_int($partyIndex)) { $partyFocus[] = $partyIndex; }
      if (is_int($troopIndex)) { $troopFocus[] = $troopIndex; }
    }
    $screen->fieldWindow->renderTargetIndicators();
    expect(new ReflectionProperty($state, 'selectionMode')->getValue($state))->toBe('target')
      ->and($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ALLY))->toBe($partyFocus)
      ->and($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ENEMY))->toBe($troopFocus)
      ->and(array_map(spl_object_id(...), $screen->fieldWindow->renderedFocus))
      ->toEqualCanonicalizing(array_map(spl_object_id(...), $expected));
    if ($side === ItemScopeSide::USER) {
      foreach ([-1, 1] as $step) {
        new ReflectionMethod($state, 'cycleTarget')->invoke($state, $context, $step);
        expect($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ALLY))->toBe([$actorIndex])
          ->and($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ENEMY))->toBe([]);
        $assertUncommitted();
      }
    }
    $press(KeyCode::c);
    $assertUncommitted();
    expect(new ReflectionProperty($state, 'selectionMode')->getValue($state))->toBe('submenu')
      ->and($screen->commandContextWindow->getActiveItem())->toBe($option)
      ->and($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ALLY))->toBe([])
      ->and($screen->fieldWindow->getFocusedIndexes(ItemScopeSide::ENEMY))->toBe([]);

    $press(KeyCode::ENTER);
    $assertUncommitted();
    $press(KeyCode::ENTER);
    $queued = $activeTime ? $engine->capturedTargets : $turn->targets;
    expect($activeTime ? $engine->capturedAction : $turn->action)->toBe($action);
    if ($number === ItemScopeNumber::RANDOM) {
      expect($queued)->toHaveCount(min(2, count($expected)))
        ->and(count(array_unique(array_map(spl_object_id(...), $queued))))->toBe(count($queued));
      foreach ($queued as $target) { expect(in_array($target, $expected, true))->toBeTrue(); }
    } else {
      expect($queued)->toBe($expected);
    }
  } finally {
    InputManager::setInputSource($oldSource);
    InputManager::setBindings($oldBindings);
    new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, $oldEvents);
  }
})->with([false, true])->with([
  'living allies' => [ItemScopeSide::ALLY, ItemScopeNumber::ALL, ItemScopeStatus::ALIVE, [0, 1]],
  'fallen allies' => [ItemScopeSide::ALLY, ItemScopeNumber::ALL, ItemScopeStatus::DEAD, [2]],
  'any allies' => [ItemScopeSide::ALLY, ItemScopeNumber::ALL, ItemScopeStatus::ANY, [0, 1, 2]],
  'living enemies' => [ItemScopeSide::ENEMY, ItemScopeNumber::ALL, ItemScopeStatus::ALIVE, [0, 1]],
  'fallen enemies' => [ItemScopeSide::ENEMY, ItemScopeNumber::ALL, ItemScopeStatus::DEAD, [2]],
  'any enemies' => [ItemScopeSide::ENEMY, ItemScopeNumber::ALL, ItemScopeStatus::ANY, [0, 1, 2]],
  'one ally' => [ItemScopeSide::ALLY, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE, [0]],
  'one enemy' => [ItemScopeSide::ENEMY, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE, [0]],
  'caster only' => [ItemScopeSide::USER, ItemScopeNumber::ONE, ItemScopeStatus::ALIVE, [1]],
  'living either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::ALL, ItemScopeStatus::ALIVE, [0, 1, 3, 4]],
  'fallen either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::ALL, ItemScopeStatus::DEAD, [2, 5]],
  'any either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::ALL, ItemScopeStatus::ANY, [0, 1, 2, 3, 4, 5]],
  'random living allies' => [ItemScopeSide::ALLY, ItemScopeNumber::RANDOM, ItemScopeStatus::ALIVE, [0, 1]],
  'random any enemies' => [ItemScopeSide::ENEMY, ItemScopeNumber::RANDOM, ItemScopeStatus::ANY, [0, 1, 2]],
  'random living either side' => [ItemScopeSide::ENEMY_ALLY, ItemScopeNumber::RANDOM, ItemScopeStatus::ALIVE, [0, 1, 3, 4]],
])->with(['magic', 'skill', 'item']);


require_once __DIR__ . '/../Support/Input/FakeHeldInputSource.php';

it('reveals the live crisis technique only while semantic Right is held and confirms through normal targeting', function (bool $activeTime): void {
  $statics = [];
  foreach ([InputManager::class, ConfigStore::class] as $class) { $statics[$class] = new ReflectionClass($class)->getStaticProperties(); }
  $cwd = getcwd(); $root = createTestDirectory('ichiloto-held-crisis-');
  writeSkillRecords($root, new SpecialSkill('Synthetic long finisher', 'A test technique.', '', 0, 0));
  try {
    chdir($root); new ReflectionProperty(InputManager::class, 'eventManager')->setValue(null, null);
    InputManager::setBindings(['right' => ['keys' => [KeyCode::d]], 'action' => ['keys' => [KeyCode::ENTER]]]);
    $input = new \Tests\Support\Input\FakeHeldInputSource(); InputManager::setInputSource($input);
    $game = new ReflectionClass(GameTargetingTestProxy::class)->newInstanceWithoutConstructor();
    setTestProperty($game, 'audioManager', $this->createStub(AudioManager::class));
    $actor = new Character('Synthetic actor', 1, new Stats(currentHp: 9, totalHp: 100, currentMp: 0, totalMp: 30),
      abilityBook: new AbilityBook([new BasicSkill('Alternate strike', 'Another authored basic attack.', '', 0, 0)]),
      actorId: 'synthetic-actor', crisisAbility: \Ichiloto\Engine\Battle\CrisisAbility::fromArray([
        'name' => 'Surge', 'hpBelowPercent' => 10, 'variants' => [['skill' => 'Synthetic long finisher']],
      ]));
    $party = new Party(); $party->addMember($actor);
    $troop = new Troop('Test', [createTargetingTestEnemy('Enemy')]); $screen = createTargetingTestScreen();
    setTestProperty($screen->commandWindow, 'height', 6);
    $screen->commandWindow->setTitle('Command');
    $engine = $activeTime ? new ActiveTimeBattleEngineCaptureProxy($game) : new TraditionalTurnBasedBattleEngine($game);
    $engine->configure($activeTime ? new ActiveTimeBattleConfig($party, $troop, $screen) : new TurnBasedBattleConfig($party, $troop, $screen));
    $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []); $turn = new Turn($actor); $context->setTurns([$turn]);
    setTestProperty($engine, 'turnStateExecutionContext', $context);
    $state = $activeTime ? new ActiveTimeFlowState($engine) : new PlayerActionState($engine);
    setTestProperty($state, 'activeCharacterIndex', 0);
    new ReflectionMethod($state, 'loadCharacterActions')->invoke($state, $context);
    $refresh = function () use ($state, $context): void { InputManager::handleInput(); $state->update($context); };
    $label = fn() => $screen->commandWindow->presentationSnapshot()->rows[0]->label;
    $refresh(); expect($label())->toBe('Attack');
    new ReflectionMethod($state, 'beginSubmenuSelection')->invoke($state, $context);
    $nativeOptions = new ReflectionProperty(BattleCommandContextWindow::class, 'items')->getValue($screen->commandContextWindow);
    expect(array_column($nativeOptions, 'label'))->toBe(['Attack', 'Alternate strike']);
    new ReflectionMethod($state, 'returnToCommandSelection')->invoke($state, $context);
    $input->press('physical-d', KeyCode::d); $refresh();
    expect($label())->toBe('Synthetic long finisher')->and($screen->commandWindow->getActiveCommand()->type)->toBe(BattleCommandType::ATTACK)
      ->and($actor->stats->currentMp)->toBe(0)->and($turn->action)->toBeNull()
      ->and(new ReflectionMethod($state, 'resolveCommandInfo')->invoke($state, $context))->toBe('A test technique.');
    $screen->commandWindow->selectNext(); $refresh();
    expect($label())->toBe('Attack')->and($screen->commandWindow->getActiveCommand()->type)->not->toBe(BattleCommandType::ATTACK);
    $screen->commandWindow->selectPrevious(); $refresh(); expect($label())->toBe('Synthetic long finisher');
    $input->release('physical-d'); $refresh(); expect($label())->toBe('Attack');
    $input->press('physical-d', KeyCode::d); $refresh();
    $actor->stats->currentHp = 10; $refresh(); expect($label())->toBe('Attack'); // Strict boundary changes live.
    $actor->stats->currentHp = 9; $refresh(); expect($label())->toBe('Synthetic long finisher');
    $input->transitions[] = \Ichiloto\Engine\IO\KeyTransition::reset(); $refresh();
    expect($label())->toBe('Attack'); // Lost focus/source cannot leave the special latched.
    $input->press('physical-d', KeyCode::d); $refresh();
    $input->press('confirm', KeyCode::ENTER); $refresh();
    expect($state->getSelectionMode())->toBe('target')
      ->and($screen->commandContextWindow->getActiveItem()->action)->toBeInstanceOf(\Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction::class)
      ->and($turn->action)->toBeNull()->and($actor->stats->currentMp)->toBe(0);
    $input->release('physical-d'); $refresh();
    expect($state->getSelectionMode())->toBe('target');
    $input->press('cancel', KeyCode::c); $refresh();
    expect($state->getSelectionMode())->toBe('command');
    expect($label())->toBe('Attack');
    InputManager::setInputSource(new FakeInputSource(KeyCode::d)); $refresh();
    new ReflectionMethod($state, 'beginSubmenuSelection')->invoke($state, $context);
    expect($state->getSelectionMode())->toBe('submenu');
    $screen->commandContextWindow->selectNext(); $screen->commandContextWindow->selectNext();
    expect($screen->commandContextWindow->getActiveItem()->action)->toBeInstanceOf(\Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction::class);
    InputManager::setInputSource($input);
    new ReflectionMethod($state, 'returnToCommandSelection')->invoke($state, $context);
    $input->press('physical-d', KeyCode::d); $refresh();
    $input->press('confirm', KeyCode::ENTER); $refresh();
    $input->release('physical-d')->release('confirm'); $refresh();
    expect($state->getSelectionMode())->toBe('target')->and($turn->action)->toBeNull();
    $input->press('confirm', KeyCode::ENTER); $refresh();
    $queued = $activeTime ? $engine->capturedAction : $turn->action;
    expect($queued)->toBeInstanceOf(\Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction::class)
      ->and($queued->name)->toBe('Synthetic long finisher')->and($actor->stats->currentMp)->toBe(0);
  } finally {
    chdir($cwd);
    foreach ($statics as $class => $values) { foreach ($values as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); } }
  }
})->with(['traditional' => false, 'active time' => true]);
