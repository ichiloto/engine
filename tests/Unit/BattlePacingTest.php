<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\Enumerations\BattlePace;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

it('shares project animation pace resolution with standalone authoring without changing global config', function (array $battleUi, BattlePace $expected) {
  $previous = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['ui' => ['battle' => $battleUi]]));
  try {
    $configured = BattlePacing::fromConfig();
    $isolated = BattlePacing::fromBattleUiConfig($battleUi);
    expect($isolated->getAnimationPace())->toBe($expected)
      ->and($configured->getAnimationPace())->toBe($expected)
      ->and($isolated->getMessageDurationSeconds())->toBe($configured->getMessageDurationSeconds())
      ->and($isolated->getTurnTimings(new AttackAction('Renamable command')))
      ->toEqual($configured->getTurnTimings(new AttackAction('Renamable command')));
    BattlePacing::fromBattleUiConfig(['animation_pace' => 'fast']);
    expect(BattlePacing::fromConfig()->getAnimationPace())->toBe($expected);
  } finally {
    $previous === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $previous);
  }
})->with([
  'explicit pace' => [['animation_pace' => 'fast', 'animation_speed' => 'slow', 'message_pace' => 'medium'], BattlePace::FAST],
  'legacy animation speed' => [['animation_speed' => 'medium', 'message_pace' => 'fast'], BattlePace::MEDIUM],
  'message pace fallback' => [['message_pace' => 'medium'], BattlePace::MEDIUM],
  'legacy message speed' => [['message_speed' => 'fast'], BattlePace::FAST],
  'legacy numeric information duration' => [['info_display_speed' => 1.25], BattlePace::FAST],
  'invalid explicit pace' => [['animation_pace' => 'invalid', 'message_pace' => 'fast'], BattlePace::SLOW],
  'default profile' => [[], BattlePace::SLOW],
]);

it('uses the slow preset duration for battle messages', function () {
  $pacing = new BattlePacing(BattlePace::SLOW, BattlePace::SLOW);

  expect($pacing->getMessageDurationSeconds())->toBe(2.5);
});

it('matches the slow physical attack timing budget', function () {
  $pacing = new BattlePacing(BattlePace::SLOW, BattlePace::SLOW);
  $timings = $pacing->getTurnTimings(new AttackAction('Attack'));

  expect(round($timings->totalDurationSeconds(), 1))->toBe(4.0)
    ->and(round($timings->turnOver, 2))->toBeGreaterThan(0.3)
    ->and(round($timings->effectAnimation, 2))->toBeGreaterThan(0.7);
});
