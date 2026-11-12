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
use Ichiloto\Engine\Entities\Skills\MagicSkill;
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
  file_put_contents($directory . '/call.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 96,"
    . " 'tracks' => [], 'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 2]]];");
  chdir($this->root);
});

afterEach(function () {
  BattleCommandCatalog::endBattle();
  chdir($this->priorCwd);
});

/** Every phase of a turn except its announcement, which a summon reads for its own time. */
function getPhasesAroundAnnouncement(BattleTurnTimings $timings): array
{
  return [$timings->stepForward, $timings->actionAnimation, $timings->effectAnimation, $timings->stepBack,
    $timings->statChanges, $timings->turnOver];
}

it('reads an authored summon\'s move for the summon announcement time and paces the rest of its turn as before',
  function (BattlePace $pace, float $reading) {
    $pacing = new BattlePacing($pace, $pace);
    $summon = new SkillBattleAction(new SpecialSkill('Skybreak', '', '', 0, 0));
    $named = new SkillBattleAction(new SpecialSkill('Summon Storm', '', '', 0, 0));
    $timings = $pacing->getTurnTimings($summon);
    // An authored summon was paced as the attack its special skill is before summons were paced by action type.
    $attack = $pacing->getTurnTimings(new AttackAction('Attack'));

    expect(BattleActionCategory::fromAction($summon))->toBe(BattleActionCategory::SUMMON)
      ->and($timings->announcement)->toBe($reading)
      ->and($timings->announcement)->toBe($pacing->getAnnouncementSeconds(BattleActionCategory::SUMMON))
      ->and(getPhasesAroundAnnouncement($timings))->toBe(getPhasesAroundAnnouncement($attack))
      ->and($timings->announcement)->toBeGreaterThan($attack->announcement)
      // Only an authored summon is a summon; a name alone no longer chooses its pacing.
      ->and(BattleActionCategory::fromAction($named))->toBe(BattleActionCategory::PHYSICAL_ATTACK)
      ->and($pacing->getTurnTimings(new AttackAction('Summon')))->toEqual($attack);
  })->with([[BattlePace::SLOW, 4.2], [BattlePace::MEDIUM, 2.8], [BattlePace::FAST, 1.68]]);

it('keeps every other action\'s turn, its announcement included, as its budget paces it', function () {
  $budgets = ['physical_attack' => [4.0, 2.5, 1.5], 'basic_magic' => [5.5, 3.5, 2.0], 'high_magic' => [9.0, 6.0, 4.0]];
  $actions = ['physical_attack' => new AttackAction('Attack'),
    'basic_magic' => new SkillBattleAction(new MagicSkill('Spark', '', '', 0, 0)),
    'high_magic' => new SkillBattleAction(new MagicSkill('Flare', '', '', 0, 0))];

  foreach ($budgets as $category => $seconds) {
    foreach ([BattlePace::SLOW, BattlePace::MEDIUM, BattlePace::FAST] as $index => $pace) {
      $timings = new BattlePacing($pace, $pace)->getTurnTimings($actions[$category]);

      expect(BattleActionCategory::fromAction($actions[$category])->value)->toBe($category)
        ->and($timings)->toEqual(new BattleTurnTimings($seconds[$index] * .07, $seconds[$index] * .28, $seconds[$index] * .12,
          $seconds[$index] * .18, $seconds[$index] * .07, $seconds[$index] * .18, $seconds[$index] * .10));
    }
  }
});

it('reads a category\'s announcement for the time the project authors at each pace, within its limits', function () {
  $pacing = BattlePacing::fromBattleUiConfig(['animation_pace' => 'medium', BattlePacing::ANNOUNCEMENT_SECONDS_KEY => [
    'summon' => ['medium' => 3.5, 'slow' => 6], 'physical_attack' => ['medium' => 99], 'basic_magic' => ['medium' => 'long']]]);
  $summon = new SkillBattleAction(new SpecialSkill('Skybreak', '', '', 0, 0));
  $engine = new BattlePacing(BattlePace::MEDIUM, BattlePace::MEDIUM);

  expect($pacing->getTurnTimings($summon)->announcement)->toBe(3.5)
    ->and(getPhasesAroundAnnouncement($pacing->getTurnTimings($summon)))
    ->toBe(getPhasesAroundAnnouncement($engine->getTurnTimings($summon)))
    ->and($pacing->getAnnouncementSeconds(BattleActionCategory::PHYSICAL_ATTACK))->toBe(BattlePacing::MAX_ANNOUNCEMENT_SECONDS)
    // A value that is not a number reads for the Engine's time; another pace's authored time is not this pace's.
    ->and($pacing->getAnnouncementSeconds(BattleActionCategory::BASIC_MAGIC))
    ->toBe($engine->getAnnouncementSeconds(BattleActionCategory::BASIC_MAGIC))
    ->and($pacing->getAnnouncementSeconds(BattleActionCategory::HIGH_MAGIC))
    ->toBe($engine->getAnnouncementSeconds(BattleActionCategory::HIGH_MAGIC))
    ->and(BattleActionCategory::SUMMON->getAnnouncementConfigPath(BattlePace::MEDIUM))
    ->toBe('ui.battle.announcement_seconds.summon.medium');
});

it('overlays the summon move as the cutscene starts without adding a reading pause or changing the outcome',
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
      $departure = isset($phases['summon-out']) ? $phases['summon-out']['start'] + $phases['summon-out']['length']
        : $phases['target']['start'] + $phases['target']['length'];
      $pacing = new BattlePacing();
      $reading = (int)ceil($pacing->getAnnouncementSeconds(BattleActionCategory::SUMMON) * BattleCommandTimeline::FPS);
      // The beats before the summon arrives and after it leaves are the attack's, as before summon pacing.
      $attack = $pacing->getTurnTimings(new AttackAction('Attack'));
      $frames = static fn(float ...$seconds): int => array_sum(array_map(
        static fn(float $phase): int => max(1, (int)ceil($phase * BattleCommandTimeline::FPS)), $seconds));
      expect($phases['announce']['length'])->toBe($reading)
        ->and($arrival)->toBe($phases['announce']['start'] + $frames($attack->actionAnimation))
        ->and($arrival)->toBe($frames($attack->stepForward, $attack->actionAnimation))
        ->and($playback->plan->timeline->defaults['lengthFrames'] - $departure)
        ->toBe($frames($attack->statChanges, $attack->stepBack, $attack->turnOver));

      $read = [];
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
      for ($tick = 0; $tick < 3000 && $context->getCurrentTurn() !== null; $tick++) {
        $frame = $playback->session->currentFrame;
        if ($screen->announcement !== null && !$playback->isCompleted) { $read[$screen->announcement][] = $frame; }
        $engine->actionExecutionState->update($context);
      }

      expect(array_keys($read))->toBe(['Skyfall'])
        ->and(min($read['Skyfall']))->toBeLessThanOrEqual($phases['announce']['start'] + 1)
        ->and(max($read['Skyfall']))->toBeGreaterThanOrEqual($arrival)
        ->and(count($read['Skyfall']))->toBeGreaterThanOrEqual($reading - 1)
        ->and(max($read['Skyfall']))->toBeLessThanOrEqual($phases['announce']['start'] + $reading)
        ->and($enemies[0]->stats->currentHp)->toBe(93)
        ->and($playback->presentationFailure)->toBeNull()
        ->and($context->getCurrentTurn())->toBeNull();
    } finally {
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $priorDelta);
      $priorConfig === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $priorConfig);
    }
  })->with([false, true])->with([false, true])->with([false, true]);
