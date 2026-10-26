<?php

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleCommandPlayback;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

it('keeps two strokes visible and independently oriented for recipients on either side with one resolution', function (bool $reduced) {
  $root = sys_get_temp_dir() . '/ichiloto-direction-' . bin2hex(random_bytes(5));
  mkdir($root);
  writeTestPng($root . '/stroke.png', 8, 4);
  try {
    $tracks = [];
    foreach ([0, 1] as $stroke) {
      $tracks[] = ['id' => 'stroke-' . $stroke, 'type' => 'image', 'asset' => 'stroke.png',
        'sheet' => ['columns' => 2, 'rows' => 1], 'cells' => ['width' => 1, 'height' => 1],
        'anchor' => 'target', 'facing' => 'east', 'keyframes' => [
          ['frame' => $stroke * 3, 'duration' => 2, 'sourceFrame' => 1, 'flipY' => $stroke === 1,
            'position' => ['x' => 1, 'y' => 0]],
        ]];
    }
    $effect = new EffectTimelineLibrary($root)->compile('two-strokes',
      ['fps' => 10, 'lengthFrames' => 5, 'restFrame' => 0, 'tracks' => $tracks], true);
    $actor = new Character('Same name', 1, new Stats());
    $left = new Character('Same name', 1, new Stats());
    $right = new Character('Same name', 1, new Stats());
    $hits = 0;
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
      $actor, [$left, $right], BattlePoseRole::ATTACK, function () use (&$hits) { $hits++; }, static fn() => null);
    $bounds = [spl_object_id($actor) => new CanvasRectangle(450, 200, 40, 80),
      spl_object_id($left) => new CanvasRectangle(150, 200, 40, 80),
      spl_object_id($right) => new CanvasRectangle(750, 200, 40, 80)];
    $layout = new BattleCanvasLayout(1440, 840);
    foreach ([0, 3] as $frame) {
      $wanted = $playback->plan->phases['target']['start'] + $frame * 12;
      $playback->update(($wanted - $playback->session->currentFrame) / 120);
      $images = GraphicalBattleEffects::compose($playback, $layout, $bounds, $root, $reduced)->images;
      expect($images)->toHaveCount(2)
        ->and($images[0]->flipX)->toBeTrue()->and($images[1]->flipX)->toBeFalse()
        ->and($images[0]->flipY)->toBe(!$reduced && $frame === 3)
        ->and($images[0]->sourceRect->x)->toBe(4)
        ->and($images[0]->destination->x)->toBe(136.0)
        ->and($images[1]->destination->x)->toBe(756.0)
        ->and($playback->presentationFailure)->toBeNull()->and($hits)->toBe(0);
    }
    $limited = GraphicalBattleEffects::compose($playback, $layout, $bounds, $root, $reduced, imageFlips: false);
    expect($limited->images)->toHaveCount($reduced ? 1 : 0)
      ->and($playback->presentationFailure)->not->toBeNull()->and($hits)->toBe(0);
    $playback->update(100);
    expect($hits)->toBe(1)->and($playback->isCompleted)->toBeTrue()
      ->and(GraphicalBattleEffects::compose($playback, $layout, $bounds, $root, $reduced)->images)->toBeEmpty();
  } finally {
    unlink($root . '/stroke.png');
    rmdir($root);
  }
})->with([false, true]);

it('contains cinematic frames in different battle areas using current replaceable art without resolving combat', function (bool $reduced) {
  $root = sys_get_temp_dir() . '/ichiloto-battle-fit-' . bin2hex(random_bytes(5));
  mkdir($root);
  writeTestPng($root . '/cinematic.png', 32, 9);
  try {
    $effect = new EffectTimelineLibrary($root)->compile('cinematic',
      ['fps' => 10, 'lengthFrames' => 1, 'restFrame' => 0, 'tracks' => [[
        'id' => 'wide', 'type' => 'image', 'asset' => 'cinematic.png', 'anchor' => 'screen',
        'fit' => 'contain', 'sheet' => ['columns' => 2, 'rows' => 1],
        'cells' => ['width' => 64, 'height' => 64],
        'keyframes' => [['frame' => 0, 'sourceFrame' => 1]],
      ]]], true);
    $actor = new Character('Caster', 1, new Stats());
    $target = new Character('Target', 1, new Stats());
    $hits = 0;
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
      $actor, [$target], BattlePoseRole::SUMMON, function () use (&$hits) { $hits++; }, static fn() => null);
    $playback->update($playback->plan->phases['target']['start'] / 120);
    foreach ([[32, 9, 1440, 840], [32, 9, 1350, 720], [18, 16, 1440, 840]] as [$width, $height, $areaWidth, $areaHeight]) {
      writeTestPng($root . '/cinematic.png', $width, $height);
      $layout = new BattleCanvasLayout($areaWidth, $areaHeight);
      $images = GraphicalBattleEffects::compose($playback, $layout, [], $root, $reduced)->images;
      expect($images)->toHaveCount(1);
      $image = $images[0];
      $sourceWidth = $width / 2;
      $scale = min($areaWidth / $sourceWidth, $areaHeight / $height);
      expect($image->sourceRect->toArray())->toBe(['x' => (int)$sourceWidth, 'y' => 0, 'width' => (int)$sourceWidth, 'height' => $height])
        ->and($image->destination->width)->toEqualWithDelta($sourceWidth * $scale, .000001)
        ->and($image->destination->height)->toEqualWithDelta($height * $scale, .000001)
        ->and($image->destination->x + $image->destination->width / 2)->toEqualWithDelta($areaWidth / 2, .000001)
        ->and($image->destination->y + $image->destination->height / 2)->toEqualWithDelta($areaHeight / 2, .000001)
        ->and($playback->presentationFailure)->toBeNull()->and($hits)->toBe(0);
    }
  } finally {
    unlink($root . '/cinematic.png');
    rmdir($root);
  }
})->with([false, true]);

it('keeps contained directional images on each stable ground point rather than a pose rectangle edge', function (bool $reduced) {
  $root = sys_get_temp_dir() . '/ichiloto-ground-fit-' . bin2hex(random_bytes(5));
  mkdir($root);
  writeTestPng($root . '/effect.png', 128, 32);
  try {
    $effect = new EffectTimelineLibrary($root)->compile('ground-fit',
      ['fps' => 10, 'lengthFrames' => 1, 'restFrame' => 0, 'tracks' => [[
        'id' => 'aura', 'type' => 'image', 'asset' => 'effect.png', 'anchor' => 'target',
        'attachment' => 'ground', 'pivot' => ['x' => .25, 'y' => 1], 'facing' => 'west',
        'fit' => 'contain', 'sheet' => ['columns' => 2, 'rows' => 1],
        'cells' => ['width' => 3, 'height' => 4],
        'keyframes' => [['frame' => 0, 'sourceFrame' => 1]],
      ]]], true);
    $actor = new Character('Caster', 1, new Stats());
    $left = new Character('Left', 1, new Stats());
    $right = new Character('Right', 1, new Stats());
    $playback = new BattleCommandPlayback(new BattleCommandTimeline(new BattleTurnTimings(.1, .1, .1, .1, .1, .1, .1), target: $effect),
      $actor, [$left, $right], BattlePoseRole::MAGIC, static fn() => null, static fn() => null);
    $playback->update($playback->plan->phases['target']['start'] / 120);
    $bounds = [spl_object_id($actor) => new CanvasRectangle(450, 200, 40, 80),
      spl_object_id($left) => new CanvasRectangle(150, 200, 40, 80),
      spl_object_id($right) => new CanvasRectangle(750, 200, 40, 80)];
    $ground = [spl_object_id($actor) => new Vector2(500, 300), spl_object_id($left) => new Vector2(100, 420),
      spl_object_id($right) => new Vector2(900, 520)];
    $images = GraphicalBattleEffects::compose($playback, new BattleCanvasLayout(1440, 840), $bounds, $root,
      $reduced, groundAnchors: $ground)->images;
    expect($images)->toHaveCount(2)
      ->and($images[0]->destination->toArray())->toBe(['x' => 64.0, 'y' => 348.0, 'width' => 144.0, 'height' => 72.0])
      ->and($images[1]->destination->toArray())->toBe(['x' => 792.0, 'y' => 448.0, 'width' => 144.0, 'height' => 72.0])
      ->and($images[0]->flipX)->toBeFalse()->and($images[1]->flipX)->toBeTrue()
      ->and($playback->presentationFailure)->toBeNull();
  } finally {
    unlink($root . '/effect.png');
    rmdir($root);
  }
})->with([false, true]);
