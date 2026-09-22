<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleProgression;
use Ichiloto\Engine\Battle\Presentation\BattleResultsContent;
use Ichiloto\Engine\Battle\Presentation\BattleResultsPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleRewards;
use Ichiloto\Engine\Progression\ProgressionSnapshot;

function playbackAward(string $id = 'hero', int $from = 80, int $to = 340, array $learned = []): BattleProgression
{
  $thresholds = [1 => 0, 2 => 100, 3 => 200, 4 => 300, 5 => 500];
  $snapshot = fn(int $exp, array $stats) => new ProgressionSnapshot($id, ucfirst($id),
    $exp >= 500 ? 5 : min(4, intdiv($exp, 100) + 1), $exp, 5, $thresholds, $stats);
  return new BattleProgression($to - $from, $snapshot($from, ['maxHp' => 80, 'speed' => 5, 'grace' => 5]),
    $snapshot($to, ['maxHp' => 100, 'speed' => 4, 'grace' => 5]), $learned);
}

it('requires distinct confirmations and finishes an exit on update without a third press', function () {
  $playback = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  expect($playback->isComplete())->toBeFalse()->and($playback->confirm())->toBeFalse()
    ->and($playback->isComplete())->toBeTrue()->and($playback->confirm())->toBeFalse()
    ->and($playback->isExiting())->toBeFalse();
  $playback->update(0.33);
  expect($playback->confirm())->toBeFalse()->and($playback->isExiting())->toBeTrue();
  $playback->update(0.42);
  expect($playback->isFinished())->toBeTrue()->and($playback->confirm())->toBeTrue();
});

it('locks confirmation through the outgoing button gap and incoming button', function (bool $natural) {
  $p = new BattleResultsPlayback(new BattleRewards(260, 0, [playbackAward()]));
  $p->update($natural ? 2.25 : 0.5);
  if (!$natural) { $p->confirm(); }
  expect($p->isComplete())->toBeTrue()
    ->and($p->confirmation())->toBe(['label' => 'Complete', 'opacity' => 1.0, 'enabled' => false]);
  foreach ([[0.06, 'Complete', 0.5], [0.09, 'Continue', 0.0], [0.10, 'Continue', 0.5]] as [$delta, $label, $alpha]) {
    $p->update($delta);
    $button = $p->confirmation();
    expect($button['label'])->toBe($label)->and($button['opacity'])->toEqualWithDelta($alpha, 0.000001)
      ->and($button['enabled'])->toBeFalse()->and($p->confirm())->toBeFalse()
      ->and($p->currentStage()['kind'])->toBe('primary');
  }
  $p->update(0.09);
  expect($p->confirmation())->toBe(['label' => 'Continue', 'opacity' => 1.0, 'enabled' => true]);
  $p->confirm();
  expect($p->currentStage()['kind'])->toBe('level');
  $p->update(1.32);
  expect($p->confirmation()['label'])->toBe('Complete')->and($p->confirmation()['enabled'])->toBeFalse();
  $p->update(0.33);
  expect($p->confirmation())->toBe(['label' => 'Continue', 'opacity' => 1.0, 'enabled' => true]);
})->with([true, false]);

it('carries natural completion overshoot into the button transition independently of frame rate', function () {
  $facts = new BattleRewards(0, 0, []);
  $single = new BattleResultsPlayback($facts);
  $split = new BattleResultsPlayback($facts);
  $single->update(2.5);
  $split->update(2.0);
  $split->update(0.3);
  $split->update(0.2);
  expect($single->confirmation())->toBe($split->confirmation())
    ->and($single->confirmation()['opacity'])->toEqualWithDelta(0.5, 0.000001);
});

it('holds event order per character and counts ability groups independently of inner entries', function () {
  $skills = [
    ['name' => 'Cure', 'kind' => 'Magic', 'description' => 'Healing', 'cost' => 8],
    ['name' => 'Focus', 'kind' => 'Ability', 'description' => 'Focus yourself', 'cost' => 2],
  ];
  $rewards = new BattleRewards(260, 10, [playbackAward(learned: $skills), playbackAward('reserve')],
    specialRewards: [['title' => 'Seal', 'description' => 'Actual authored reward']]);
  $playback = new BattleResultsPlayback($rewards, true);
  $stages = [];
  while (!$playback->isFinished()) {
    $stages[] = $playback->currentStage();
    $playback->update(0.1);
    $playback->confirm();
  }
  expect(array_column($stages, 'kind'))->toBe(['primary', 'level', 'ability', 'ability', 'level', 'special'])
    ->and(array_column($stages, 'actor'))->toBe([null, 0, 0, 0, 1, null])
    ->and($playback->eventCounter())->toBe(['current' => 4, 'total' => 4])
    ->and($playback->rewards)->toBe($rewards);
});

it('rejects repeated streams and ignores confirmations during page transition', function () {
  $p = new BattleResultsPlayback(new BattleRewards(260, 0, [playbackAward()]));
  $p->confirm();
  foreach (range(1, 20) as $_) { $p->update(0.04); $p->confirm(); }
  expect($p->currentStage()['kind'])->toBe('primary');
  $p->update(0.081);
  $p->confirm();
  expect($p->currentStage()['kind'])->toBe('level')->and($p->isComplete())->toBeFalse();
  $p->update(0.1);
  $p->confirm();
  expect($p->stageTime())->toBe(0.0);
  $p->update(1.3);
  expect($p->isComplete())->toBeTrue();
});

it('samples all EXP gauges together with visible thresholds and exact final facts', function () {
  $p = new BattleResultsPlayback(new BattleRewards(260, 0, [playbackAward(), playbackAward('reserve')]));
  $p->update(0.899);
  expect($p->progress(0)['experience'])->toBe(80)->and($p->progress(1))->toBe($p->progress(0));
  $p->update(0.29);
  expect($p->progress(0)['ratio'])->toBe(1.0)->and($p->progress(0)['level'])->toBe(1);
  $p->update(0.05);
  expect($p->progress(0)['level'])->toBe(2)->and($p->progress(0)['ratio'])->toBeLessThan(1.0);
  $p->update(100);
  expect($p->progress(0))->toMatchArray(['level' => 4, 'current' => 40, 'needed' => 200,
    'ratio' => 0.2, 'maximum' => false, 'experience' => 340]);
});

it('has equivalent capped and zero outcomes with reduced motion and no automatic event advance', function () {
  $facts = new BattleRewards(0, 0, [playbackAward(from: 500, to: 500)]);
  $normal = new BattleResultsPlayback($facts);
  $reduced = new BattleResultsPlayback($facts, true);
  $normal->update(100);
  expect($normal->currentStage()['kind'])->toBe('primary')->and($reduced->isComplete())->toBeTrue()
    ->and($normal->progress(0))->toBe($reduced->progress(0))
    ->and($normal->progress(0)['maximum'])->toBeTrue();
});

it('keeps a consumer-defined page limit and resets it at the next event', function () {
  $p = new BattleResultsPlayback(new BattleRewards(260, 0, [playbackAward()]), true);
  $p->setScrollLimit(9);
  foreach (range(0, 11) as $_) { $p->navigate(1); }
  expect($p->scrollOffset)->toBe(9)->and($p->pageCount())->toBe(10);
  $p->setScrollLimit(2);
  expect($p->scrollOffset)->toBe(2);
  $p->confirm();
  expect($p->scrollOffset)->toBe(0)->and($p->pageCount())->toBeLessThan(10);
});

it('retains long content, signed stats and actual MP costs without ANSI or control characters', function () {
  $skill = ['name' => str_repeat('Very long ', 40), 'kind' => 'Ability',
    'description' => "\e[31mRED\e[0m\n" . str_repeat('d', 601), 'cost' => 8];
  $p = new BattleResultsPlayback(new BattleRewards(260, 0, [playbackAward(learned: [$skill])]), true);
  $p->confirm();
  $level = implode("\n", array_column(BattleResultsContent::eventLines($p), 'text'));
  expect($level)->toContain('+20', '-1', '(0)');
  $p->update(0.1);
  $p->confirm();
  $lines = BattleResultsContent::eventLines($p);
  $text = implode("\n", array_column($lines, 'text'));
  expect($text)->toContain('Cost: 8 MP', 'RED')->not->toContain("\e")
    ->and(substr_count($text, 'd'))->toBeGreaterThanOrEqual(601)->and($p->pageCount())->toBeGreaterThan(1);
  foreach ($lines as $line) { expect(mb_strlen($line['text']))->toBeLessThanOrEqual(50); }
});

it('rejects invalid playback time and invalid page geometry', function () {
  $p = new BattleResultsPlayback(new BattleRewards(0, 0, []));
  foreach ([-1.0, INF, NAN] as $delta) { expect(fn() => $p->update($delta))->toThrow(InvalidArgumentException::class); }
  expect(fn() => $p->setScrollLimit(-1))->toThrow(InvalidArgumentException::class);
});

it('keeps newlines but strips C0 and C1 controls before native cell projection', function () {
  expect(BattleResultsContent::wrap("a\t b\u{0085}c\nsecond\x01 line", 40))->toBe(['a  bc', 'second line']);
});
