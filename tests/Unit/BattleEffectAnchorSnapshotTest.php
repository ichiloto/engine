<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\{BattleEffectAnchorSnapshot, BattleEffectTargetGroup, BattleCommandPlayback, BattleCommandTimeline, BattlePoseRole};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineValidator;

function anchorSnapshotFixture(): array
{
  $actor = new Character('Same', 1, new Stats(currentHp: 100, totalHp: 100));
  $target = new Character('Same', 1, new Stats(currentHp: 100, totalHp: 100));
  $id = spl_object_id($target);
  $ground = new Vector2(20, 60);
  $group = BattleEffectTargetGroup::collectTargets([$target, $target], [$id => new CanvasRectangle(10, 20, 20, 40)], [$id => $ground]);
  return [$actor, $target, $group];
}

it('owns a copied source ground independently of selected membership and later input mutation', function () {
  [$actor, $target, $group] = anchorSnapshotFixture();
  $source = new Vector2(90.25, 45.5);
  $capture = new BattleEffectAnchorSnapshot($group, $source);
  $source->x = 900;
  $returned = $capture->getCasterGround(); $returned->y = 800;
  expect($capture->getCasterGround())->toEqual(new Vector2(90.25, 45.5))
    ->and($capture->targets)->toBe($group)
    ->and(array_keys($capture->targets->grounds))->toBe([spl_object_id($target)])
    ->and($capture->targets->grounds)->not->toHaveKey(spl_object_id($actor));
});

it('rejects nonfinite captured source coordinates', function (float $x, float $y) {
  [,, $group] = anchorSnapshotFixture();
  expect(fn() => new BattleEffectAnchorSnapshot($group, new Vector2($x, $y)))->toThrow(InvalidArgumentException::class);
})->with([[INF, 0.0], [0.0, NAN], [-INF, 0.0]]);

it('captures source and recipients once at the authored cue while preserving target-only providers', function (bool $legacy) {
  [$actor, $target, $group] = anchorSnapshotFixture();
  $data = ['fps' => 24, 'lengthFrames' => 4, 'targetCaptureFrame' => 1, 'effectTiming' => ['mode' => 'frame', 'frame' => 2],
    'tracks' => [['id' => 'text', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'duration' => 4, 'content' => '.']]]]];
  $effect = (new EffectTimelineValidator(''))->compile('capture', $data, forBattle: true);
  $plan = new BattleCommandTimeline(new BattleTurnTimings(.1,.1,.1,.1,.1,.1,.1), target: $effect);
  $hits = $captures = 0;
  $p = new BattleCommandPlayback($plan, $actor, [$target], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $callback = function (BattleCommandPlayback $sample) use ($group, $legacy, &$captures): BattleEffectTargetGroup|BattleEffectAnchorSnapshot {
    $captures++;
    return $legacy ? $group : new BattleEffectAnchorSnapshot($group, new Vector2(90, 45));
  };
  if ($legacy) { $p->bindTargetGroupCapture($callback); }
  else { $p->bindAnchorSnapshotCapture($callback); }
  $p->begin(); $captureTick = $plan->getCommandFrameForAuthoredFrame('target', 1);
  $p->update(($captureTick - 1) / 120);
  expect($p->getCapturedTargetGroup('snapshot'))->toBeNull()->and($captures)->toBe(0)->and($hits)->toBe(0);
  $p->update(1/120);
  expect($p->getCapturedTargetGroup('snapshot'))->toBe($group)->and($captures)->toBe(1)->and($hits)->toBe(0)
    ->and($p->targets)->toBe([$target]);
  if ($legacy) { expect($p->getCapturedAnchorSnapshot('snapshot'))->toBeNull(); }
  else { expect($p->getCapturedAnchorSnapshot('snapshot')->getCasterGround())->toEqual(new Vector2(90, 45)); }
  $p->pause(); $p->update(10); $p->resume(); $p->update(10);
  expect($captures)->toBe(1)->and($hits)->toBe(1)->and($actor->stats->currentHp)->toBe(100)->and($target->stats->currentHp)->toBe(100);
  $p->cancel(); expect($p->getCapturedTargetGroup('snapshot'))->toBeNull()->and($p->getCapturedAnchorSnapshot('snapshot'))->toBeNull();
})->with([false, true]);
