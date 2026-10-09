<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandRunner;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\EnemyDefeatStyle;
use Ichiloto\Engine\IO\Console\TerminalText;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

it('holds simultaneous enemy defeats after feedback and emits exactly one clear sound each', function (bool $activeTime, bool $graphical) {
  $sounds = [];
  $audio = $this->createMock(AudioManager::class);
  $audio->method('playSystemSound')->willReturnCallback(function ($sound) use (&$sounds): void { $sounds[] = $sound; });
  [, $context, , $actor, , $enemies] = createTargetExecutionFixture($activeTime, $graphical, $audio);
  $hits = 0;
  $runner = new BattleCommandRunner($context, $actor, $enemies, null, 'Synthetic action',
    new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, null,
    function () use ($enemies, &$hits): void { $hits++; foreach ($enemies as $enemy) { $enemy->stats->currentHp = 0; } },
    static fn() => [['text' => 'damage']], static fn() => null);
  $runner->begin();
  $playback = $runner->playback;
  $start = $playback->plan->phases['return']['start'] / BattleCommandTimeline::FPS;
  $runner->update($start - .01);
  expect($hits)->toBe(1)->and($sounds)->toBeEmpty();
  foreach ($enemies as $enemy) {
    expect($playback->getEnemyDefeatTreatment($enemy)['pulse'])->toBeFalse()
      ->and($playback->getEnemyDefeatTreatment($enemy)['opacity'])->toBe(1.0);
  }
  $runner->update(.02);
  foreach ($enemies as $enemy) {
    $normal = $playback->getEnemyDefeatTreatment($enemy);
    $reduced = $playback->getEnemyDefeatTreatment($enemy, true);
    expect($normal['pulse'])->toBeTrue()->and($normal['visible'])->toBeTrue()
      ->and($reduced['pulse'])->toBeFalse()->and($reduced['opacity'])->toBeLessThan(1.0);
  }
  $runner->update(.2);
  expect($playback->session->isCompleted)->toBeTrue()->and($playback->isCompleted)->toBeFalse()->and($sounds)->toBeEmpty();
  $context->ui->fieldWindow->pauseTiming();
  $before = $playback->getEnemyDefeatTreatment($enemies[0]);
  $runner->update(100);
  expect($playback->getEnemyDefeatTreatment($enemies[0]))->toEqual($before)->and($sounds)->toBeEmpty();
  $context->ui->fieldWindow->resumeTiming();
  $runner->update(100);
  foreach ($enemies as $enemy) { expect($playback->getEnemyDefeatTreatment($enemy)['visible'])->toBeFalse(); }
  expect($sounds)->toBe(array_fill(0, count($enemies), SystemSound::ENEMY_COLLAPSE))
    ->and($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
  $runner->update(100);
  $runner->dispose();
  expect($sounds)->toHaveCount(count($enemies))->and($context->ui->fieldWindow->getCommandPlayback())->toBeNull();
})->with([false, true])->with([false, true]);

it('cancels a result-only poison or counter defeat without emitting abandoned clear audio', function (bool $resultsOnly) {
  [, $context, , $actor, , $enemies] = createTargetExecutionFixture(false, true, $this->createMock(AudioManager::class));
  $enemy = $enemies[0];
  $events = [];
  $playback = null;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), resultsOnly: $resultsOnly),
    $resultsOnly ? $enemy : $actor, [$enemy], BattlePoseRole::ATTACK,
    function () use ($enemy): void { $enemy->stats->currentHp = 0; },
    function ($cue) use (&$playback, $enemy, &$events): void {
      if ($cue['type'] === 'commandResolved') { $playback->beginEnemyDefeat($enemy); }
      if ($cue['type'] === 'enemyDefeated') { $events[] = $cue; }
    });
  $playback->begin();
  $playback->update($playback->plan->phases['return']['start'] / 120 + .01);
  expect($playback->getEnemyDefeatTreatment($enemy)['visible'])->toBeTrue();
  $playback->cancel();
  $playback->update(100);
  expect($playback->getEnemyDefeatTreatment($enemy))->toBeNull()->and($events)->toBeEmpty()
    ->and($enemy->isKnockedOut)->toBeTrue()->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('removes revived enemies from the presentation hold and never clears them or retries a failed cue', function () {
  [, , , $actor, , $enemies] = createTargetExecutionFixture(false, true, $this->createMock(AudioManager::class));
  foreach ($enemies as $enemy) { $enemy->stats->currentHp = 0; }
  $clears = [];
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $actor, $enemies, BattlePoseRole::ATTACK, static fn() => null,
    function ($cue) use (&$clears): void {
      if ($cue['type'] === 'enemyDefeated') { $clears[] = $cue['payload']['target']; throw new RuntimeException('Synthetic audio failure'); }
    });
  foreach ($enemies as $enemy) { $playback->beginEnemyDefeat($enemy); $playback->beginEnemyDefeat($enemy); }
  $enemies[0]->stats->currentHp = 1;
  $playback->update(100);
  $playback->update(100);
  expect($clears)->toBe(array_slice($enemies, 1))->and($playback->isCompleted)->toBeTrue()
    ->and($playback->presentationFailure)->toBeInstanceOf(RuntimeException::class)
    ->and($playback->getEnemyDefeatTreatment($enemies[0]))->toBeNull();
});

it('bounds defeat ownership to command participants and stops cue delivery on reentrant interruption', function () {
  [, , , $actor, , $enemies] = createTargetExecutionFixture(false, true, $this->createMock(AudioManager::class));
  foreach ($enemies as $enemy) { $enemy->stats->currentHp = 0; }
  $events = [];
  $playback = null;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1)),
    $actor, $enemies, BattlePoseRole::ATTACK, static fn() => null,
    function ($cue) use (&$playback, &$events): void {
      if ($cue['type'] === 'enemyDefeated') { $events[] = $cue; $playback->cancel(); }
    });
  foreach ($enemies as $enemy) { $playback->beginEnemyDefeat($enemy); }
  $outside = clone $enemies[0];
  $playback->beginEnemyDefeat($outside);
  expect($playback->getEnemyDefeatTreatment($outside))->toBeNull();
  $playback->update(100);
  expect($events)->toHaveCount(1)->and($playback->isCancelled)->toBeTrue();
  foreach ($enemies as $enemy) {
    expect($playback->getEnemyDefeatTreatment($enemy))->toBeNull()->and($enemy->isKnockedOut)->toBeTrue();
  }
});

it('keeps treatment bounded and offers non-flashing terminal fading without labels', function (bool $reduced) {
  $style = new EnemyDefeatStyle();
  for ($pulse = 0; $pulse < $style->pulses; $pulse++) {
    $treatment = $style->getTreatment($pulse * $style->pulseSeconds + .01, $reduced);
    expect($treatment['pulse'])->toBe(!$reduced);
    $sprite = EnemyDefeatStyle::applyTerminalTreatment(['Enemy'], $treatment);
    expect(TerminalText::stripAnsi($sprite[0]))->toBe('Enemy')->and($sprite[0])->not->toContain('KO');
  }
  $fade = $style->getTreatment($style->pulses * $style->pulseSeconds + $style->fadeSeconds / 2, $reduced);
  expect($fade['pulse'])->toBeFalse()->and($fade['opacity'])->toBeLessThan(1.0)
    ->and(EnemyDefeatStyle::applyTerminalTreatment(['Enemy'], $fade)[0])->toStartWith("\033[2m")
    ->and(EnemyDefeatStyle::applyTerminalTreatment(['Enemy'], $style->getTreatment($style->getDurationSeconds(), $reduced)))->toBeEmpty();
})->with([false, true]);

it('uses project defeat settings and can omit clear audio without changing the hold', function () {
  $store = new ReflectionClass(\Ichiloto\Engine\Util\Config\ConfigStore::class)->getStaticProperties();
  try {
    \Ichiloto\Engine\Util\Config\ConfigStore::put(\Ichiloto\Engine\Util\Config\ProjectConfig::class,
      new \Ichiloto\Engine\Util\Config\PlaySettings(['ui' => ['battle' => ['defeat' => [
        'pulses' => 2, 'pulseSeconds' => .2, 'fadeSeconds' => .4, 'color' => [64, 160, 255], 'audio' => false]]]]));
    $audio = $this->createMock(AudioManager::class);
    $audio->expects($this->never())->method('playSystemSound');
    [, $context, , $actor, , $enemies] = createTargetExecutionFixture(false, true, $audio);
    $runner = new BattleCommandRunner($context, $actor, $enemies, null, '', new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), null, null,
      static function () use ($enemies): void { foreach ($enemies as $enemy) { $enemy->stats->currentHp = 0; } },
      static fn() => [], static fn() => null);
    $runner->begin();
    expect($runner->playback->defeatStyle->getDurationSeconds())->toBe(.8)
      ->and($runner->playback->defeatStyle->color)->toBe([64, 160, 255]);
    $runner->update(PHP_FLOAT_MAX);
    expect($runner->playback->isCompleted)->toBeTrue();
    $runner->dispose();
  } finally {
    foreach ($store as $key => $value) { new ReflectionProperty(\Ichiloto\Engine\Util\Config\ConfigStore::class, $key)->setValue(null, $value); }
  }
});

it('validates defeat theme limits and rejects unbounded or nonfinite timing', function (array $data) {
  expect(fn() => EnemyDefeatStyle::createFromArray($data))->toThrow(InvalidArgumentException::class);
})->with([[['pulses' => 0]], [['pulses' => 9]], [['pulseSeconds' => INF]], [['fadeSeconds' => NAN]],
  [['fadeSeconds' => 3]], [['color' => [256, 0, 0]]], [['unknown' => true]]]);
