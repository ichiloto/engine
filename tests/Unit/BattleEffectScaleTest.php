<?php

use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Animations\EffectScale;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Inventory\ItemRecord;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Util\Debug;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** A synthetic ground-attached strike drawn once at its largest size, with a glyph counterpart. */
function scaledStrikeData(array $position = ['x' => 0, 'y' => 0]): array
{
  return ['fps' => 5, 'lengthFrames' => 2, 'restFrame' => 1, 'tracks' => [
    ['id' => 'strike', 'type' => 'image', 'asset' => 'strike.png', 'sheet' => ['columns' => 2, 'rows' => 1],
      'cells' => ['width' => 4, 'height' => 4], 'fit' => 'contain', 'anchor' => 'target', 'attachment' => 'ground',
      'pivot' => ['x' => .5, 'y' => .9],
      'keyframes' => [['frame' => 0, 'sourceFrame' => 0, 'position' => $position], ['frame' => 1, 'sourceFrame' => 1, 'position' => $position]]],
    ['id' => 'glyph', 'type' => 'glyph', 'anchor' => 'target', 'keyframes' => [['frame' => 0, 'duration' => 2, 'content' => "\\|/\n-*-"]]],
  ], 'cues' => [['id' => 'impact', 'type' => 'applyEffect', 'frame' => 1]],
    'effectTiming' => ['mode' => 'cue', 'cueId' => 'impact']];
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-effect-scale-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  $this->debug = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  writeTestPng($this->root . '/strike.png', 64, 32);
  $this->library = new EffectTimelineLibrary($this->root);
  $this->actor = new Character('Caster', 1, new Stats(currentHp: 100, totalHp: 100));
  $this->target = new Character('Foe', 1, new Stats(currentHp: 100, totalHp: 100));
  $this->bounds = [spl_object_id($this->actor) => new CanvasRectangle(700, 200, 48, 96),
    spl_object_id($this->target) => new CanvasRectangle(200, 200, 48, 96)];
  $this->ground = [spl_object_id($this->actor) => new Vector2(724, 296), spl_object_id($this->target) => new Vector2(224, 296)];
  $this->layout = new BattleCanvasLayout(960, 540, uiCellWidth: 6, uiCellHeight: 12);
  $this->playAt = function (float $scale, string $phase = 'target', array $position = ['x' => 0, 'y' => 0]): array {
    $effect = $this->library->compile('strike', scaledStrikeData($position), true);
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1),
      $effect, $effect, targetScale: $scale), $this->actor, [$this->target], BattlePoseRole::MAGIC,
      static fn() => null, static fn() => null);
    $playback->update($playback->plan->phases[$phase]['start'] / 120);
    $canvas = GraphicalBattleEffects::compose($playback, $this->layout, $this->bounds, $this->root, false,
      groundAnchors: $this->ground);
    return [$playback, $canvas];
  };
});

afterEach(function () {
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
  foreach ($this->debug as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
});

it('plays one target timeline at each binding scale about its ground pivot', function (float $scale, float $size) {
  [$playback, $canvas] = ($this->playAt)($scale);
  $image = $canvas->images[0]->destination;
  expect($canvas->images)->toHaveCount(1)
    ->and([$image->width, $image->height])->toEqualWithDelta([$size, $size], .001)
    // The pivot stays on the subject's ground point at every size.
    ->and($image->x + $image->width * .5)->toEqualWithDelta(224.0, .5)
    ->and($image->y + $image->height * .9)->toEqualWithDelta(296.0, .5)
    ->and($playback->presentationFailure)->toBeNull();
})->with([[.75, 144.0], [1.0, 192.0], [1.25, 240.0], [1.5, 288.0]]);

it('scales authored cell offsets with the image so the composition holds', function () {
  [, $plain] = ($this->playAt)(1.0, position: ['x' => 2, 'y' => -1]);
  [, $large] = ($this->playAt)(1.5, position: ['x' => 2, 'y' => -1]);
  $pivot = static fn($box): array => [$box->x + $box->width * .5, $box->y + $box->height * .9];
  expect($pivot($plain->images[0]->destination))->toEqualWithDelta([224.0 + 12, 296.0 - 12], .5)
    ->and($pivot($large->images[0]->destination))->toEqualWithDelta([224.0 + 18, 296.0 - 18], .5);
});

it('leaves the caster effect, glyph cells and terminal presentation at their authored size', function () {
  [, $source] = ($this->playAt)(1.5, 'source');
  expect($source->images[0]->destination->width)->toBe(192.0);
  [$plain, $plainCanvas] = ($this->playAt)(1.0);
  [$scaled, $scaledCanvas] = ($this->playAt)(1.5);
  $glyphs = static fn(BattleCommandPlayback $playback): array => array_values(array_filter(
    $playback->plan->timeline->playbackSegments, static fn(array $segment): bool => $segment['layer'] === 'glyph'));
  expect($glyphs($scaled))->toBe($glyphs($plain))
    ->and(array_map(static fn($layer) => $layer->toArray(), $scaledCanvas->textLayers))
    ->toBe(array_map(static fn($layer) => $layer->toArray(), $plainCanvas->textLayers))
    ->and($scaled->getActiveSegments(false, true))->toBe($plain->getActiveSegments(false, true));
});

it('keeps an unscaled binding byte-identical to the authored timeline', function () {
  $effect = $this->library->compile('strike', scaledStrikeData(), true);
  $timings = new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1);
  expect(new BattleCommandTimeline($timings, target: $effect, targetScale: 1.0)->timeline->toArray())
    ->toBe(new BattleCommandTimeline($timings, target: $effect)->timeline->toArray());
});

it('refuses a scale outside the supported range at every boundary', function (mixed $scale) {
  expect(fn() => EffectScale::readValue($scale))->toThrow(InvalidArgumentException::class);
  expect(fn() => SkillRecord::readSkill(['kind' => 'magic', 'name' => 'Spell', 'description' => '', 'icon' => '',
    'cost' => 1, 'cooldown' => 0, 'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0], 'animationScale' => $scale]))
    ->toThrow(InvalidArgumentException::class);
})->with([0, .1, 4.5, '1.5', INF, NAN]);

it('round trips the binding scale through skill and item records and resolves it per action', function () {
  $data = ['kind' => 'magic', 'name' => 'Grade III', 'description' => '', 'icon' => '', 'cost' => 1, 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '%2', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 0],
    'effects' => [], 'animationId' => 4, 'animationScale' => 1.25, 'effectType' => 'destructive'];
  $skill = SkillRecord::readSkill($data);
  expect($skill->animationScale)->toBe(1.25)->and(SkillRecord::writeSkill($skill)['animationScale'])->toBe(1.25)
    ->and(ActionAnimationResolver::getExplicitAnimationScale(new SkillBattleAction($skill)))->toBe(1.25);
  unset($data['animationScale']);
  $plain = SkillRecord::readSkill($data);
  expect($plain->animationScale)->toBe(1.0)->and(SkillRecord::writeSkill($plain))->not->toHaveKey('animationScale')
    ->and(ActionAnimationResolver::getExplicitAnimationScale(new SkillBattleAction(new MagicSkill('Bare', '', '', 1, 0))))->toBe(1.0)
    ->and(ActionAnimationResolver::getExplicitAnimationScale(null))->toBe(1.0);

  $item = ItemRecord::readItem(['kind' => 'item', 'id' => 'bomb', 'name' => 'Bomb', 'description' => '', 'icon' => '',
    'price' => 1, 'animationId' => 4, 'animationScale' => .5]);
  expect($item->animationScale)->toBe(.5)->and(ItemRecord::writeItem($item)['animationScale'])->toBe(.5)
    ->and(ActionAnimationResolver::getExplicitAnimationScale(new ItemBattleAction($item)))->toBe(.5);
});
