<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineCompiler;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneTrack;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

function battleImageEffectData(?string $anchor = null): array
{
  return ['fps' => 5, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [[
    'id' => 'spark', 'type' => 'image', 'asset' => 'spark.png', 'sheet' => ['columns' => 2, 'rows' => 1],
    'cells' => ['width' => 1, 'height' => 1], ...($anchor === null ? [] : ['anchor' => $anchor]),
    'keyframes' => [['frame' => 0, 'sourceFrame' => 0], ['frame' => 1, 'sourceFrame' => 1]],
  ]], 'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 1]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'impact']];
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-battle-effects-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  writeTestPng($this->root . '/spark.png', 16, 2);
  $this->library = new EffectTimelineLibrary($this->root);
  $this->actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  // Identical data is not identical combatant identity.
  $this->targets = [new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100)),
    new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100))];
  $this->bounds = [spl_object_id($this->actor) => new CanvasRectangle(340, 80, 48, 96),
    spl_object_id($this->targets[0]) => new CanvasRectangle(60, 100, 48, 96),
    spl_object_id($this->targets[1]) => new CanvasRectangle(160, 100, 48, 96)];
  $this->layout = new BattleCanvasLayout(480, 280, uiCellWidth: 3, uiCellHeight: 5);
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
  foreach ($this->debug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('anchors source art to the caster and target art to every distinct combatant instance', function () {
  $effect = $this->library->compile('spark', battleImageEffectData(), true);
  $resolutions = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    $effect, $effect), $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::MAGIC,
    function () use (&$resolutions) { $resolutions++; }, static fn() => null);
  $playback->update($playback->plan->phases['source']['start'] / 120);
  $source = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect($source->images)->toHaveCount(1)->and($source->images[0]->destination->x)->toBe(340.0)
    ->and($source->images[0]->sourceRect->width)->toBe(8)->and($resolutions)->toBe(0);
  $playback->update(.4);
  $targets = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect($targets->images)->toHaveCount(2)->and(array_column(array_column($targets->images, 'destination'), 'x'))
    ->toBe([60.0, 160.0])->and($resolutions)->toBe(0);
  $playback->update(.2);
  $frame = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect(array_column(array_column($frame->images, 'sourceRect'), 'x'))->toBe([8, 8])->and($resolutions)->toBe(1);
  expect(GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false)->toArray())
    ->toBe($frame->toArray())->and($resolutions)->toBe(1);
});

it('holds the effect rest frame under reduced motion without moving the logical impact', function () {
  $effect = $this->library->compile('spark', battleImageEffectData(), true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $frame = $playback->session->currentFrame;
  $calm = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, true);
  expect($calm->images[0]->sourceRect->x)->toBe(8)->and($calm->textLayers)->toBeEmpty()
    ->and($playback->session->currentFrame)->toBe($frame)->and($hits)->toBe(0);
  $playback->update(.2);
  expect($hits)->toBe(1);
});

it('composes one sheet crop per caster or recipient at authored rates and speeds', function (bool $reduced, int $fps, float $speed) {
  writeTestPng($this->root . '/cadence.png', 56, 2);
  $effect = $this->library->compile('cadence', ['fps' => $fps, 'lengthFrames' => 7, 'restFrame' => 3,
    'tracks' => [['id' => 'art', 'type' => 'image', 'asset' => 'cadence.png',
      'sheet' => ['columns' => 7, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1],
      'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'sourceFrame' => $frame], range(0, 6))]],
    'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 1]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'hit']], true);
  $effect->defaults['playback']['defaultSpeed'] = $speed;
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    $effect, $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $previous = 0;
  foreach (['source' => 1, 'target' => 2] as $phase => $count) {
    $start = $playback->plan->phases[$phase]['start'];
    $length = $playback->plan->phases[$phase]['length'];
    for ($tick = 0; $tick < $length; $tick++) {
      $at = $start + $tick;
      $playback->update(($at - $previous) / BattleCommandTimeline::FPS);
      $previous = $at;
      $canvas = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, $reduced);
      $frame = $reduced ? 3 : min(6, (int)floor($tick * $fps * $speed / BattleCommandTimeline::FPS + 1e-12));
      expect($playback->phase)->toBe($phase)->and($canvas->images)->toHaveCount($count)
        ->and(array_unique(array_column($canvas->images, 'id')))->toHaveCount($count)
        ->and(array_column(array_column($canvas->images, 'sourceRect'), 'x'))->toBe(array_fill(0, $count, $frame * 8))
        ->and($hits)->toBe($phase === 'target' && $tick >= (int)ceil(BattleCommandTimeline::FPS / ($fps * $speed)) ? 1 : 0)
        ->and($playback->presentationFailure)->toBeNull();
    }
  }
  $playback->update(10);
  expect(GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, $reduced)->images)
    ->toBeEmpty()->and($hits)->toBe(1);
})->with([false, true])->with([[48, 1.0], [48, .5], [48, 1.25], [120, 4.0], [7, 1.4]]);

it('reconciles a replaced sheet and reports a missing sheet without repeating gameplay', function () {
  $effect = $this->library->compile('spark', battleImageEffectData(), true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update(($playback->plan->phases['target']['start'] + 24) / 120);
  unlink($this->root . '/spark.png');
  writeTestPng($this->root . '/spark.png', 32, 8);
  $replaced = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect($replaced->images[0]->sourceRect->toArray())->toBe(['x' => 16, 'y' => 0, 'width' => 16, 'height' => 8])
    ->and($hits)->toBe(1);
  unlink($this->root . '/spark.png');
  expect(GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false)->images)
    ->toBeEmpty()->and($playback->presentationFailure)->not->toBeNull()->and($hits)->toBe(1);
  $playback->update(5);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
});

it('draws a screen-anchored image once instead of once per target', function () {
  $effect = $this->library->compile('spark', battleImageEffectData('screen'), true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(0, 0, 0, 0, 0, 0, 0),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $images = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false)->images;
  expect($images)->toHaveCount(1)->and($images[0]->destination->toArray())
    ->toBe(['x' => 216.0, 'y' => 116.0, 'width' => 48.0, 'height' => 48.0]);
});

it('diagnoses an omitted subject ground point without changing command resolution', function () {
  $data = battleImageEffectData('target');
  $data['tracks'][0]['attachment'] = 'ground';
  $data['tracks'][0]['pivot'] = ['x' => .5, 'y' => .75];
  $effect = $this->library->compile('spark', $data, true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $canvas = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false, groundAnchors: []);
  expect($canvas->images)->toBeEmpty()->and($playback->presentationFailure)->not->toBeNull()->and($hits)->toBe(0);
  $playback->update(10);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
});

it('supports bounded glyph flash and shake tracks without enabling gameplay cues in ambient effects', function () {
  $data = ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [
    ['id' => 'spark', 'type' => 'glyph', 'anchor' => 'caster', 'keyframes' => [['frame' => 0, 'duration' => 3, 'content' => '*']]],
    ['id' => 'flash', 'type' => 'flash', 'anchor' => 'screen', 'keyframes' => [['frame' => 0, 'duration' => 3, 'color' => 'blue']]],
    ['id' => 'shake', 'type' => 'shake', 'keyframes' => [['frame' => 0, 'duration' => 3, 'payload' => ['amplitude' => 2]]]],
  ]];
  expect(fn() => $this->library->compile('cast', $data))->toThrow(InvalidArgumentException::class);
  $effect = $this->library->compile('cast', $data, true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update(($playback->plan->phases['target']['start'] + 3) / 120);
  $normal = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  $calm = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, true);
  expect($normal->textLayers)->toHaveCount(5)->and($normal->textLayers[0]->runs[0]->text)->toBe('*')
    ->and($calm->textLayers)->toHaveCount(1)->and($playback->getShakeFraction($this->targets[0], false))->not->toBe(0.0);
});

it('honors shake visibility and anchors on the paused command playhead without hiding impact', function (string $anchor, string $stage) {
  $effect = $this->library->compile('shake-visibility', ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [[
    'id' => 'shake', 'type' => 'shake', 'anchor' => $anchor,
    'keyframes' => array_map(static fn(int $frame): array => ['frame' => $frame, 'visible' => $frame === 1,
      'payload' => ['amplitude' => 2]], range(0, 2)),
  ]], 'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 2]]], true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    source: $stage === 'source' ? $effect : null, target: $stage === 'target' ? $effect : null),
    $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::MAGIC,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $unrelated = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
  $subjects = [$this->actor, ...$this->targets, $unrelated];
  $previous = 0;
  foreach ([3 => false, 15 => true, 27 => false] as $tick => $visible) {
    $at = $playback->plan->phases[$stage]['start'] + $tick;
    $playback->update(($at - $previous) / BattleCommandTimeline::FPS);
    $previous = $at;
    expect($playback->phase)->toBe($stage)->and($playback->session->currentFrame)->toBe($at)
      ->and($hits)->toBe($stage === 'target' && $tick >= 24 ? 1 : 0);
    foreach ($subjects as $subject) {
      $affected = match ($anchor) {
        'caster' => $subject === $this->actor,
        'target' => in_array($subject, $this->targets, true),
        'screen' => true,
      };
      $offset = $playback->getShakeFraction($subject, false);
      expect(abs($offset) > 0)->toBe($visible && $affected)
        ->and(abs($offset))->toBeLessThanOrEqual(.2)
        ->and($playback->getShakeFraction($subject, true))->toBe(0.0)
        ->and($subject->stats->currentHp)->toBe(100);
      $playback->pause();
      $playback->update(5);
      expect($playback->getShakeFraction($subject, false))->toBe($offset)
        ->and($playback->session->currentFrame)->toBe($at);
      $playback->resume();
    }
  }
  $playback->update(10);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue()
    ->and($playback->getShakeFraction($this->actor, false))->toBe(0.0);
})->with(['caster', 'target', 'screen'])->with(['source', 'target']);

it('keeps terminal-only shake tracks out of graphical battler motion', function () {
  $effect = $this->library->compile('terminal-shake', ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [[
    'id' => 'shake', 'type' => 'shake', 'presentation' => 'terminal',
    'keyframes' => [['frame' => 0, 'duration' => 3, 'payload' => ['amplitude' => 2]]],
  ]]], true, EffectPresentation::TERMINAL);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update(($playback->plan->phases['target']['start'] + 3) / BattleCommandTimeline::FPS);
  expect($playback->getActiveSegments(false, true))->toHaveCount(1)
    ->and($playback->getShakeFraction($this->targets[0], false))->toBe(0.0);
});

it('masks target flashes to each current pose instead of painting rectangular battler washes', function () {
  $effect = $this->library->compile('flash', ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [[
    'id' => 'flash', 'type' => 'flash', 'keyframes' => [['frame' => 0, 'duration' => 3, 'color' => 'red']],
  ]]], true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, [...$this->targets, $this->targets[0]], BattlePoseRole::ATTACK,
    static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $images = [];
  foreach ($this->targets as $subject) {
    $identity = spl_object_id($subject);
    $images[$identity] = new CanvasImage('pose-' . $identity, 'spark.png', $this->bounds[$identity], 100,
      new SpriteSourceRect(8, 0, 8, 2), .4);
  }
  $normal = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false, $images);
  expect($normal->textLayers)->toBeEmpty()->and($normal->composites)->toHaveCount(2);
  foreach ($normal->composites as $index => $tint) {
    expect($tint->destination)->toEqual($this->bounds[spl_object_id($this->targets[$index])])
      ->and($tint->layer)->toBe(101)->and($tint->opacity)->toBe(.4)
      ->and($tint->operations[0]->data['masks'][0])->toBe(['type' => 'image_alpha', 'invert' => false,
        'asset' => 'spark.png', 'destination' => ['x' => 0.0, 'y' => 0.0, 'width' => 48.0, 'height' => 96.0],
        'source' => ['x' => .5, 'y' => 0.0, 'width' => .5, 'height' => 1.0]]);
  }
  expect(GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, true, $images)->composites)->toBeEmpty()
    ->and(GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false)->composites)->toBeEmpty();
});

it('draws a screen-scope flash only once even if its stage targets multiple battlers', function () {
  $effect = $this->library->compile('screen-flash', ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [[
    'id' => 'flash', 'type' => 'flash', 'keyframes' => [['frame' => 0, 'duration' => 3, 'payload' => ['scope' => 'screen']]],
  ]]], true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $normal = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect($normal->textLayers)->toHaveCount(4)->and($normal->composites)->toBeEmpty()
    ->and(array_all($normal->textLayers, fn($layer) => str_contains($layer->id, '-screen')))->toBeTrue();
});

it('shares concurrent flash and reaction tints per recipient within the canvas composite budget', function () {
  $effect = $this->library->compile('flash', ['fps' => 10, 'lengthFrames' => 10, 'tracks' => [[
    'id' => 'flash', 'type' => 'flash', 'keyframes' => [['frame' => 0, 'duration' => 10, 'color' => 'white']],
  ]], 'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 0]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'hit']], true);
  $recipients = $images = $bounds = [];
  foreach (range(0, 4) as $index) {
    $recipient = $recipients[] = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
    $identity = spl_object_id($recipient);
    $bounds[$identity] = new CanvasRectangle($index * 80, 100, 48, 96);
    $images[$identity] = new CanvasImage('pose-' . $identity, 'spark.png', $bounds[$identity], 100);
  }
  $hits = 0;
  $playback = null;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $recipients, BattlePoseRole::ATTACK,
    function () use (&$hits, &$playback, $recipients) {
      $hits++;
      foreach ($recipients as $recipient) { $playback->setReaction($recipient, BattlePoseRole::DAMAGE); }
    }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $frame = GraphicalBattleEffects::compose($playback, $this->layout, $bounds, $this->root, false, $images);
  expect($frame->composites)->toHaveCount(1)->and($frame->composites[0]->operations)->toHaveCount(10)
    ->and($frame->textLayers)->toBeEmpty()->and($hits)->toBe(1);
  foreach (array_chunk($frame->composites[0]->operations, 2) as $index => $operations) {
    expect(array_column(array_column($operations, 'data'), 'opacity'))->toBe([.22, .16])
      ->and($operations[0]->data['destination']['x'])->toBe($index * 80.0)
      ->and($operations[1]->data['masks'][0]['destination'])->toBe($operations[0]->data['destination']);
  }
  $calm = GraphicalBattleEffects::compose($playback, $this->layout, $bounds, $this->root, true, $images);
  expect($calm->composites)->toBeEmpty()->and($hits)->toBe(1)->and($playback->presentationFailure)->toBeNull();
});

it('rejects malformed battle data at the definition boundary', function (array $frame) {
  $data = ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [['id' => 'bad', 'type' => 'text',
    'keyframes' => [['frame' => 0, ...$frame]]]]];
  expect(fn() => $this->library->compile('bad', $data, true))->toThrow(InvalidArgumentException::class);
})->with([[['content' => []]], [['position' => ['x' => 0, 'y' => 'north']]], [['visible' => 'yes']],
  [['payload' => 'invalid']], [['payload' => ['color' => []]]], [['payload' => ['anchor' => 'arbitrary']]]]);

it('does not load a timeline symlink outside its asset root', function () {
  $outside = tempnam(sys_get_temp_dir(), 'ichiloto-outside-effect-');
  file_put_contents($outside, '<?php throw new RuntimeException("must not execute");');
  mkdir($this->root . '/Animations/escape', 0777, true);
  symlink($outside, $this->root . '/Animations/escape/escape.timeline.php');
  try { expect(fn() => $this->library->load('escape', true))->toThrow(InvalidArgumentException::class, 'inside the asset root'); }
  finally { unlink($outside); }
});

it('retains every recipient and effect when group tinting exceeds the per-image surface count', function (bool $reduced) {
  $effect = $this->library->compile('group-impact', ['fps' => 10, 'lengthFrames' => 10, 'tracks' => [
    ['id' => 'flash', 'type' => 'flash', 'keyframes' => [['frame' => 0, 'duration' => 10, 'color' => 'white']]],
    ['id' => 'impact', 'type' => 'image', 'asset' => 'spark.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'cells' => ['width' => 1, 'height' => 1], 'keyframes' => [['frame' => 0, 'duration' => 10, 'sourceFrame' => 1]]],
  ], 'cues' => [['id' => 'hit', 'type' => 'applyEffect', 'frame' => 0]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'hit']], true);
  $recipients = $images = $bounds = [];
  foreach (range(0, 11) as $index) {
    $recipient = $recipients[] = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
    $identity = spl_object_id($recipient);
    $bounds[$identity] = new CanvasRectangle(($index % 6) * 70 + .25, intdiv($index, 6) * 100 + .5, 48, 96);
    $images[$identity] = new CanvasImage('pose-' . $identity, 'spark.png', $bounds[$identity], 100,
      new SpriteSourceRect(8, 0, 8, 2));
  }
  $hits = 0;
  $playback = null;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $recipients, BattlePoseRole::ATTACK,
    function () use (&$hits, &$playback, $recipients) {
      $hits++;
      foreach ($recipients as $recipient) { $playback->setReaction($recipient, BattlePoseRole::DAMAGE); }
    }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  $frame = GraphicalBattleEffects::compose($playback, $this->layout, $bounds, $this->root, $reduced, $images);
  expect($frame->images)->toHaveCount(12)->and($hits)->toBe(1)->and($playback->presentationFailure)->toBeNull();
  if ($reduced) { expect($frame->composites)->toBeEmpty(); }
  else {
    expect($frame->composites)->toHaveCount(1)->and($frame->composites[0]->operations)->toHaveCount(24);
    foreach ($frame->composites[0]->operations as $index => $operation) {
      expect($operation->data['masks'][0]['source']['x'])->toBe(.5)
        ->and($operation->data['destination']['width'])->toBe(48.0)
        ->and($operation->data['destination']['x'])->toBe((intdiv($index, 2) % 6) * 70 + .25);
    }
    // A batching surface must not protect its empty gaps as if they were art.
    expect(\Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationPlacement::isClear(
      new CanvasRectangle(49, 1, 20, 80), $frame->getOverlayProtection()))->toBeTrue();
  }
  $playback->update(100);
  $finished = GraphicalBattleEffects::compose($playback, $this->layout, $bounds, $this->root, $reduced, $images);
  expect($finished->images)->toBeEmpty()->and($finished->composites)->toBeEmpty()->and($hits)->toBe(1);
})->with([false, true]);

it('refuses overlapping non-image keyframes before they can produce duplicate canvas identities', function () {
  $data = ['fps' => 10, 'lengthFrames' => 4, 'tracks' => [['id' => 'overlap', 'type' => 'text',
    'keyframes' => [['frame' => 0, 'duration' => 3, 'content' => 'old'], ['frame' => 2, 'content' => 'new']]]]];
  expect(fn() => $this->library->compile('overlap', $data, true))
    ->toThrow(InvalidArgumentException::class, 'overlapping keyframes');
});

it('omits only optional tints when faded surfaces exceed the shared raster count', function (bool $reduced) {
  $effect = $this->library->compile('faded-impact', ['fps' => 10, 'lengthFrames' => 10, 'tracks' => [
    ['id' => 'flash', 'type' => 'flash', 'keyframes' => [['frame' => 0, 'duration' => 10, 'color' => 'white']]],
    ['id' => 'impact', 'type' => 'image', 'asset' => 'spark.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'keyframes' => [['frame' => 0, 'duration' => 10, 'sourceFrame' => 1]]],
  ]], true);
  $recipients = $images = $bounds = [];
  foreach (range(0, 8) as $index) {
    $recipient = $recipients[] = new Character('Twin', 1, new Stats(currentHp: 100, totalHp: 100));
    $identity = spl_object_id($recipient);
    $bounds[$identity] = new CanvasRectangle($index * 48, 40, 32, 48);
    $images[$identity] = new CanvasImage('pose-' . $identity, 'spark.png', $bounds[$identity], 100,
      new SpriteSourceRect(8, 0, 8, 2), opacity: .4);
  }
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $recipients, BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120 + .01);
  $frame = GraphicalBattleEffects::compose($playback, $this->layout, $bounds, $this->root, $reduced, $images);
  expect($frame->images)->toHaveCount(9)->and($frame->composites)->toBeEmpty();
  if ($reduced) { expect($playback->presentationFailure)->toBeNull(); }
  else { expect($playback->presentationFailure?->getMessage())->toContain('at most 8 entries'); }
  $playback->update(100);
  $playback->update(100);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('honors clear-before-draw and authored depth in both presenters', function () {
  $data = ['fps' => 10, 'lengthFrames' => 3, 'tracks' => [
    ['id' => 'replacement', 'type' => 'text', 'keyframes' => [['frame' => 0, 'duration' => 3,
      'content' => 'new', 'zIndex' => 2, 'payload' => ['clearBeforeDraw' => true]]]],
    ['id' => 'old', 'type' => 'text', 'keyframes' => [['frame' => 0, 'duration' => 3, 'content' => 'old', 'zIndex' => 1]]],
  ]];
  $effect = $this->library->compile('replacement', $data, true);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, [$this->targets[0]], BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  expect(array_column(array_column($playback->getActiveSegments(false, true), 'drawCommands'), 0))
    ->toHaveCount(1)->and($playback->getActiveSegments(false, true)[0]['drawCommands'][0]['content'])->toBe('new');
  $text = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false)->textLayers;
  expect($text)->toHaveCount(1)->and($text[0]->runs[0]->text)->toBe('new');
});

it('keeps the legacy terminal effect when graphical target art is image-only', function () {
  $image = $this->library->compile('spark', battleImageEffectData(), true);
  $legacy = \Ichiloto\Engine\Animations\Timelines\LegacyAnimationTimeline::compile(new Animation(2, 'Legacy',
    frames: [new \Ichiloto\Engine\Animations\AnimationFrame(1, [new \Ichiloto\Engine\Animations\AnimationCell('*', 0, 0)])]));
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $image, terminalTarget: $legacy), $this->actor, [$this->targets[0]], BattlePoseRole::ATTACK,
    static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  expect(array_column($playback->getActiveSegments(false, true), 'layer'))->toContain('glyph')
    ->and(array_column($playback->getActiveSegments(), 'layer'))->toBe(['image']);
  $frame = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false);
  expect($frame->images)->toHaveCount(1)->and($frame->textLayers)->toBeEmpty();
  $text = $this->library->compile('authored', ['fps' => 10, 'lengthFrames' => 1, 'tracks' => [[
    'id' => 'authored', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => '+']],
  ]]], true);
  expect(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $text, terminalTarget: $legacy)->terminalTarget)->toBeNull();
});

it('round trips animation effect identities without freezing sheet dimensions or bytes', function () {
  $animation = Animation::fromArray(['id' => 7, 'name' => 'Cast', 'sourceEffect' => 'cast-aura', 'targetEffect' => 'spark-hit']);
  expect(Animation::fromArray($animation->toArray())->toArray())->toBe($animation->toArray())
    ->and($animation->sourceEffect)->toBe('cast-aura')->and($animation->targetEffect)->toBe('spark-hit');
  expect(fn() => new Animation(1, 'Unsafe', sourceEffect: '../outside'))->toThrow(InvalidArgumentException::class);
});

it('selects individual effect counterparts without hiding shared narrative or unrelated graphical glyphs', function (bool $reduced) {
  $data = battleImageEffectData();
  array_push($data['tracks'],
    ['id' => 'terminal-slash', 'type' => 'glyph', 'presentation' => 'terminal',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => '/']]],
    ['id' => 'narrative', 'type' => 'text',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => 'Impact']]],
    ['id' => 'graphical-rune', 'type' => 'glyph', 'presentation' => 'graphical',
      'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => 'o']]]);
  $graphical = $this->library->compile('mixed', $data, true);
  $terminal = $this->library->compile('mixed', $data, true, EffectPresentation::TERMINAL);
  expect(array_column($graphical->playbackSegments, 'layer'))->toBe(['glyph', 'image', 'text', 'image'])
    ->and(array_column($terminal->playbackSegments, 'layer'))->toBe(['glyph', 'text']);
  foreach ([false => $graphical, true => $terminal] as $isTerminal => $effect) {
    $hits = 0;
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
      target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC,
      function () use (&$hits) { $hits++; }, static fn() => null);
    $playback->update($playback->plan->phases['target']['start'] / 120);
    $contents = array_column(array_merge(...array_column($playback->getActiveSegments($reduced, (bool)$isTerminal), 'drawCommands')), 'content');
    expect($contents)->toContain('Impact', $isTerminal ? '/' : 'o')
      ->and($contents)->not->toContain($isTerminal ? 'o' : '/')->and($hits)->toBe(0);
    $playback->update(100);
    expect($hits)->toBe(1)->and($playback->getActiveSegments($reduced, (bool)$isTerminal))->toBeEmpty();
  }
})->with([false, true]);

it('allows a later independent stroke to be dormant in the sequence rest presentation', function (bool $reduced) {
  $data = battleImageEffectData();
  $first = $data['tracks'][0];
  $second = [...$first, 'id' => 'second-stroke', 'keyframes' => [
    ['frame' => 3, 'sourceFrame' => 0], ['frame' => 4, 'sourceFrame' => 1]]];
  $data = [...$data, 'fps' => 12, 'lengthFrames' => 5, 'tracks' => [$first, $second],
    'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 3]]];
  $effect = $this->library->compile('two-strokes', $data, true);
  $hits = 0;
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, [$this->targets[0]], BattlePoseRole::ATTACK,
    function () use (&$hits) { $hits++; }, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  for ($frame = 0; $frame < 5; $frame++) {
    $canvas = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, $reduced);
    expect($canvas->images)->toHaveCount(!$reduced && $frame === 2 ? 0 : 1)
      ->and($hits)->toBe($frame >= 3 ? 1 : 0);
    if ($canvas->images !== []) {
      expect($canvas->images[0]->id)->toContain($reduced || $frame < 3 ? 'target-spark-' : 'target-second-stroke-')
        ->and($canvas->images[0]->sourceRect->x)->toBe($reduced ? 8 : ($frame % 3) * 8);
    }
    $playback->update(1 / 12);
  }
  $playback->update(100);
  expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
})->with([false, true]);

it('still refuses image sequences with no useful authored rest presentation', function (bool $battle) {
  $data = battleImageEffectData();
  unset($data['cues'], $data['effectTiming']);
  $data['tracks'][0]['keyframes'] = [['frame' => 0, 'sourceFrame' => 0]];
  expect(fn() => $this->library->compile('no-rest', $data, $battle))
    ->toThrow(InvalidArgumentException::class, 'no authored rest presentation');
})->with([false, true]);

it('retains independent presentation cadence rest and cues under one stable effect identity', function () {
  $terminal = ['fps' => 10, 'lengthFrames' => 6, 'restFrame' => 4,
    'tracks' => [['id' => 'terminal', 'type' => 'glyph', 'keyframes' => [
      ['frame' => 0, 'duration' => 3, 'content' => '+'], ['frame' => 3, 'duration' => 3, 'content' => '*']]]],
    'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 4]]];
  $graphical = [...battleImageEffectData(), 'fps' => 12];
  $data = ['presentations' => ['terminal' => $terminal, 'graphical' => $graphical]];
  foreach ([EffectPresentation::TERMINAL, EffectPresentation::GRAPHICAL] as $presentation) {
    $effect = $this->library->compile('shared-identity', $data, true, $presentation);
    $isTerminal = $presentation === EffectPresentation::TERMINAL;
    expect($effect->sourceId)->toBe('shared-identity')->and($effect->fps)->toBe($isTerminal ? 10 : 12)
      ->and($effect->defaults['lengthFrames'])->toBe($isTerminal ? 6 : 2)
      ->and($effect->defaults['restFrame'])->toBe($isTerminal ? 4 : 1)
      ->and($effect->cueSchedule[0]['frame'])->toBe($isTerminal ? 4 : 1);
    $hits = 0;
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
      target: $effect), $this->actor, $this->targets, BattlePoseRole::HEAL,
      function () use (&$hits) { $hits++; }, static fn() => null);
    expect($playback->plan->phases['target']['length'])->toBe($isTerminal ? 72 : 20);
    $start = $playback->plan->phases['target']['start'];
    $impact = $start + ($isTerminal ? 48 : 10);
    $playback->update(($impact - 1) / 120);
    expect($hits)->toBe(0);
    $playback->update(1 / 120);
    expect($hits)->toBe(1);
    $playback->update(100);
    expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue();
  }
});

it('keeps terminal compilation and cache independent from unavailable optional graphical assets', function () {
  $image = battleImageEffectData();
  $image['tracks'][0]['asset'] = 'missing.png';
  $terminal = ['fps' => 10, 'lengthFrames' => 1,
    'tracks' => [['id' => 'terminal', 'type' => 'glyph', 'keyframes' => [['frame' => 0, 'content' => '+']]]]];
  $data = ['presentations' => ['terminal' => $terminal, 'graphical' => $image]];
  mkdir($this->root . '/Animations/independent', 0777, true);
  file_put_contents($this->root . '/Animations/independent/independent.timeline.php', '<?php return ' . var_export($data, true) . ';');
  $effect = $this->library->load('independent', true, EffectPresentation::TERMINAL);
  expect($effect->fps)->toBe(10)->and($effect->playbackSegments[0]['layer'])->toBe('glyph')
    ->and(fn() => $this->library->load('independent', true))->toThrow(RuntimeException::class)
    ->and($this->library->load('independent', true, EffectPresentation::TERMINAL))->toBe($effect);
  $flat = [...$image, 'tracks' => [...$image['tracks'], ...$terminal['tracks']]];
  expect($this->library->compile('flat-independent', $flat, true, EffectPresentation::TERMINAL)->playbackSegments)
    ->toHaveCount(1)->and(fn() => $this->library->compile('flat-independent', $flat, true))->toThrow(RuntimeException::class);
});

it('keeps image-only effects out of terminal asset dependencies and allows its numeric fallback', function () {
  $data = battleImageEffectData();
  unset($data['cues'], $data['effectTiming']);
  $data['tracks'][0]['asset'] = 'missing.png';
  $effect = $this->library->compile('optional-art', $data, true, EffectPresentation::TERMINAL);
  expect($effect->playbackSegments)->toBeEmpty()->and($effect->cueSchedule)->toBeEmpty();
  // The action loader retains its legacy target when a selection has no tracks or cues.
  expect(fn() => $this->library->compile('optional-art', $data, true))->toThrow(RuntimeException::class);
});

it('filters presentation before clear commands and does not suppress fallback for graphical-only glyphs', function () {
  $tracks = [
    ['id' => 'narrative', 'type' => 'text', 'keyframes' => [['frame' => 0, 'content' => 'Visible']]],
    ['id' => 'terminal-clear', 'type' => 'glyph', 'presentation' => 'terminal',
      'keyframes' => [['frame' => 0, 'content' => '/', 'zIndex' => 1, 'payload' => ['clearBeforeDraw' => true]]]],
  ];
  $effect = new CompiledEffectTimeline('mixed', '', fps: 10,
    playbackSegments: (new EffectTimelineCompiler())->compileTracks($tracks), defaults: ['lengthFrames' => 1]);
  $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect), $this->actor, $this->targets, BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
  $playback->update($playback->plan->phases['target']['start'] / 120);
  expect(array_column(array_merge(...array_column($playback->getActiveSegments(), 'drawCommands')), 'content'))->toBe(['Visible'])
    ->and(array_column(array_merge(...array_column($playback->getActiveSegments(false, true), 'drawCommands')), 'content'))->toBe(['/']);
  $tracks[1]['presentation'] = 'graphical';
  $effect->playbackSegments = (new EffectTimelineCompiler())->compileTracks([$tracks[1]]);
  expect(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
    target: $effect, terminalTarget: $effect)->terminalTarget)->toBe($effect);
});

it('round trips explicit summon track presentation through the common compiler', function () {
  $data = ['id' => 'one', 'type' => 'glyph', 'presentation' => 'terminal',
    'keyframes' => [['frame' => 0, 'content' => '+']]];
  $track = SummonCutsceneTrack::fromArray($data);
  expect($track->toArray()['presentation'])->toBe('terminal')
    ->and((new EffectTimelineCompiler())->compileTracks([$track->toArray()])[0]['presentation'])->toBe('terminal');
});

it('refuses unknown track presentation at both shared authoring boundaries', function (mixed $scope) {
  $data = ['id' => 'one', 'type' => 'glyph', 'presentation' => $scope, 'keyframes' => [['frame' => 0, 'content' => '+']]];
  expect(fn() => $this->library->compile('bad', ['fps' => 10, 'lengthFrames' => 1, 'tracks' => [$data]], true))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => SummonCutsceneTrack::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(['renderer-name' => 'gpui', 'empty' => '', 'number' => 1]);

it('refuses incomplete nested or mixed presentation sequence ownership', function (array $data) {
  expect(fn() => $this->library->compile('bad-variants', $data, true))->toThrow(InvalidArgumentException::class);
})->with([
  [['presentations' => ['graphical' => []]]],
  [['presentations' => ['terminal' => [], 'graphical' => [], 'unknown' => []]]],
  [['presentations' => ['terminal' => ['presentations' => []], 'graphical' => []]]],
  [['fps' => 10, 'presentations' => ['terminal' => [], 'graphical' => []]]],
]);
