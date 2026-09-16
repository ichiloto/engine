<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleCursorPlacement;
use Ichiloto\Engine\Battle\Presentation\BattleTargetCursor;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

function targetCursorTextures(): array
{
  return array_combine(['above', 'left', 'right'], array_map(
    fn($name) => new CanvasNineSlice($name . '.png', new SpriteSourceRect(0, 0, 32, 32)), ['above', 'left', 'right']));
}

it('points inward from each configured side with a complete bounded motion envelope', function ($placement, $dx, $dy) {
  $cursor = new BattleTargetCursor(targetCursorTextures(), $placement);
  $battler = new CanvasRectangle(100, 100, 80, 120);
  $start = $cursor->layout($battler, 400, 400, 0, true);
  $end = $cursor->layout($battler, 400, 400, 0.6, true);
  expect($start['texture']->asset)->toBe($placement->value . '.png')
    ->and($end['bounds']->x)->toBe($start['bounds']->x + $dx)
    ->and($end['bounds']->y)->toBe($start['bounds']->y + $dy)
    ->and($end['envelope'])->toEqual($start['envelope']);
  foreach ([$start['bounds'], $end['bounds']] as $bounds) {
    expect($bounds->x)->toBeGreaterThanOrEqual($start['envelope']->x)
      ->and($bounds->y)->toBeGreaterThanOrEqual($start['envelope']->y)
      ->and($bounds->x + $bounds->width)->toBeLessThanOrEqual($start['envelope']->x + $start['envelope']->width)
      ->and($bounds->y + $bounds->height)->toBeLessThanOrEqual($start['envelope']->y + $start['envelope']->height);
  }
  expect($cursor->layout($battler, 400, 400, 0, false))->toEqual($cursor->layout($battler, 400, 400, 0.6, false));
})->with([[BattleCursorPlacement::ABOVE, 0.0, -4.0], [BattleCursorPlacement::LEFT, -4.0, 0.0], [BattleCursorPlacement::RIGHT, 4.0, 0.0]]);

it('changes orientation at an edge instead of clamping a pointer away from its target', function () {
  $cursor = new BattleTargetCursor(targetCursorTextures());
  $layout = $cursor->layout(new CanvasRectangle(2, 2, 40, 60), 200, 200);
  expect($layout['texture']->asset)->toBe('right.png')->and($layout['bounds']->x)->toBe(48.0);
  $layout['envelope']->assertWithin(200, 200);
  expect(fn() => $cursor->layout(new CanvasRectangle(0, 0, 200, 200), 200, 200))->toThrow(RuntimeException::class);
});

it('rejects incomplete or invalid project cursor definitions before rendering', function () {
  expect(fn() => new BattleTargetCursor([]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTargetCursor(targetCursorTextures(), size: 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleTargetCursor(targetCursorTextures(), gap: -1))->toThrow(InvalidArgumentException::class);
  $textures = targetCursorTextures();
  $textures['above'] = new CanvasNineSlice('above.png', new SpriteSourceRect(0, 0, 32, 32), minimumWidth: 64);
  expect(fn() => new BattleTargetCursor($textures))->toThrow(InvalidArgumentException::class);
});

it('reserves clear motion space beside a banner and hides under a complete modal', function () {
  $cursor = new BattleTargetCursor(targetCursorTextures());
  $battler = new CanvasRectangle(100, 84, 80, 120);
  $banner = new CanvasRectangle(0, 20, 400, 60);
  foreach ([0, 0.6, 1.2] as $now) {
    $layout = $cursor->layout($battler, 400, 400, $now, true, [$banner]);
    expect($layout['texture']->asset)->toBe('left.png')
      ->and($layout['envelope']->y)->toBeGreaterThanOrEqual($banner->y + $banner->height);
  }
  expect($cursor->layout($battler, 400, 400, occupied: [new CanvasRectangle(0, 0, 400, 400)]))->toBeNull();
});
