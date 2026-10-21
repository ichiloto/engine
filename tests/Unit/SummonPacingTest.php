<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Enumerations\BattleActionCategory;
use Ichiloto\Engine\Battle\Enumerations\BattlePace;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

beforeEach(function () {
  $this->priorCwd = getcwd();
  $this->root = createTestDirectory('summon-pacing-');
  $directory = $this->root . '/assets/Cutscenes/Summons/call';
  mkdir($directory, 0777, true);
  // The summon's name and its linked action deliberately avoid the word "summon".
  file_put_contents($directory . '/call.data.php', "<?php return ['id' => 'call', 'name' => 'Caller',"
    . " 'moveName' => 'Skyfall', 'linkedActionId' => 'Skybreak'];");
  file_put_contents($directory . '/call.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 4,"
    . " 'tracks' => [], 'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 2]]];");
  chdir($this->root);
});

afterEach(function () {
  BattleCommandCatalog::endBattle();
  chdir($this->priorCwd);
});

it('paces an authored summon by its action type at every pace and no longer guesses summons from names',
  function (BattlePace $pace) {
    $pacing = new BattlePacing($pace, $pace);
    $summon = new SkillBattleAction(new SpecialSkill('Skybreak', '', '', 0, 0));
    $named = new SkillBattleAction(new SpecialSkill('Summon Storm', '', '', 0, 0));
    $expected = BattleTurnTimings::fromTotalDuration(BattleActionCategory::SUMMON->totalDurationSeconds($pace));
    $physical = BattleTurnTimings::fromTotalDuration(BattleActionCategory::PHYSICAL_ATTACK->totalDurationSeconds($pace));

    expect(BattleActionCategory::fromAction($summon))->toBe(BattleActionCategory::SUMMON)
      ->and($pacing->getTurnTimings($summon))->toEqual($expected)
      ->and($pacing->getTurnTimings($summon)->announcement)->toBeGreaterThan($physical->announcement)
      // Only an authored summon is a summon; a name alone no longer chooses its pacing.
      ->and(BattleActionCategory::fromAction($named))->toBe(BattleActionCategory::PHYSICAL_ATTACK)
      ->and($pacing->getTurnTimings(new AttackAction('Summon')))->toEqual($physical);
  })->with([BattlePace::SLOW, BattlePace::MEDIUM, BattlePace::FAST]);

it('holds the summon move for its whole announcement before the summon arrives without changing the outcome',
  function (bool $activeTime, bool $graphical, bool $reduced) {
    $priorConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
    $priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
    try {
      BattleCommandCatalog::beginBattle();
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createStub(AudioManager::class));
      [$engine, $context, $screen, , , $enemies] = $fixture;
      $action = new SkillBattleAction(new SpecialSkill('Skybreak', '', '', 0, 0,
        invocation: new SkillInvocation(message: '%2', accuracy: 100),
        effects: [new HPDamageSkillEffect('7', variance: 0)]), random: new SeededCombatRandomSource(1));
      queueTargetExecution($fixture, $action, [$enemies[0]]);
      $playback = $screen->fieldWindow->getCommandPlayback();
      $phases = $playback->plan->phases;
      $arrival = $phases['summon-in']['start'] ?? $phases['target']['start'];
      $reading = (int)ceil(BattleTurnTimings::fromTotalDuration(BattleActionCategory::SUMMON->totalDurationSeconds(
        new BattlePacing()->getAnimationPace()))->announcement * BattleCommandTimeline::FPS);
      expect($phases['announce']['length'])->toBe($reading)
        ->and($arrival)->toBeGreaterThanOrEqual($phases['announce']['start'] + $reading);

      $read = [];
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
      for ($tick = 0; $tick < 3000 && $context->getCurrentTurn() !== null; $tick++) {
        $frame = $playback->session->currentFrame;
        if ($screen->announcement !== null && !$playback->isCompleted) { $read[$screen->announcement][] = $frame; }
        $engine->actionExecutionState->update($context);
      }

      expect(array_keys($read))->toBe(['Skyfall'])
        ->and(min($read['Skyfall']))->toBeLessThanOrEqual($phases['announce']['start'] + 1)
        ->and(max($read['Skyfall']))->toBeLessThan($arrival)
        ->and(count($read['Skyfall']))->toBeGreaterThanOrEqual($reading - 1)
        ->and($enemies[0]->stats->currentHp)->toBe(93)
        ->and($playback->presentationFailure)->toBeNull()
        ->and($context->getCurrentTurn())->toBeNull();
    } finally {
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $priorDelta);
      $priorConfig === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $priorConfig);
    }
  })->with([false, true])->with([false, true])->with([false, true]);
