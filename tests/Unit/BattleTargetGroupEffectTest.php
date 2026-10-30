<?php

use Ichiloto\Engine\Animations\Timelines\{EffectPresentation, EffectTimelineLibrary};
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\{BattleCanvasLayout, BattleCommandPlayback, BattleCommandTimeline, BattleEffectTargetGroup, BattlePoseRole, GraphicalBattleEffects};
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\{Character, Stats};
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-target-group-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  \Tests\Support\Rendering\writeTestPng($this->root . '/effect.png', 12, 8);
  $this->actor = new Character('Same name', 1, new Stats());
  $this->one = new Character('Same name', 1, new Stats());
  $this->two = new Character('Same name', 1, new Stats());
  $this->other = new Character('Same name', 1, new Stats());
  $this->bounds = [spl_object_id($this->one) => new CanvasRectangle(160, 300, 80, 100),
    spl_object_id($this->two) => new CanvasRectangle(410, 410, 80, 100),
    spl_object_id($this->other) => new CanvasRectangle(1100, 100, 80, 100)];
  $this->grounds = [spl_object_id($this->one) => new Vector2(200, 400),
    spl_object_id($this->two) => new Vector2(450, 510), spl_object_id($this->other) => new Vector2(1140, 200)];
  $this->track = ['id' => 'group', 'type' => 'image', 'asset' => 'effect.png', 'anchor' => 'target-group',
    'attachment' => 'ground', 'fit' => 'contain', 'span' => true, 'cells' => ['width' => 2, 'height' => 2],
    'pivot' => ['x' => .5, 'y' => 1.0], 'keyframes' => [['frame' => 0, 'duration' => 3]]];
  $this->timeline = ['fps' => 24, 'lengthFrames' => 3, 'restFrame' => 0, 'tracks' => [$this->track]];
});

afterEach(function () { unlink($this->root . '/effect.png'); rmdir($this->root); });

it('retains exact selected identities and their separate ground depths, excluding unrelated bodies', function () {
  $group = BattleEffectTargetGroup::collectTargets([$this->one, $this->two, $this->one], $this->bounds, $this->grounds);
  expect(array_keys($group->grounds))->toBe([spl_object_id($this->one), spl_object_id($this->two)])
    ->and([$group->left, $group->right, $group->far, $group->near, $group->spanWidth])->toBe([200.0, 450.0, 400.0, 510.0, 330.0])
    ->and($group->getGroundOrigin())->toEqual(new Vector2(325, 510))
    ->and($group->bounds->toArray())->toBe(['x' => 160.0, 'y' => 300.0, 'width' => 330.0, 'height' => 210.0])
    ->and(BattleEffectTargetGroup::collectTargets([], [], []))->toBeNull();
  expect(fn() => BattleEffectTargetGroup::collectTargets([$this->one], $this->bounds, []))->toThrow(RuntimeException::class);
});

it('draws one proportional group effect at the current selected span with one command resolution', function (bool $reduced) {
  $effect = (new EffectTimelineLibrary($this->root))->compile('group', $this->timeline, true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [$this->one, $this->two, $this->one], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $layout = new BattleCanvasLayout(1440, 840);
  $render = fn() => GraphicalBattleEffects::compose($playback, $layout, $this->bounds, $this->root, $reduced,
    groundAnchors: $this->grounds);
  $image = $render()->images[0];
  expect($render()->images)->toHaveCount(1)
    ->and($image->id)->toEndWith('target-group')
    ->and($image->destination->toArray())->toBe(['x' => 160.0, 'y' => 290.0, 'width' => 330.0, 'height' => 220.0])
    ->and($playback->presentationFailure)->toBeNull()->and($hits)->toBe(0);
  // The same stable recipient moves in logical formation; presentation reads
  // its current projection, without choosing a different recipient or firing a cue.
  $this->grounds[spl_object_id($this->two)] = new Vector2(550, 530);
  $this->bounds[spl_object_id($this->two)] = new CanvasRectangle(510, 430, 80, 100);
  $image = $render()->images[0];
  expect($image->destination->width)->toBe(430.0)
    ->and($image->destination->height)->toEqualWithDelta(430 / 1.5, 1e-9)
    ->and($image->destination->x + $image->destination->width / 2)->toBe(375.0)
    ->and($image->destination->y + $image->destination->height)->toBe(530.0)->and($hits)->toBe(0);
  $playback->update(100);
  expect($hits)->toBe(1)->and($render()->images)->toBeEmpty();
})->with([false, true]);

it('keeps authored fixed group sizing available and reports incomplete geometry without resolving combat', function () {
  $this->timeline['tracks'][0]['span'] = false;
  $effect = (new EffectTimelineLibrary($this->root))->compile('fixed', $this->timeline, true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [$this->one], BattlePoseRole::ATTACK, function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $layout = new BattleCanvasLayout(1440, 840);
  $images = GraphicalBattleEffects::compose($playback, $layout, $this->bounds, $this->root, false, groundAnchors: $this->grounds)->images;
  expect($images)->toHaveCount(1)->and($images[0]->destination->width)->toBe(96.0);
  $failed = GraphicalBattleEffects::compose($playback, $layout, $this->bounds, $this->root, false, groundAnchors: []);
  expect($failed->images)->toBeEmpty()->and($playback->presentationFailure)->not->toBeNull()->and($hits)->toBe(0);
  $playback->update(100);
  expect($hits)->toBe(1);
});

it('validates span ownership and leaves graphical resources out of terminal admission', function () {
  $library = new EffectTimelineLibrary($this->root);
  foreach ([['anchor' => 'target'], ['attachment' => 'center'], ['fit' => 'stretch'], ['span' => 'yes']] as $bad) {
    $data = $this->timeline; $data['tracks'][0] = array_replace($this->track, $bad);
    expect(fn() => $library->compile('bad-group', $data, true))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => $library->compile('field-group', $this->timeline))->toThrow(InvalidArgumentException::class);
  $this->timeline['tracks'][0]['presentation'] = 'graphical';
  $this->timeline['tracks'][0]['asset'] = 'missing-graphical.png';
  expect(fn() => $library->compile('terminal-group', $this->timeline, true, EffectPresentation::TERMINAL))->not->toThrow(Throwable::class);
});

it('crops a wide group at unchanged proportional density without shifting its ground origin', function () {
  $this->grounds[spl_object_id($this->one)] = new Vector2(20, 400);
  $this->grounds[spl_object_id($this->two)] = new Vector2(1420, 600);
  $this->bounds[spl_object_id($this->one)] = new CanvasRectangle(0, 300, 40, 100);
  $this->bounds[spl_object_id($this->two)] = new CanvasRectangle(1400, 500, 40, 100);
  $effect = (new EffectTimelineLibrary($this->root))->compile('clipped-group', $this->timeline, true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
    $this->actor, [$this->one, $this->two], BattlePoseRole::ATTACK, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $layout = new BattleCanvasLayout(1440, 840);
  $images = GraphicalBattleEffects::compose($playback, $layout, $this->bounds, $this->root, false, groundAnchors: $this->grounds)->images;
  expect($images)->toHaveCount(1);
  $image = $images[0];
  $scale = $image->destination->width / $image->sourceRect->width;
  $image->destination->assertWithin(1440, 840);
  $originX = $image->destination->x - $image->sourceRect->x * $scale;
  $originY = $image->destination->y - $image->sourceRect->y * $scale;
  expect($image->destination->height / $image->sourceRect->height)->toEqualWithDelta($scale, 1e-9)
    ->and($originX + 12 * $scale / 2)->toEqualWithDelta(720, 1e-9)
    ->and($originY + 8 * $scale)->toEqualWithDelta(600, 1e-9)
    ->and($playback->presentationFailure)->toBeNull();
});
