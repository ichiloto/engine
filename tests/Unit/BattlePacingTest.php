<?php

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\Enumerations\BattlePace;

it('uses the slow preset duration for battle messages', function () {
  $pacing = new BattlePacing(BattlePace::SLOW, BattlePace::SLOW);

  expect($pacing->getMessageDurationSeconds())->toBe(2.5);
});

it('matches the slow physical attack timing budget', function () {
  $pacing = new BattlePacing(BattlePace::SLOW, BattlePace::SLOW);
  $timings = $pacing->getTurnTimings(new AttackAction('Attack'));

  expect(round($timings->totalDurationSeconds(), 1))->toBe(4.0);
});
