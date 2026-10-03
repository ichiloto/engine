<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandContextWindow;
use Ichiloto\Engine\Battle\UI\BattleCommandWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope as InventoryScope;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class TargetExecutionField extends BattleFieldWindow
{
  public array $results = [];
  public function __construct() {}
  public function clearTargetIndicators(): void {}
  public function focusPartyBattler(int $index, bool $blink = false): void {}
  public function focusOnTroopBattler(int $index, bool $blink = false): void {}
  public function clearMagicCastEffects(): void {}
  public function clearStatChangePopups(): void {}
  public function clearBattleFlash(): void {}
  public function clearSummonShake(): void {}
  public function showStatChangePopup(CharacterInterface $battler, array $lines, bool $clearExisting = true,
    float $durationSeconds = 0.0): void { $this->results[] = [$battler, $lines]; }
}

final class TargetExecutionScreen extends BattleScreen
{
  public array $observed = [];
  public function __construct(private bool $graphical)
  {
    $this->fieldWindow = new TargetExecutionField();
    $this->characterNameWindow = new class extends BattleCharacterNameWindow {
      public function __construct() {}
      public function setActiveSelection(int $index, bool $blink = false): void {}
    };
    $this->characterStatusWindow = new class extends BattleCharacterStatusWindow {
      public function __construct() {}
      public function setCharacters(array $characters): void {}
    };
    $this->commandWindow = new class extends BattleCommandWindow {
      public function __construct() {}
      public function blur(): void {}
    };
    $this->commandContextWindow = new class extends BattleCommandContextWindow {
      public function __construct() {}
      public function clear(): void {}
    };
  }
  public function getPacing(): BattlePacing { return new BattlePacing(); }
  public function usesGraphicalField(): bool { return $this->graphical; }
  public function refresh(): void {}
  public function refreshField(): void
  {
    $playback = $this->fieldWindow->getCommandPlayback();
    if ($playback !== null) {
      $this->observed[] = [$playback->phase, $playback->getPoseRole($playback->actor), $playback->targets];
    }
  }
  public function hideMessage(): void {}
  public function showMessage(string $text): void {}
  public function alert(string $text): void {}
}

function createTargetExecutionFixture(bool $activeTime, bool $graphical, AudioManager $audio): array
{
  $game = new class extends Game { public function __construct() {} public function __destruct() {} };
  new ReflectionProperty(Game::class, 'audioManager')->setValue($game, $audio);
  $party = new Party();
  $actor = new Character('Caster', 1, new Stats(currentHp: 80, totalHp: 100, currentMp: 50, totalMp: 50));
  $ally = new Character('Recipient', 1, new Stats(currentHp: 0, totalHp: 100));
  $party->addMember($actor);
  $party->addMember($ally);
  $enemies = [];
  foreach (['Enemy A', 'Enemy B'] as $name) {
    $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(Enemy::class, 'name')->setValue($enemy, $name);
    new ReflectionProperty(Enemy::class, 'stats')->setValue($enemy, new Stats(currentHp: 100, totalHp: 100));
    $enemies[] = $enemy;
  }
  $troop = new Troop('Synthetic', $enemies);
  $screen = new TargetExecutionScreen($graphical);
  $engine = $activeTime ? new ActiveTimeBattleEngine($game) : new TraditionalTurnBasedBattleEngine($game);
  $engine->configure($activeTime ? new ActiveTimeBattleConfig($party, $troop, $screen)
    : new TurnBasedBattleConfig($party, $troop, $screen));
  $context = new TurnStateExecutionContext($game, $party, $troop, $screen, []);
  new ReflectionProperty($engine, 'turnStateExecutionContext')->setValue($engine, $context);
  return [$engine, $context, $screen, $actor, $ally, $enemies];
}

function queueTargetExecution(array $fixture, BattleAction $action, array $targets): Turn
{
  [$engine, $context, , $actor] = $fixture;
  if ($engine instanceof ActiveTimeBattleEngine) {
    $engine->queueImmediateTurn($context, $actor, $action, $targets);
  } else {
    $turn = new Turn($actor);
    $turn->action = $action;
    $turn->targets = $targets;
    $context->setTurns([$turn]);
    $engine->setState($engine->actionExecutionState);
  }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
  $engine->state->update($context);
  return $context->getTurns()[0];
}

beforeEach(function () {
  $this->targetConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  $this->targetDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
});

afterEach(function () {
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->targetDelta);
  $this->targetConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->targetConfig);
});

it('executes a queued revival on its fallen ally through impact, reaction and cleanup',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
    $item = new Item('Revival', '', '', 10, 2,
      scope: new InventoryScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD),
      effects: [new ResurrectionEffect('Revive', '', 25, 1, ValueBasis::ACTUAL)]);
    $turn = queueTargetExecution($fixture, new ItemBattleAction($item), [$ally]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($ally)])
      ->and(array_map(spl_object_id(...), $playback?->targets ?? []))->toBe([spl_object_id($ally)]);
    $impact = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, ($impact - 1) / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(0)->and($item->quantity)->toBe(2);
    $playback->pause();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(0)->and($item->quantity)->toBe(2);
    $playback->resume();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(25)->and($item->quantity)->toBe(1)
      ->and($actor->stats->currentHp)->toBe(80)->and($enemies[0]->stats->currentHp)->toBe(100)
      ->and($enemies[1]->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(50)
      ->and($screen->fieldWindow->results[0][0])->toBe($ally);
    $reaction = $playback->plan->phases['reaction']['start'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, max(0, $reaction - $impact) / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($playback->getPoseRole($ally))->toBe(BattlePoseRole::HEAL);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($item->quantity)->toBe(1)
      ->and($turn->isCompleted)->toBeTrue()->and($playback->getPoseRole($ally))->toBe(BattlePoseRole::IDLE)
      ->and($playback->getAdvanceFraction())->toBe(0.0);
  })->with([false, true])->with([false, true])->with([false, true]);

it('keeps a queued support action within its ally scope when its chosen target falls', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $skill = new MagicSkill('Recover', '', '', 4, 0, new ItemScope(ItemScopeSide::ALLY),
    effects: [new HPRecoverSkillEffect('20', variance: 0)]);
  $turn = queueTargetExecution($fixture, new SkillBattleAction($skill), [$ally]);
  expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($actor)]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->state->update($context);
  expect($actor->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(46)
    ->and($enemies[0]->stats->currentHp)->toBe(100)->and($enemies[1]->stats->currentHp)->toBe(100);
})->with([false, true]);

it('retargets one attack to one living opponent, never the entire opposing side', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $turn = queueTargetExecution($fixture, new AttackAction('Attack'), [$ally]);
  expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($enemies[0])])
    ->and(array_map(spl_object_id(...), $screen->fieldWindow->getCommandPlayback()?->targets ?? []))
    ->toBe([spl_object_id($enemies[0])]);
})->with([false, true]);

it('does not spend a revival item when no fallen ally remains eligible', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $ally->stats->currentHp = 100;
  $item = new Item('Revival', '', '', 10, 2,
    scope: new InventoryScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD),
    effects: [new ResurrectionEffect('Revive', '', 25, 1, ValueBasis::ACTUAL)]);
  $turn = queueTargetExecution($fixture, new ItemBattleAction($item), [$ally]);
  expect($item->quantity)->toBe(2)->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
    ->and($turn->isCompleted)->toBeTrue()->and($enemies[0]->stats->currentHp)->toBe(100);
})->with([false, true]);

it('resolves an all-ally command once and shows a result for each legal recipient',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
    $ally->stats->currentHp = 20;
    $skill = new MagicSkill('Synthetic group heal', '', '', 4, 0,
      new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ALL), effects: [new HPRecoverSkillEffect('20', variance: 0)]);
    $action = new SkillBattleAction($skill);
    $turn = queueTargetExecution($fixture, $action, [$ally]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($actor), spl_object_id($ally)])
      ->and(array_map(spl_object_id(...), $playback?->targets ?? []))->toBe(array_map(spl_object_id(...), $turn->targets));
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($actor->stats->currentHp)->toBe(100)->and($ally->stats->currentHp)->toBe(40)
      ->and($actor->stats->currentMp)->toBe(46)->and($action->lastResult?->targetCount())->toBe(2)
      ->and(array_map(static fn(array $result): int => spl_object_id($result[0]), $screen->fieldWindow->results))
      ->toBe([spl_object_id($actor), spl_object_id($ally)])
      ->and($enemies[0]->stats->currentHp)->toBe(100)->and($enemies[1]->stats->currentHp)->toBe(100)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($turn->isCompleted)->toBeTrue();
  })->with([false, true])->with([false, true])->with([false, true]);
