<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\ItemScope as SkillScope;
use Ichiloto\Engine\Entities\Inventory\Inventory;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\ItemStore;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

beforeEach(function () {
  $this->eligibilityCwd = getcwd();
  $this->eligibilityDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
  $this->eligibilityConfig = [];
  foreach ([ProjectConfig::class, ItemStore::class] as $key) {
    $this->eligibilityConfig[$key] = ConfigStore::has($key) ? ConfigStore::get($key) : null;
    ConfigStore::remove($key);
  }
  $root = createTestDirectory('ichiloto-queued-eligibility-');
  chdir($root);
  mkdir('assets/Data', 0700, true);
  mkdir('assets/Cutscenes/Summons/call', 0700, true);
  file_put_contents('assets/Data/animations.php', '<?php return [];');
  file_put_contents('assets/Cutscenes/Summons/call/call.data.php', '<?php return ' . var_export([
    'id' => 'call', 'name' => 'Synthetic summon', 'linkedActionId' => 'Call',
    'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 400],
    'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 400],
  ], true) . ';');
  file_put_contents('assets/Cutscenes/Summons/call/call.timeline.php', '<?php return ' . var_export([
    'fps' => 10, 'lengthFrames' => 20, 'restFrame' => 0,
    'tracks' => [['id' => 'mark', 'type' => 'glyph', 'keyframes' => [
      ['frame' => 0, 'duration' => 20, 'content' => '*'],
    ]]],
    'cues' => [['id' => 'sound', 'type' => 'playSound', 'frame' => 0,
      'payload' => ['soundEffect' => 'not-played.wav']]],
  ], true) . ';');
  BattleCommandCatalog::beginBattle();
});

afterEach(function () {
  BattleCommandCatalog::endBattle();
  chdir($this->eligibilityCwd);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->eligibilityDelta);
  foreach ($this->eligibilityConfig as $key => $value) {
    $value === null ? ConfigStore::remove($key) : ConfigStore::put($key, $value);
  }
});

function createEligibilitySkillAction(string $kind, ?SkillScope $scope = null): SkillBattleAction
{
  $class = match ($kind) { 'basic' => BasicSkill::class, 'magic' => MagicSkill::class, default => SpecialSkill::class };
  $skill = new $class($kind === 'summon' ? 'Call' : 'Synthetic ' . $kind, '', '', 4, 0,
    scope: $scope ?? new SkillScope(), effects: [new HPDamageSkillEffect('20', variance: 0)]);
  $random = new class implements CombatRandomSource {
    public function nextInt(int $minimum, int $maximum): int { return min($maximum, max($minimum, 95)); }
  };
  return new class($skill, random: $random) extends SkillBattleAction {
    public int $executions = 0;
    public function execute(CharacterInterface $actor, array $targets): void
    {
      $this->executions++;
      parent::execute($actor, $targets);
    }
  };
}

/** Queue while authorized, then change resources before the execution state gets its first update. */
function queueEligibilityTurn(array $fixture, BattleAction $action, array $targets, ?callable $beforeExecution = null): Turn
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
  $turn = $context->getTurns()[0];
  if ($beforeExecution !== null) { $beforeExecution(); }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
  $engine->state->update($context);
  return $turn;
}

function advanceEligibilityTurn(array $fixture, float $seconds): void
{
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $seconds);
  $fixture[0]->state->update($fixture[1]);
}

it('refuses unaffordable queued basic magic and summon commands before presentation',
  function (string $kind, bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $audio = $this->createMock(AudioManager::class);
    $audio->expects($this->never())->method('playSoundEffect');
    $audio->expects($this->never())->method('playSystemSound');
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $audio);
    [, , $screen, $actor, , $enemies] = $fixture;
    $action = createEligibilitySkillAction($kind);
    expect($action->getExecutionRefusal($actor))->toBeNull();
    $turn = queueEligibilityTurn($fixture, $action, [$enemies[1]], function () use ($actor): void {
      $actor->stats->currentMp = 3;
    });
    $playback = $screen->fieldWindow->getCommandPlayback();
    expect($screen->announcement)->toContain('not enough MP')
      ->and($playback->plan->resultsOnly)->toBeTrue()->and($playback->plan->summon)->toBeNull()
      ->and($playback->targets)->toBe([$enemies[1]])->and($turn->targets)->toBe([$enemies[1]])
      ->and($playback->getPoseRole($actor))->toBe(BattlePoseRole::IDLE)
      ->and($playback->getAdvanceFraction())->toBe(0.0)
      ->and($playback->plan->phases['return']['start'] / BattleCommandTimeline::FPS)
      ->toBe($screen->getPacing()->getMessageDurationSeconds())
      ->and($playback->getActiveSegments($reducedMotion, !$graphical))->toBeEmpty()
      ->and($screen->fieldWindow->results)->toBeEmpty()->and($turn->isCompleted)->toBeFalse();
    advanceEligibilityTurn($fixture, .01);
    expect($screen->announcement)->toContain('not enough MP');
    advanceEligibilityTurn($fixture, 20.0);
    expect($action->executions)->toBe(0)->and($action->lastResult)->toBeNull()
      ->and($actor->stats->currentMp)->toBe(3)->and($enemies[1]->stats->currentHp)->toBe(100)
      ->and($enemies[0]->stats->currentHp)->toBe(100)->and($turn->isCompleted)->toBeTrue()
      ->and($screen->fieldWindow->results)->toBeEmpty()->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with(['basic', 'magic', 'summon'])->with([false, true])->with([false, true])->with([false, true]);

it('resolves still-authorized queued skills exactly once at their original impact and target',
  function (string $kind, bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $action = createEligibilitySkillAction($kind);
    $turn = queueEligibilityTurn($fixture, $action, [$enemies[1]]);
    $runner = new ReflectionProperty(ActionExecutionState::class, 'command')->getValue($engine->state);
    $runner->begin();
    $playback = $runner->playback;
    expect($playback->plan->resultsOnly)->toBeFalse()->and($playback->targets)->toBe([$enemies[1]]);
    if ($kind === 'summon') { expect($playback->plan->summon)->not->toBeNull(); }
    $impact = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
    advanceEligibilityTurn($fixture, ($impact - 1) / BattleCommandTimeline::FPS);
    expect($action->executions)->toBe(0)->and($actor->stats->currentMp)->toBe(50)
      ->and($enemies[1]->stats->currentHp)->toBe(100);
    advanceEligibilityTurn($fixture, 1 / BattleCommandTimeline::FPS);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($enemies[1]->stats->currentHp)->toBeLessThan(100)
      ->and($action->lastResult?->targetCount())->toBe(1)
      ->and($screen->fieldWindow->results[0][0])->toBe($enemies[1]);
    $hp = $enemies[1]->stats->currentHp;
    advanceEligibilityTurn($fixture, 20.0);
    $playback->update(20.0);
    $runner->update(20.0);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($enemies[1]->stats->currentHp)->toBe($hp)->and($enemies[0]->stats->currentHp)->toBe(100)
      ->and($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->results)->toHaveCount(1)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with(['basic', 'magic', 'summon'])->with([false, true])->with([false, true])->with([false, true]);

function writeEligibilityImageSummon(bool $graphical): void
{
  if ($graphical) { writeTestPng('assets/poses.png', 24, 8); }
  file_put_contents('assets/Cutscenes/Summons/call/call.data.php', '<?php return ' . var_export([
    'id' => 'call', 'name' => 'Synthetic summon', 'linkedActionId' => 'Call',
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'contact'],
    'transitionIn' => ['type' => 'fadeToBlack', 'durationMs' => 400],
    'transitionOut' => ['type' => 'fadeFromBlack', 'durationMs' => 400],
  ], true) . ';');
  file_put_contents('assets/Cutscenes/Summons/call/call.timeline.php', '<?php return ' . var_export([
    'formatVersion' => 1, 'presentations' => [
      'graphical' => ['fps' => 24, 'lengthFrames' => 12, 'restFrame' => 8, 'tracks' => [[
        'id' => 'body', 'type' => 'image', 'asset' => 'poses.png',
        'sheet' => ['columns' => 3, 'rows' => 1], 'cells' => ['width' => 3, 'height' => 4],
        'fit' => 'contain', 'anchor' => 'caster', 'attachment' => 'ground',
        'pivot' => ['x' => .5, 'y' => 1.0], 'keyframes' => [
          ['frame' => 0, 'duration' => 4, 'sourceFrame' => 0],
          ['frame' => 4, 'duration' => 4, 'sourceFrame' => 1],
          ['frame' => 8, 'duration' => 4, 'sourceFrame' => 2],
        ],
      ]], 'cues' => [['id' => 'contact', 'type' => 'applyEffect', 'frame' => 8]]],
      'terminal' => ['fps' => 10, 'lengthFrames' => 3, 'restFrame' => 1, 'tracks' => [[
        'id' => 'impact', 'type' => 'glyph',
        'keyframes' => [['frame' => 0, 'duration' => 3, 'content' => '*']],
      ]], 'cues' => [['id' => 'contact', 'type' => 'applyEffect', 'frame' => 1]]],
    ],
  ], true) . ';');
}

function writeEligibilityStageSummon(bool $graphical): void
{
  writeEligibilityImageSummon($graphical);
  $directory = 'assets/Cutscenes/Summons/call/call';
  $data = require $directory . '.data.php';
  $data['transitionIn']['durationMs'] = $data['transitionOut']['durationMs'] = 0;
  $timeline = require $directory . '.timeline.php';
  $sequence = &$timeline['presentations']['graphical'];
  $sequence['restFrame'] = 4;
  $sequence['stage'] = [
    'canvas' => ['width' => 400, 'height' => 300], 'startFrame' => 0, 'restoreFrame' => 8,
    'subjects' => [['id' => 'visitor', 'position' => ['x' => 300, 'y' => 260],
      'size' => ['width' => 80, 'height' => 160]]],
    'camera' => [['id' => 'close', 'frame' => 0, 'focus' => ['x' => 300, 'y' => 240], 'zoom' => 2, 'easing' => 'hold'],
      ['id' => 'release', 'frame' => 8, 'focus' => ['x' => 200, 'y' => 150], 'zoom' => 1]],
    'covers' => [['id' => 'initial', 'frame' => 0, 'color' => 'white', 'opacity' => 0, 'easing' => 'hold'],
      ['id' => 'blast', 'frame' => 8, 'color' => 'white', 'opacity' => 0],
      ['id' => 'whiteout', 'frame' => 10, 'color' => 'white', 'opacity' => 1],
      ['id' => 'clear', 'frame' => 11, 'color' => 'white', 'opacity' => 0]],
  ];
  $track = &$sequence['tracks'][0];
  unset($track['cells'], $track['attachment']);
  $track['anchor'] = 'stage';
  $track['placement'] = ['subject' => 'visitor'];
  file_put_contents($directory . '.data.php', '<?php return ' . var_export($data, true) . ';');
  file_put_contents($directory . '.timeline.php', '<?php return ' . var_export($timeline, true) . ';');
}

it('holds cinematic summon restoration and damage feedback before concluding either battle engine',
  function (bool $activeTime, bool $graphical, bool $reducedMotion, bool $lethal) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    writeEligibilityStageSummon($graphical);
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    foreach ($enemies as $enemy) { $enemy->stats->currentHp = $lethal ? 1 : 100; }
    $action = createEligibilitySkillAction('summon', new SkillScope(number: ItemScopeNumber::ALL));
    $turn = queueEligibilityTurn($fixture, $action, $enemies);
    $execution = $engine->state;
    $runner = new ReflectionProperty(ActionExecutionState::class, 'command')->getValue($execution);
    $playback = $runner->playback;
    $plan = $playback->plan;
    expect($plan->cinematicStage !== null)->toBe($graphical);
    $impact = $plan->getCommandFrameForAuthoredFrame('target', $graphical ? 8 : 1);
    advanceEligibilityTurn($fixture, ($impact - 1) / BattleCommandTimeline::FPS);
    expect($action->executions)->toBe(0)->and($actor->stats->currentMp)->toBe(50)
      ->and($screen->fieldWindow->results)->toBeEmpty();
    if ($graphical) {
      $view = $plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion);
      expect($view->active)->toBeTrue()->and($view->contentFrame)->toBe($reducedMotion ? 4 : 7);
    }
    advanceEligibilityTurn($fixture, 1 / BattleCommandTimeline::FPS);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($action->lastResult->targetCount())->toBe(2)
      ->and($enemies[0]->isKnockedOut)->toBe($lethal)->and($enemies[1]->isKnockedOut)->toBe($lethal)
      ->and($engine->state)->toBe($execution)->and($context->getCurrentTurn())->toBe($turn)
      ->and($playback->isCompleted)->toBeFalse()
      ->and($screen->fieldWindow->results)->toHaveCount($graphical ? 0 : 2);
    if ($graphical) {
      $restored = $plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion);
      expect($restored->active)->toBeFalse()->and($restored->drawsContent)->toBe(!$reducedMotion);
      $whiteout = $plan->getCommandFrameForAuthoredFrame('target', 10);
      advanceEligibilityTurn($fixture, ($whiteout - $impact) / BattleCommandTimeline::FPS);
      $view = $plan->getCinematicStageFrame($playback->session->currentFrame, $reducedMotion);
      expect($view->cover['opacity'])->toBe($reducedMotion ? 0.0 : 1.0)
        ->and($screen->fieldWindow->results)->toBeEmpty()->and($engine->state)->toBe($execution);
    }
    $reaction = $plan->phases['reaction']['start'];
    advanceEligibilityTurn($fixture, ($reaction - $playback->session->currentFrame) / BattleCommandTimeline::FPS);
    expect($playback->phase)->toBe('reaction')->and($action->executions)->toBe(1)
      ->and(array_column($screen->fieldWindow->results, 0))->toBe($enemies)
      ->and($plan->getCinematicStageFrame($playback->session->currentFrame))->toBeNull()
      ->and($engine->state)->toBe($execution)->and($context->getCurrentTurn())->toBe($turn);
    if ($graphical) {
      expect($playback->getPoseElapsedSeconds($enemies[0]))->toBe(0.0)
        ->and($playback->getPoseRole($enemies[0]))->toBe($lethal ? BattlePoseRole::KNOCKOUT : BattlePoseRole::DAMAGE);
    }
    $reactionLength = $plan->phases['reaction']['length'];
    advanceEligibilityTurn($fixture, ($reactionLength - 1) / BattleCommandTimeline::FPS);
    expect($engine->state)->toBe($execution)->and($screen->fieldWindow->getCommandPlayback())->toBe($playback)
      ->and($screen->fieldWindow->results)->toHaveCount(2);
    $hp = array_map(static fn($enemy) => $enemy->stats->currentHp, $enemies);
    advanceEligibilityTurn($fixture, 20.0);
    $runner->update(20.0);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and(array_map(static fn($enemy) => $enemy->stats->currentHp, $enemies))->toBe($hp)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($screen->fieldWindow->results)->toHaveCount(2)
      ->and($screen->controlsRestored)->toBe($graphical ? 1 : 0);
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('resolves image summons at the selected presentation contact cue and cleans up both engines',
  function (bool $activeTime, bool $graphical, bool $reducedMotion, bool $allEnemies) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    writeEligibilityImageSummon($graphical);
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, , $screen, $actor, , $enemies] = $fixture;
    $action = createEligibilitySkillAction('summon', new SkillScope(number: $allEnemies ? ItemScopeNumber::ALL : ItemScopeNumber::ONE));
    $targets = $allEnemies ? $enemies : [$enemies[1]];
    $turn = queueEligibilityTurn($fixture, $action, $targets);
    $runner = new ReflectionProperty(ActionExecutionState::class, 'command')->getValue($engine->state);
    $playback = $runner->playback;
    $summon = $playback->plan->summon;
    expect($summon)->not->toBeNull()->and($summon->fps)->toBe($graphical ? 24 : 10)
      ->and($summon->defaults['lengthFrames'])->toBe($graphical ? 12 : 3)
      ->and($playback->plan->phases['target']['length'])->toBe($graphical ? 60 : 36);
    $impact = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
    expect($impact - $playback->plan->phases['target']['start'])->toBe($graphical ? 40 : 12);
    advanceEligibilityTurn($fixture, ($impact - 1) / BattleCommandTimeline::FPS);
    $segments = $playback->getActiveSegments($reducedMotion, !$graphical);
    expect($action->executions)->toBe(0)->and($actor->stats->currentMp)->toBe(50)
      ->and($enemies[1]->stats->currentHp)->toBe(100)->and($segments)->toHaveCount(1)
      ->and($segments[0]['layer'])->toBe($graphical ? 'image' : 'glyph');
    if ($graphical) {
      expect($segments[0]['drawCommands'][0]['payload'])->toMatchArray([
        'sourceFrame' => $reducedMotion ? 2 : 1, 'fit' => 'contain',
        'anchor' => 'caster', 'attachment' => 'ground', 'frameWidth' => 8, 'frameHeight' => 8,
      ]);
    }
    advanceEligibilityTurn($fixture, 1 / BattleCommandTimeline::FPS);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($enemies[1]->stats->currentHp)->toBeLessThan(100)
      ->and($action->lastResult?->targetCount())->toBe(count($targets))
      ->and(array_column($screen->fieldWindow->results, 0))->toBe($targets);
    if ($allEnemies) { expect($enemies[0]->stats->currentHp)->toBeLessThan(100); }
    $otherHp = $enemies[0]->stats->currentHp;
    $hp = $enemies[1]->stats->currentHp;
    advanceEligibilityTurn($fixture, 20.0);
    $playback->update(20.0);
    $runner->update(20.0);
    expect($action->executions)->toBe(1)->and($actor->stats->currentMp)->toBe(46)
      ->and($enemies[1]->stats->currentHp)->toBe($hp)->and($enemies[0]->stats->currentHp)->toBe($otherHp)
      ->and($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->results)->toHaveCount(count($targets))
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($playback->getActiveSegments($reducedMotion, !$graphical))->toBeEmpty();
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('pauses image summons at contact and abandons them without losing or replaying combat',
  function (bool $activeTime, bool $graphical, bool $reducedMotion, bool $afterContact, bool $stage) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $stage ? writeEligibilityStageSummon($graphical) : writeEligibilityImageSummon($graphical);
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $action = createEligibilitySkillAction('summon');
    queueEligibilityTurn($fixture, $action, [$enemies[1]]);
    $execution = $engine->state;
    $runner = new ReflectionProperty(ActionExecutionState::class, 'command')->getValue($execution);
    $playback = $runner->playback;
    $impact = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
    advanceEligibilityTurn($fixture, ($impact - 1) / BattleCommandTimeline::FPS);
    $frame = $playback->session->currentFrame;
    $segments = $playback->getActiveSegments($reducedMotion, !$graphical);
    expect($segments)->not->toBeEmpty()->and($screen->controlsHidden)->toBe($graphical ? 1 : 0);
    $screen->pauseTiming();
    advanceEligibilityTurn($fixture, 60.0);
    expect($playback->session->currentFrame)->toBe($frame)
      ->and($playback->getActiveSegments($reducedMotion, !$graphical))->toBe($segments)
      ->and($action->executions)->toBe(0)->and($actor->stats->currentMp)->toBe(50)
      ->and($enemies[1]->stats->currentHp)->toBe(100)->and($screen->fieldWindow->results)->toBeEmpty();
    $screen->resumeTiming();
    if ($afterContact) { advanceEligibilityTurn($fixture, 1 / BattleCommandTimeline::FPS); }
    $hp = $enemies[1]->stats->currentHp;
    $results = $screen->fieldWindow->results;
    expect($action->executions)->toBe($afterContact ? 1 : 0)
      ->and($actor->stats->currentMp)->toBe($afterContact ? 46 : 50)
      ->and($results)->toHaveCount($afterContact && !($graphical && $stage) ? 1 : 0);
    if ($afterContact) { expect($hp)->toBeLessThan(100); }
    $execution->exit($context);
    $execution->exit($context);
    $screen->resumeTiming(discard: true);
    $playback->resume();
    $playback->update(60.0);
    $runner->begin();
    $runner->update(60.0);
    expect($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($screen->fieldWindow->getActingBattler())->toBeNull()
      ->and($screen->announcement)->toBeNull()->and($screen->controlsRestored)->toBe($graphical ? 1 : 0)
      ->and($playback->getActiveSegments($reducedMotion, !$graphical))->toBeEmpty()
      ->and($playback->getAdvanceFraction())->toBe(0.0)
      ->and($action->executions)->toBe($afterContact ? 1 : 0)
      ->and($actor->stats->currentMp)->toBe($afterContact ? 46 : 50)
      ->and($enemies[1]->stats->currentHp)->toBe($hp)->and($enemies[0]->stats->currentHp)->toBe(100)
      ->and($screen->fieldWindow->results)->toBe($results);
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('rechecks MP at impact and replaces abandoned presentation with a refusal without resurrection',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, , $screen, $actor, , $enemies] = $fixture;
    $action = createEligibilitySkillAction('magic');
    $turn = queueEligibilityTurn($fixture, $action, [$enemies[1]]);
    $runner = new ReflectionProperty(ActionExecutionState::class, 'command')->getValue($engine->state);
    $original = $runner->playback;
    $actor->stats->currentMp = 3;
    advanceEligibilityTurn($fixture, 20.0);
    expect($screen->announcement)->toContain('not enough MP')->and($original->isCancelled)->toBeTrue()
      ->and($runner->playback)->not->toBe($original)->and($runner->playback->plan->resultsOnly)->toBeTrue()
      ->and($action->executions)->toBe(0)->and($actor->stats->currentMp)->toBe(3)
      ->and($screen->fieldWindow->results)->toBeEmpty()->and($enemies[1]->stats->currentHp)->toBe(100);
    advanceEligibilityTurn($fixture, 20.0);
    $runner->update(20.0);
    $runner->begin();
    expect($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
      ->and($action->executions)->toBe(0)->and($action->lastResult)->toBeNull();
  })->with([false, true])->with([false, true])->with([false, true]);

it('refuses depleted queued items using their resource owner even when the queued reference is stale',
  function (bool $owned, bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $audio = $this->createMock(AudioManager::class);
    $audio->expects($this->never())->method('playSystemSound');
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $audio);
    [, , $screen, $actor] = $fixture;
    $held = new Item('Recovery', '', '', 1, 1, scope: new ItemScope(ItemScopeSide::ALLY),
      effects: [new HPRecoveryEffect('Restore', '', 10, 1, ValueBasis::ACTUAL)], id: 'recovery');
    $inventory = $owned ? new Inventory() : null;
    $inventory?->addItems($held);
    $queued = $owned ? clone $held : $held;
    $action = new ItemBattleAction($queued, $inventory);
    expect($action->getExecutionRefusal($actor))->toBeNull();
    $turn = queueEligibilityTurn($fixture, $action, [$actor], function () use ($held, $inventory): void {
      if ($inventory !== null) { $inventory->removeItems($held); }
      else { $held->quantity = 0; }
    });
    expect($screen->announcement)->toContain('none left')
      ->and($screen->fieldWindow->getCommandPlayback()->plan->resultsOnly)->toBeTrue()
      ->and($queued->quantity)->toBe($owned ? 1 : 0);
    advanceEligibilityTurn($fixture, 20.0);
    expect($turn->isCompleted)->toBeTrue()->and($actor->stats->currentHp)->toBe(80)
      ->and($screen->fieldWindow->results)->toBeEmpty()->and($action->lastResult)->toBeNull()
      ->and($held->quantity)->toBe(0)->and($inventory?->getQuantityById('recovery'))->toBe($owned ? 0 : null);
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('spends only the live owned item stack and never overspends a reused queued reference', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [, , $screen, $actor] = $fixture;
  $held = new Item('Recovery', '', '', 1, 1, scope: new ItemScope(ItemScopeSide::ALLY),
    effects: [new HPRecoveryEffect('Restore', '', 10, 1, ValueBasis::ACTUAL)], id: 'recovery');
  $inventory = new Inventory();
  $inventory->addItems($held);
  $queued = clone $held;
  $action = new ItemBattleAction($queued, $inventory);
  $turn = queueEligibilityTurn($fixture, $action, [$actor]);
  advanceEligibilityTurn($fixture, 20.0);
  expect($turn->isCompleted)->toBeTrue()->and($actor->stats->currentHp)->toBe(90)
    ->and($inventory->getQuantityById('recovery'))->toBe(0)->and($queued->quantity)->toBe(1);
  $result = $action->lastResult;
  $screen->fieldWindow->results = [];
  $refused = queueEligibilityTurn($fixture, $action, [$actor]);
  expect($screen->announcement)->toContain('none left');
  advanceEligibilityTurn($fixture, 20.0);
  expect($refused->isCompleted)->toBeTrue()->and($actor->stats->currentHp)->toBe(90)
    ->and($action->lastResult)->toBe($result)->and($screen->fieldWindow->results)->toBeEmpty()
    ->and($inventory->getQuantityById('recovery'))->toBe(0)->and($queued->quantity)->toBe(1);
})->with([false, true]);

it('uses the configured battle screen capability to compile terminal summons without PNG assets', function () {
  file_put_contents('assets/Cutscenes/Summons/call/call.timeline.php', '<?php return ' . var_export([
    'fps' => 10, 'lengthFrames' => 2, 'restFrame' => 0, 'tracks' => [
      ['id' => 'art', 'type' => 'image', 'presentation' => 'graphical', 'asset' => 'missing.png',
        'keyframes' => [['frame' => 0, 'duration' => 2]]],
      ['id' => 'text', 'type' => 'glyph', 'presentation' => 'terminal',
        'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '*']]],
    ],
  ], true) . ';');
  [$engine] = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
  $action = createEligibilitySkillAction('summon');
  $compiled = new ReflectionMethod(ActionExecutionState::class, 'resolveSummonCutscene')
    ->invoke($engine->actionExecutionState, $action);
  expect($compiled)->not->toBeNull()->and($compiled->hasTerminalContent)->toBeTrue()
    ->and(array_column($compiled->playbackSegments, 'layer'))->not->toContain('image');
});

it('rechecks the inventory owner at impact before applying an item effect',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [, , $screen, $actor] = $fixture;
    $held = new Item('Recovery', '', '', 1, 1, scope: new ItemScope(ItemScopeSide::ALLY),
      effects: [new HPRecoveryEffect('Restore', '', 10, 1, ValueBasis::ACTUAL)], id: 'recovery');
    $inventory = new Inventory();
    $inventory->addItems($held);
    $queued = clone $held;
    $action = new ItemBattleAction($queued, $inventory);
    $turn = queueEligibilityTurn($fixture, $action, [$actor]);
    $original = $screen->fieldWindow->getCommandPlayback();
    $inventory->removeItems($held);
    advanceEligibilityTurn($fixture, 20.0);
    expect($original->isCancelled)->toBeTrue()->and($screen->announcement)->toContain('none left')
      ->and($screen->fieldWindow->results)->toBeEmpty()->and($actor->stats->currentHp)->toBe(80)
      ->and($inventory->getQuantityById('recovery'))->toBe(0)->and($queued->quantity)->toBe(1)
      ->and($action->lastResult)->toBeNull();
    advanceEligibilityTurn($fixture, 20.0);
    expect($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with([false, true])->with([false, true])->with([false, true]);

it('preserves reusable items and skips consumption when there are no effects or recipients', function () {
  [, , , $actor] = createTargetExecutionFixture(false, false, $this->createMock(AudioManager::class));
  $effect = new HPRecoveryEffect('Restore', '', 10, 1, ValueBasis::ACTUAL);
  foreach ([[false, [$effect], [$actor]], [true, [], [$actor]], [true, [$effect], []]] as [$consumable, $effects, $targets]) {
    $held = new Item('Recovery', '', '', 1, 2, consumable: $consumable, effects: $effects, id: 'recovery');
    $inventory = new Inventory();
    $inventory->addItems($held);
    $action = new ItemBattleAction(clone $held, $inventory);
    $action->execute($actor, $targets);
    expect($inventory->getQuantityById('recovery'))->toBe(2);
  }
  expect($actor->stats->currentHp)->toBe(90);
});

it('uses a replenished stack of the same stable identity instead of stale queued quantity', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [, , , $actor] = $fixture;
  $old = new Item('Recovery', '', '', 1, 1, scope: new ItemScope(ItemScopeSide::ALLY),
    effects: [new HPRecoveryEffect('Restore', '', 10, 1, ValueBasis::ACTUAL)], id: 'recovery');
  $inventory = new Inventory();
  $inventory->addItems($old);
  $action = new ItemBattleAction($old, $inventory);
  $inventory->removeItems($old);
  $current = clone $old;
  $current->quantity = 2;
  $inventory->addItems($current);
  $turn = queueEligibilityTurn($fixture, $action, [$actor]);
  advanceEligibilityTurn($fixture, 20.0);
  expect($turn->isCompleted)->toBeTrue()->and($actor->stats->currentHp)->toBe(90)
    ->and($old->quantity)->toBe(0)->and($current->quantity)->toBe(1)
    ->and($inventory->getQuantityById('recovery'))->toBe(1);
})->with([false, true]);
