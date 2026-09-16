<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleHudListSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusRow;
use Ichiloto\Engine\Battle\Presentation\BattleHudStatusSnapshot;
use Ichiloto\Engine\Battle\Presentation\BattleUiSkin;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleHud;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

function hudTestSkin(): BattleUiSkin
{
  $textures = [];
  foreach (['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting'] as $role) {
    $textures[$role] = new CanvasNineSlice($role . '.png', new SpriteSourceRect(0, 0, 8, 8));
  }
  $colors = [];
  foreach (['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink'] as $role) {
    $colors[$role] = PresentationColor::rgb(200, 200, 200);
  }
  return new BattleUiSkin($textures, $colors);
}

function hudTestArena(): BattleArenaDefinition
{
  return new BattleArenaDefinition(1350, 720,
    new CanvasImage('arena', 'arena.png', new CanvasRectangle(0, 0, 1350, 720)), [], [], skin: hudTestSkin(),
    feedbackArea: new CanvasRectangle(0, 80, 1350, 452));
}

function hudTestList(string $title, array $labels, int $selected = 0, string $help = ''): BattleHudListSnapshot
{
  return new BattleHudListSnapshot($title, $help, array_map(fn($index) => new BattleHudRow($index, $labels[$index], $index === $selected),
    array_keys($labels)), $selected, 0, 4, count($labels), 1, 1);
}

it('preserves command context name and resource rows without terminal borders', function () {
  $hud = new BattleHudSnapshot(hudTestList('Command 1/2', ['Attack', 'Skill', 'Magic', 'Item'], help: 'i:Info'),
    hudTestList('Magic', ['Cure  3 MP'], help: 'enter:Select i:Info c:Back'),
    hudTestList('Name', ['Hero', 'Oracle', 'Strider', 'Sentinel']),
    new BattleHudStatusSnapshot('', '', array_map(fn($index) => new BattleHudStatusRow($index, 1234 + $index, 2000, 19, 30, 0.5), range(0, 3))));
  $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, 'submenu', 0);
  $layers = array_column($frame->textLayers, null, 'id');
  expect($layers['hud-command-title']->runs[0]->text)->toBe('Command 1/2')
    ->and($layers['hud-submenu-rows']->runs[0]->text)->toBe('Cure  3 MP')
    ->and($layers['hud-submenu-help']->runs[0]->text)->toBe('enter:Select i:Info c:Back');
  foreach (range(0, 3) as $index) {
    $baseline = fn($id) => $layers[$id]->y + $layers[$id]->runs[$index]->row * $layers[$id]->grid->cellHeight;
    expect($baseline('hud-names-rows'))->toBe($baseline('hud-hp-rows'))
      ->and($baseline('hud-hp-rows'))->toBe($baseline('hud-mp-rows'));
  }
  $cursors = array_values(array_filter($frame->images, fn($image) => str_starts_with($image->id, 'hud-cursor')));
  expect($cursors)->toHaveCount(1)->and($cursors[0]->destination->x)->toBe(156.0)
    ->and(count($frame->textLayers))->toBeLessThanOrEqual(64);
});

it('moves only the input-owned cursor and keeps persistent selection stationary', function () {
  $hud = new BattleHudSnapshot(hudTestList('Command', ['Attack']), hudTestList('Attack', ['Strike']), hudTestList('Name', ['Hero']));
  $first = GraphicalBattleHud::compose(hudTestArena(), $hud, 'command', 0);
  $second = GraphicalBattleHud::compose(hudTestArena(), $hud, 'command', 0.6);
  expect($first->textLayers)->toEqual($second->textLayers);
  $moving = [];
  foreach ($first->images as $index => $image) {
    if ($image->destination != $second->images[$index]->destination) { $moving[] = $image->id; }
  }
  expect($moving)->toBe(['hud-cursor-1-1'])
    ->and(GraphicalBattleHud::cursorOffset(0.6, false))->toBe(4.0)
    ->and(GraphicalBattleHud::cursorOffset(0.6, true))->toBe(0.0);
  $target = GraphicalBattleHud::compose(hudTestArena(), $hud, 'target', 0.6);
  expect(array_filter($target->images, fn($image) => str_starts_with($image->id, 'hud-cursor')))->toBe([]);
});

it('omits zero fills and absent ATB while values update immediately', function () {
  $snapshot = fn(int $hp) => new BattleHudSnapshot(status: new BattleHudStatusSnapshot('', '', [new BattleHudStatusRow(0, $hp, 100, 7, 10)]));
  $empty = GraphicalBattleHud::compose(hudTestArena(), $snapshot(0), null, 0);
  $full = GraphicalBattleHud::compose(hudTestArena(), $snapshot(100), null, 0);
  expect(array_filter($empty->images, fn($image) => str_starts_with($image->id, 'hp-fill-')))->toBe([])
    ->and(array_filter($full->images, fn($image) => str_starts_with($image->id, 'atb-')))->toBe([]);
  $values = array_column($full->textLayers, null, 'id');
  expect($values['hud-hp-rows']->runs[0]->text)->toBe('100')->and($values)->not->toHaveKey('hud-stats-ATB');
});

it('honors reduced motion without dropping highlights or changing author preferences', function () {
  $previous = new ReflectionClass(ConfigStore::class)->getStaticProperties();
  try {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => true]]));
    $hud = new BattleHudSnapshot(hudTestList('Command', ['Attack']));
    expect(GraphicalBattleHud::compose(hudTestArena(), $hud, 'command', 0)->toArray())
      ->toBe(GraphicalBattleHud::compose(hudTestArena(), $hud, 'command', 0.6)->toArray());
  } finally {
    foreach ($previous as $name => $value) { new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value); }
  }
});

it('keeps blank context visible and removes all hidden windows on replacement', function () {
  $context = hudTestList('', [], -1);
  expect(GraphicalBattleHud::compose(hudTestArena(), new BattleHudSnapshot(context: $context), null, 0)->images)->toHaveCount(1);
  $empty = GraphicalBattleHud::compose(hudTestArena(), new BattleHudSnapshot(), null, 0);
  expect($empty->images)->toBe([])->and($empty->textLayers)->toBe([]);
});

it('projects multiline messages inside the existing grown window without changing their source', function () {
  $source = "First line\nSecond line";
  $hud = new BattleHudSnapshot(message: $source, messageRows: 2);
  $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, null, 0);
  $message = array_column($frame->textLayers, null, 'id')['hud-message'];
  expect($hud->message)->toBe($source)
    ->and(array_column($message->runs, 'text'))->toBe(['First line', 'Second line'])
    ->and(array_column($message->runs, 'row'))->toBe([0, 1])
    ->and($message->grid->rows)->toBe(2)
    ->and($frame->images[0]->destination->height)->toBe(80.0);
});

it('bounds visible text before transport while retaining long authored labels in snapshots', function () {
  $source = str_repeat('Long action ', 200) . '99 MP';
  $hud = new BattleHudSnapshot(context: hudTestList('Magic', [$source]), message: str_repeat('m', 2000));
  $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, 'submenu', 0);
  expect($hud->context->rows[0]->label)->toBe($source);
  foreach ($frame->textLayers as $layer) {
    $layer->bounds->assertWithin(1350, 720);
    foreach ($layer->runs as $run) { $run->assertFits($layer->grid->columns, $layer->grid->rows); }
  }
});

it('leaves canvas layer capacity for simultaneous results with four full HUD rows', function () {
  $list = hudTestList('Title', ['First', 'Second', 'Third', 'Fourth'], help: 'Help');
  $hud = new BattleHudSnapshot($list, $list, $list, new BattleHudStatusSnapshot('', '',
    array_map(fn($i) => new BattleHudStatusRow($i, 1234, 2000, 19, 30, 0.5), range(0, 3))));
  $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, 'submenu', 0);
  expect($frame->textLayers)->toHaveCount(17);
  $columns = array_column($frame->textLayers, null, 'id');
  foreach (['names', 'command', 'submenu', 'hp', 'mp'] as $role) {
    expect($columns['hud-' . $role . '-rows']->runs)->toHaveCount(4);
  }
});

it('marks invalid maxima with a dash instead of inventing a full or empty resource percentage', function () {
  $hud = new BattleHudSnapshot(status: new BattleHudStatusSnapshot('', '',
    array_map(fn($i) => new BattleHudStatusRow($i, 10, 0, 7, -1), range(0, 3))));
  $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, null, 0);
  $columns = array_column($frame->textLayers, null, 'id');
  expect(array_column($columns['hud-hp-rows']->runs, 'text'))->toBe(array_fill(0, 4, '10'))
    ->and(array_column($columns['hud-mp-rows']->runs, 'text'))->toBe(array_fill(0, 4, '7'))
    ->and(array_column($columns['hud-hp-unknown']->runs, 'text'))->toBe(array_fill(0, 4, '-'))
    ->and(array_column($columns['hud-mp-unknown']->runs, 'text'))->toBe(array_fill(0, 4, '-'))
    ->and(array_filter($frame->images, fn($image) => preg_match('/^(hp|mp)-fill-/', $image->id)))->toBe([]);
});

it('right aligns resource values at stable right edges as digits grow and shrink', function () {
  $previous = null;
  foreach ([[9, 10, 999, 10000], [10, 9, 1000, 9999]] as $values) {
    $hud = new BattleHudSnapshot(status: new BattleHudStatusSnapshot('', '',
      array_map(fn($index) => new BattleHudStatusRow($index, $values[$index], 10000, $values[3 - $index], 10000, 0.5), range(0, 3))));
    $frame = GraphicalBattleHud::compose(hudTestArena(), $hud, null, 0);
    $layers = array_column($frame->textLayers, null, 'id');
    foreach (['hp', 'mp'] as $resource) {
      $layer = $layers['hud-' . $resource . '-rows'];
      foreach ($layer->runs as $run) {
        expect($run->column + strlen($run->text))->toBe($layer->grid->columns);
      }
      if ($previous !== null) {
        expect($layer->x)->toBe($previous[$resource]->x)->and($layer->grid)->toEqual($previous[$resource]->grid);
      }
    }
    $previous = ['hp' => $layers['hud-hp-rows'], 'mp' => $layers['hud-mp-rows']];
  }
});
