<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleFormationLayout;
use Ichiloto\Engine\Battle\Presentation\BattleGround;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattlerArtwork;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Scenes\Arena\BattleTestMenuList;
use Ichiloto\Engine\UI\Presentation\BattleTestMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRowColumn;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-battle-test-menu-' . bin2hex(random_bytes(6));
  mkdir($this->root);
  \Tests\Support\Rendering\writeTestPng($this->root . '/cave.png', 400, 300);
  \Tests\Support\Rendering\writeTestPng($this->root . '/bat.png', 80, 60);
  $arena = new BattleArenaDefinition('Cave', new CanvasImage('arena', 'cave.png', new CanvasRectangle(0, 0, 1350, 720),
    sourceRect: new SpriteSourceRect(50, 40, 300, 160)));
  $this->catalog = new BattlePresentationCatalog(['cave' => $arena], [], ['Bat' => new BattlerArtwork('bat.png', 80, 60, 40, 60)],
    ui: new BattleCanvasLayout(1350, 720, ground: new BattleGround(top: 300, bottom: 600)), defaultArena: 'cave');
  $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
});

afterEach(function () {
  foreach (glob($this->root . '/*') ?: [] as $file) { unlink($file); }
  rmdir($this->root);
});

it('draws the battle\'s resting stage beside the list, scaled into its frame with the arena crop kept, the chosen member outlined', function () {
  $stage = BattleFormationLayout::compose($this->catalog, 'cave', [['enemyId' => 'Bat', 'slot' => new BattleStageSlot(300, 0, 80, 60)],
    ['enemyId' => 'Bat', 'slot' => null]], [], $this->root);
  $list = new BattleTestMenuList('Test Troop', [['1 Bat', []], ['2 Bat', []], ['+ Add an enemy', []], ['Fight', []]], 1,
    note: 'Kept as Bat x 2 in Troops.', namesArena: true);
  $canvas = BattleTestMenuPresentation::compose($this->theme, $list, true, [['Kaelion', ['Lv' => '5', 'HP' => '120/120']]], null,
    'Cave', $stage, 1, true);

  $stageImages = array_values(array_filter($canvas->images, static fn(CanvasImage $image): bool => str_starts_with($image->id, 'stage-')));
  $frame = $stageImages[0]->destination;
  $scale = $frame->width / 1350;
  $text = implode(' ', array_map(static fn($layer): string => implode('', array_map(static fn($run): string => $run->text, $layer->runs)), $canvas->textLayers));

  // The background keeps its crop and fills the stage frame; every battler stands where the battle stands it, scaled.
  expect(array_column($stageImages, 'asset'))->toBe(['cave.png', 'bat.png', 'bat.png'])
    ->and($stageImages[0]->sourceRect)->toEqual(new SpriteSourceRect(50, 40, 300, 160))
    ->and(round($frame->height, 3))->toBe(round(720 * $scale, 3))
    ->and(array_map(static fn(CanvasImage $image): array => [$image->id, round($image->destination->x - $frame->x, 3),
      round($image->destination->y - $frame->y, 3)], array_slice($stageImages, 1)))
    ->toEqualCanonicalizing(array_map(static fn($battler): array => ['stage-' . $battler->image->id,
      round($battler->image->destination->x * $scale, 3), round($battler->image->destination->y * $scale, 3)], $stage->enemies))
    ->and($text)->toContain('Test Troop')->toContain('Arena  < Cave >')->toContain('Kept as Bat x 2 in Troops.')->toContain('Kaelion');
});

it('says why there is no stage where nothing graphical draws one', function () {
  $canvas = BattleTestMenuPresentation::compose($this->theme, new BattleTestMenuList('Troop', [['Bats', ['x2', '1']]], 0,
    columns: [MenuRowColumn::fit(['x2']), MenuRowColumn::fit(['1'], label: 'Lv')]), true,
    [], null, null, null, null, false);
  $text = implode(' ', array_map(static fn($layer): string => implode('', array_map(static fn($run): string => $run->text, $layer->runs)), $canvas->textLayers));

  expect($text)->toContain('draws no graphical battle stage')->toContain('Bats')->toContain('No party could be built')
    ->and(array_filter($canvas->images, static fn(CanvasImage $image): bool => str_starts_with($image->id, 'stage-')))->toBe([]);
});

it('draws a long troop list while the party is set up, a row without a value beside rows with one', function () {
  $rows = [...array_map(static fn(int $i): array => ["Troop {$i}", ["x{$i}", '1']], range(1, 40)), ['+ Compose a test troop', ['', '']]];
  $columns = [MenuRowColumn::fit(['x40']), MenuRowColumn::fit(['1'], label: 'Lv')];
  // The cursor is in the party: no row is chosen, and the troop being fought stays in view.
  $canvas = BattleTestMenuPresentation::compose($this->theme, new BattleTestMenuList('Troop', $rows, null, columns: $columns, anchor: 40), true,
    [['Kaelion', ['Lv' => '5']]], 0, 'Cave', null, null, false);
  $text = implode(' ', array_map(static fn($layer): string => implode('', array_map(static fn($run): string => $run->text, $layer->runs)), $canvas->textLayers));

  expect($text)->toContain('Compose a test troop')->toContain('Troop 39')->not->toContain('Troop 2 ');
});

/** Each text run of a canvas row, as [row id, first cell, text]. */
function battleTestRuns($canvas, string $prefix): array
{
  $runs = [];
  foreach ($canvas->textLayers as $layer) {
    if (!str_starts_with($layer->id, $prefix)) { continue; }
    foreach ($layer->runs as $run) { $runs[] = [$layer->id, $layer->x + $run->column * $layer->grid->cellWidth, $run->text, $layer->grid->cellWidth]; }
  }
  return $runs;
}

it('lines party figures up: each stat label in one place and its figures sharing one right edge', function () {
  $canvas = BattleTestMenuPresentation::compose($this->theme, new BattleTestMenuList('Troop', [], null), true,
    [['Kaelion', ['Lv' => '1', 'HP' => '140/140']], ['Liora', ['Lv' => '12', 'HP' => '90/90']]], null, null, null, null, false);
  $runs = battleTestRuns($canvas, 'battle-test-members');
  $find = static fn(string $text): array => array_values(array_filter($runs, static fn(array $run): bool => $run[2] === $text))[0];
  $end = static fn(array $run): float => $run[1] + mb_strlen($run[2]) * $run[3];
  $labels = array_values(array_filter($runs, static fn(array $run): bool => $run[2] === 'Lv'));

  // "Lv" starts in the same place on both rows, and each column's figures end on one right edge: a shorter figure never moves its row.
  expect($labels)->toHaveCount(2)->and($labels[0][1])->toBe($labels[1][1])
    ->and($end($find('1')))->toBe($end($find('12')))
    ->and($end($find('140/140')))->toBe($end($find('90/90')));
});

it('keeps every list row on one line, cutting a name that cannot fit with an ellipsis', function () {
  $long = str_repeat('Very Long Troop Name ', 6);
  $canvas = BattleTestMenuPresentation::compose($this->theme, new BattleTestMenuList('Troop', [[$long, ['x2', '1']], ['Bats', ['x2', '1-2']]], 0,
    columns: [MenuRowColumn::fit(['x2']), MenuRowColumn::fit(['1', '1-2'], label: 'Lv')]), true, [], null, null, null, null, false);
  $labels = array_values(array_filter(battleTestRuns($canvas, 'battle-test-rows'), static fn(array $run): bool => str_starts_with($run[2], 'Very')));

  expect($labels)->toHaveCount(1)->and($labels[0][2])->toEndWith("\u{2026}");
});
