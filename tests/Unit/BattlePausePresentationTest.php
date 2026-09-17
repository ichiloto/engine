<?php

use Ichiloto\Engine\Battle\Presentation\BattlePauseMenu;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasUiAdapter;
use Ichiloto\Engine\Battle\Presentation\BattlePauseSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattlePause;
use Ichiloto\Engine\Battle\Presentation\PauseAction;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;

function pauseSkinFixture(): BattlePauseSkin
{
  $textures = [];
  foreach (BattlePauseSkin::TEXTURES as $role) {
    $textures[$role] = match ($role) {
      'panel' => new CanvasNineSlice('panel.png', new SpriteSourceRect(0, 0, 96, 96), 24, 24, 24, 24),
      'selector' => new CanvasNineSlice('selector.png', new SpriteSourceRect(5, 5, 22, 22)),
      'divider' => new CanvasNineSlice('divider.png', new SpriteSourceRect(0, 0, 96, 16)),
      default => new CanvasNineSlice($role . '.png', new SpriteSourceRect(0, 0, 96, 48), 12, 12, 12, 12),
    };
  }
  return new BattlePauseSkin($textures, array_fill_keys(BattlePauseSkin::COLORS, PresentationColor::rgb(200, 190, 180)));
}

it('shares opaque Console cell conversion without replacing explicit selection colors or filling gaps', function () {
  $foreground = PresentationColor::ansi16(12);
  $background = PresentationColor::ansi256(24);
  $source = [new PresentationTextRun(3, 5, '  '), new PresentationTextRun(3, 10, 'Selected', $foreground, $background)];
  $runs = BattleCanvasUiAdapter::opaqueRuns($source);
  expect($runs)->toHaveCount(2)
    ->and([$runs[0]->row, $runs[0]->column, $runs[0]->text])->toBe([3, 5, '  '])
    ->and($runs[0]->background)->toEqual(PresentationColor::rgb(15, 23, 30))
    ->and($runs[1])->toBe($source[1])->and($source[0]->background)->toBeNull()
    ->and(BattleCanvasUiAdapter::opaqueRuns([]))->toBe([]);
});

it('locks opening input, defaults Resume and emits each exit once without a callback', function () {
  $now = 0.0;
  $menu = new BattlePauseMenu(clock: function () use (&$now): float { return $now; });
  $menu->open();
  $menu->navigate(3); $menu->confirm();
  expect($menu->selection)->toBe(0)->and($menu->tick())->toBeNull();
  $now = 0.2; $menu->tick(); $menu->confirm();
  expect($menu->tick())->toBeNull()->and($menu->isReady())->toBeFalse();
  $now = 0.4;
  expect($menu->tick())->toBe(PauseAction::RESUME)->and($menu->tick())->toBeNull();
  $menu->open(1); $now = 0.6; $menu->tick(); $menu->confirm();
  $menu->close(); $now = 1;
  expect($menu->tick())->toBeNull();
  $menu->open();
  expect($menu->selection)->toBe(0)->and($menu->confirmation)->toBeNull();
});

it('defaults destructive confirmations to Cancel and restores the originating focus', function (int $index, PauseAction $action) {
  $now = 0.0;
  $menu = new BattlePauseMenu(clock: function () use (&$now): float { return $now; });
  $menu->open(); $now = 0.2; $menu->tick(); $menu->navigate($index); $menu->confirm();
  $now += 0.08; $menu->tick(); $now += 0.08; $menu->tick();
  expect($menu->confirmation)->toBe($action)->and($menu->selection)->toBe(0)->and($menu->labels()[0])->toBe('Cancel');
  $menu->back(pauseShortcut: true);
  expect($menu->isReady())->toBeTrue()->and($menu->confirmation)->toBe($action);
  $menu->confirm(); $now += 0.08; $menu->tick(); $now += 0.08; $menu->tick();
  expect($menu->confirmation)->toBeNull()->and($menu->selection)->toBe($index);
  $menu->confirm(); $now += 0.08; $menu->tick(); $now += 0.08; $menu->tick();
  $menu->navigate(1); $menu->confirm(); $now += 0.13;
  expect($menu->tick())->toBe($action)->and($menu->tick())->toBeNull();
})->with([[2, PauseAction::TITLE], [3, PauseAction::EXIT]]);

it('composes centered independent labels over the unchanged scene at every approved size', function (int $width, int $height, bool $confirm) {
  $now = 0.0;
  $menu = new BattlePauseMenu(clock: function () use (&$now): float { return $now; });
  $menu->open(); $now = 0.2; $menu->tick();
  if ($confirm) { $menu->navigate(2); $menu->confirm(); $now += 0.08; $menu->tick(); $now += 0.08; $menu->tick(); }
  $image = new CanvasImage('actual-scene', 'actual.png', new CanvasRectangle(0, 0, $width, $height));
  $field = new PresentationCanvas($width, $height, [$image]);
  foreach (range(0, count($menu->labels()) - 1) as $selection) {
    $frame = GraphicalBattlePause::frame($field, pauseSkinFixture(), $menu);
    expect($frame->images[0])->toBe($image)
      ->and(array_column($frame->textLayers, 'id'))->not->toContain('pause-hints');
    $layers = array_column($frame->textLayers, null, 'id');
    foreach ($menu->labels() as $index => $label) {
      $text = $layers['pause-label-' . $index];
      $center = $width / 2 + ($confirm ? ($index === 0 ? -117 : 117) : 0);
      expect($text->runs[0]->text)->toBe($label)
        ->and($text->bounds->x + $text->bounds->width / 2)->toEqualWithDelta($center, 0.001);
    }
    foreach ($frame->images as $part) {
      if ($part === $image) { continue; }
      expect($part->destination->width)->toBeLessThanOrEqual($confirm ? 520 : 400);
    }
    $menu->navigate(1); $now += 0.3;
  }
  expect($menu->confirmation !== null)->toBe($confirm);
})->with([[1350, 720], [960, 540], [736, 414]])->with([false, true]);

it('uses short owned fades but no scale or moving cursor in reduced motion', function () {
  $now = 0.0;
  $menu = new BattlePauseMenu(true, function () use (&$now): float { return $now; });
  $menu->open(); $now = 0.08;
  expect($menu->opacity())->toBe(0.5)->and($menu->scale())->toBe(1.0);
  $now = 0.2; $menu->tick();
  $field = new PresentationCanvas(1350, 720);
  $a = GraphicalBattlePause::frame($field, pauseSkinFixture(), $menu);
  $now += 0.6;
  $b = GraphicalBattlePause::frame($field, pauseSkinFixture(), $menu);
  expect($a->toArray())->toBe($b->toArray());
  $menu->back(); $now += 0.06;
  expect($menu->opacity())->toEqualWithDelta(0.5, 0.000001)->and($menu->scale())->toBe(1.0);
});

it('rejects incomplete declared skins and leaves legacy catalogs optional', function () {
  $skin = pauseSkinFixture();
  $textures = $skin->textures; unset($textures['focus']);
  expect(fn() => new BattlePauseSkin($textures, $skin->colors))->toThrow(InvalidArgumentException::class)
    ->and((new BattlePresentationCatalog([], [], []))->pause)->toBeNull();
  expect(fn() => GraphicalBattlePause::preflight($skin, sys_get_temp_dir()))->toThrow(RuntimeException::class);
});
