<?php

use Ichiloto\Engine\Battle\Presentation\{BattleArenaDefinition, BattleCanvasLayout, BattleFooting, BattleGround, BattleStageSlot, BattleStaging, BattlerSlot};
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasImage, CanvasRectangle};
use Ichiloto\Engine\Battle\Presentation\{BattleFormationLayout, BattlerArtwork};

function bodyFootingScene(float $perspective = 1): array
{
  $layout = new BattleCanvasLayout(200, 200, uiCellWidth: 1, uiCellHeight: 1, battlerArea: new CanvasRectangle(0, 0, 200, 120),
    enemyArea: new CanvasRectangle(0, 0, 100, 120), partyArea: new CanvasRectangle(100, 0, 100, 120));
  $footing = new BattleFooting([[100, 30], [200, 30], [200, 100], [40, 100]]);
  $arena = new BattleArenaDefinition('Sloping shore', new CanvasImage('shore', 'shore.png', new CanvasRectangle(0, 0, 200, 200)),
    ground: new BattleGround(30, 100, $perspective), footing: $footing);
  return [$layout, $arena];
}

it('fits the whole body and its feet together on a narrow shore without changing body scale', function (float $perspective, float $pivot) {
  [$layout, $arena] = bodyFootingScene($perspective);
  $body = ['width' => 40.0, 'height' => 40.0, 'pivotX' => $pivot, 'pivotY' => .8];
  $staging = BattleStaging::stage($layout, $arena, [], [new BattleStageSlot(100, 50, 40, 40, 1.1)], [$body]);
  $slot = $staging->enemies[0];
  $run = $arena->footing->getFootedRun(EnemyHabitat::LAND, $slot->y);
  expect($slot->x)->toBeGreaterThanOrEqual($run[0] - 1e-6)->toBeLessThanOrEqual($run[1] + 1e-6)
    ->and($slot->x - $pivot * 40 * $slot->displayScale)->toBeGreaterThanOrEqual(-1e-6)
    ->and($slot->x + (1 - $pivot) * 40 * $slot->displayScale)->toBeLessThanOrEqual(100 + 1e-6)
    ->and($slot->displayScale)->toEqualWithDelta(1.1 * $staging->ground->getDepthScale($slot->y), 1e-9)
    ->and([$slot->width, $slot->height])->toBe([40.0, 40.0]);
})->with([[1.0, .5], [.7, .2], [1.3, .8]]);

it('keeps a valid height and finds the nearest exact height when only a touching point fits', function () {
  [$layout, $arena] = bodyFootingScene();
  $body = ['width' => 40.0, 'height' => 40.0];
  $staging = BattleStaging::stage($layout, $arena, [], [new BattleStageSlot(100, 50, 40, 40)], [$body]);
  expect($staging->enemies[0]->x)->toEqualWithDelta(80, 1e-9)
    ->and($staging->enemies[0]->y)->toEqualWithDelta(30 + 20 * 70 / 60, 1e-9);
  $moved = BattleStaging::fitMovedSlot($layout, $arena, false, EnemyHabitat::LAND, new BattlerSlot(99, 90, 40, 40), $body);
  expect($moved->y)->toBe(90.0)->and($moved->x)->toBe(80.0);
});

it('does not turn a concave cut across empty ground into usable footing', function () {
  $posts = new BattleFooting([[0, 0], [20, 0], [20, 80], [90, 80], [90, 0], [100, 0], [100, 100], [0, 100]]);
  $limits = [[1.0, 0.0, -30.0], [-1.0, 0.0, 70.0], [0.0, 1.0, 0.0], [0.0, -1.0, 60.0]];
  expect($posts->getFootedPoint(EnemyHabitat::LAND, 50, 40, $limits))->toBeNull();
  $limits[3][2] = 100.0;
  expect($posts->getFootedPoint(EnemyHabitat::LAND, 50, 40, $limits))->toBe([50.0, 80.0]);
});

it('chooses the nearest feasible air habitat and preserves water-only and missing-habitat constraints', function () {
  $footing = new BattleFooting([[0, 80], [100, 80], [100, 100], [0, 100]], [[0, 20], [100, 20], [100, 40], [0, 40]]);
  $limits = [[1.0, 0.0, -30.0], [-1.0, 0.0, 70.0], [0.0, 1.0, 0.0], [0.0, -1.0, 100.0]];
  expect($footing->getFootedPoint(EnemyHabitat::AIR, 50, 45, $limits))->toBe([50.0, 40.0])
    ->and($footing->getFootedPoint(EnemyHabitat::LAND, 50, 45, $limits))->toBe([50.0, 80.0])
    ->and($footing->getFootedPoint(EnemyHabitat::WATER, 50, 45, $limits))->toBe([50.0, 40.0])
    ->and((new BattleFooting())->getFootedPoint(EnemyHabitat::WATER, 50, 45, $limits))->toBeNull();
});

it('leaves an impossible body at its original scale for clearance diagnostics', function () {
  [$layout, $arena] = bodyFootingScene();
  $staging = BattleStaging::stage($layout, $arena, [], [new BattleStageSlot(80, 0, 140, 40)],
    [['width' => 140.0, 'height' => 40.0]]);
  expect($staging->enemies[0]->displayScale)->toBe(1.0)
    ->and($staging->enemies[0]->width)->toBe(140.0);
});

it('reads visible body clearance from current pixels and retains the independent padded frame and pivot', function () {
  $root = sys_get_temp_dir() . '/ichiloto-body-footing-' . bin2hex(random_bytes(6));
  mkdir($root);
  $image = imagecreatetruecolor(100, 100);
  imagealphablending($image, false);
  imagesavealpha($image, true);
  imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
  imagefilledrectangle($image, 30, 20, 69, 79, imagecolorallocatealpha($image, 60, 80, 100, 0));
  imagepng($image, $root . '/body.png');
  try {
    $body = BattleFormationLayout::getStandingSize(new BattlerArtwork('body.png', 100, 100, 50, 80), null, null, null, null, $root);
    expect($body['visible'])->toBe(['left' => 20.0, 'right' => 20.0, 'top' => 60.0, 'bottom' => 0.0])
      ->and([$body['width'], $body['height'], $body['pivotX'], $body['pivotY']])->toBe([100.0, 100.0, .5, .8]);
    [$layout, $arena] = bodyFootingScene();
    $slot = BattleStaging::fitMovedSlot($layout, $arena, false, EnemyHabitat::LAND, new BattlerSlot(90, 90, 100, 100), $body);
    expect($slot->y)->toBe(90.0)->and($slot->x)->toBe(80.0)->and($slot->displayScale)->toBe(1.0);
    $missing = BattleFormationLayout::getStandingSize(null, null, null, null, null, $root);
    expect($missing['visible'])->toBeNull();
  } finally {
    unlink($root . '/body.png');
    rmdir($root);
  }
});
