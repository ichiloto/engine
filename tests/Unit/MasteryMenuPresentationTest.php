<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MasteryCamera;
use Ichiloto\Engine\UI\Presentation\MasteryLearnHold;
use Ichiloto\Engine\UI\Presentation\MasteryMenuContent;
use Ichiloto\Engine\UI\Presentation\MasteryMenuLevel;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MasteryMenuSelection;
use Ichiloto\Engine\UI\Presentation\MasteryMenuTab;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/** Every text run a canvas shows, with where it starts, its cell width and colour. */
function masteryCanvasRuns(PresentationCanvas $canvas): array
{
  $runs = [];
  foreach ($canvas->textLayers as $layer) {
    foreach ($layer->runs as $run) {
      if ($run->foreground !== null && trim($run->text) !== '') {
        $runs[] = ['id' => $layer->id, 'text' => $run->text, 'x' => $layer->x + $run->column * $layer->grid->cellWidth,
          'y' => $layer->y + $run->row * $layer->grid->cellHeight, 'width' => mb_strlen($run->text) * $layer->grid->cellWidth,
          'color' => $run->foreground->toArray()];
      }
    }
  }

  return $runs;
}

/** The fills a composite draws, as canvas rectangles: by default every picture of the tree (what stays, what moves, the marks over both). */
function masteryBoardFills(PresentationCanvas $canvas, array $ids = ['mastery-board', 'mastery-board-motion', 'mastery-board-marks']): array
{
  $fills = [];
  foreach (array_filter($canvas->composites, static fn($composite) => in_array($composite->id, $ids, true)) as $board) {
    foreach ($board->operations as $operation) {
      if ($operation->data['type'] === 'fill') {
        $d = $operation->data['destination'];
        $fills[] = ['x' => $board->destination->x + $d['x'], 'y' => $board->destination->y + $d['y'], 'width' => $d['width'],
          'height' => $d['height'], 'masks' => count($operation->data['masks']), 'color' => $operation->data['brush']['color'] ?? null];
      }
    }
  }

  return $fills;
}

/**
 * A synthetic board for a character holding 60 SP: a learned grade, an open grade in focus, and a
 * locked advanced skill whose reach ("Multiple foes") is a wide badge.
 */
function masteryPresentationContent(array $overrides = []): MasteryMenuContent
{
  $nodes = [
    new MasteryMenuNode('sky', 'Sky', MasteryNodeState::OPEN, 0.5, 0, null, 'sky', iconRoles: ['mastery.node']),
    new MasteryMenuNode('spark-1', 'Spark I', MasteryNodeState::LEARNED, 0.5, 1, 'sky', 'sky', 'I'),
    new MasteryMenuNode('spark-2', 'Spark II', MasteryNodeState::OPEN, 0.5, 2, 'spark-1', 'sky', 'II', affordable: true, price: '50 SP'),
    new MasteryMenuNode('tempest', 'Tempest', MasteryNodeState::LOCKED, 0.5, 3, 'spark-2', 'sky', 'Multiple foes', true, price: '120 SP'),
  ];
  $values = [
    'character' => new Character('Hero', 0, new Stats(), actorId: 'hero'), 'title' => 'Mastery', 'role' => 'Stormcaller', 'levelLabel' => 'Lv',
    'pointsLabel' => 'SP', 'points' => 60,
    'regions' => [new MasteryMenuTab('blades', 'Blades', 1, 1), new MasteryMenuTab('storms', 'Storms', 1, 4)], 'regionIndex' => 1,
    'families' => [new MasteryMenuTab('sky', 'Sky', 1, 4)], 'familyIndex' => 0, 'level' => MasteryMenuLevel::TREE, 'nodes' => $nodes, 'lanes' => 1, 'stages' => 4,
    'focus' => 'spark-2', 'camera' => MasteryCamera::createAt(0.5, 2), 'selection' => new MasteryMenuSelection('Spark II', null, '50 SP', 'Learn', true),
    'footer' => [['text' => 'MP', 'color' => 'muted'], ['text' => '9', 'color' => 'text'], ['text' => 'One foe', 'color' => 'text']],
    'hints' => [new ActionHint('confirm', 'Hold · Learn', ControlHint::keyboard(KeyCode::ENTER)),
      new ActionHint('cancel', 'Back', ControlHint::keyboard(KeyCode::ESCAPE))],
  ];

  return new MasteryMenuContent(...array_replace($values, $overrides));
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-mastery-menu-' . bin2hex(random_bytes(4));
  mkdir($this->root);
  $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'mastery' => ['regionColors' => ['storms' => [151, 205, 234]]]]);
});

afterEach(function () {
  rmdir($this->root);
});

it('draws the corner block, the discipline tabs with counts, the family strip and a name under every node', function () {
  $runs = masteryCanvasRuns(MasteryMenuPresentation::compose(masteryPresentationContent(), $this->theme));
  $texts = array_column($runs, 'text');

  expect($texts)->toContain('Mastery', 'Hero', 'Stormcaller', 'Lv', '1', 'SP', '60', 'Blades', '1/1', 'Storms', '1/4', 'Sky',
      'Spark I', 'Spark II', 'I', 'II', 'MP', '9', 'One foe')
    // The price is said once, beside Learn, never on the board.
    ->and(count(array_filter($texts, static fn(string $text): bool => str_contains($text, '50 SP'))))->toBe(1)
    // The shown discipline's name takes its accent.
    ->and(array_find($runs, static fn(array $run): bool => $run['text'] === 'Storms')['color'])->toBe(['kind' => 'rgb', 'r' => 151, 'g' => 205, 'b' => 234])
    // The footer names only this level's few bindings.
    ->and(implode(' ', $texts))->toContain('Hold · Learn', 'Back');
});

it('centres Learn in its button', function () {
  $canvas = MasteryMenuPresentation::compose(masteryPresentationContent(), $this->theme);
  $learn = array_find(masteryCanvasRuns($canvas), static fn(array $run): bool => $run['text'] === 'Learn');
  $chrome = array_find($canvas->composites, static fn($composite) => $composite->id === 'mastery-chrome');
  // The button is the chrome's only 180 by 40 fill.
  $button = array_find(array_map(static fn($operation) => $operation->data['destination'] ?? null, $chrome->operations),
    static fn(?array $area): bool => $area !== null && $area['width'] === 180.0 && $area['height'] === 40.0);

  expect($button)->not->toBeNull()
    ->and(abs(($learn['x'] + $learn['width'] / 2) - ($button['x'] + $button['width'] / 2)))->toBeLessThan(0.01);
});

it('keeps a wide reach badge clear of its padlock', function () {
  $canvas = MasteryMenuPresentation::compose(masteryPresentationContent(['focus' => 'tempest', 'camera' => MasteryCamera::createAt(0.5, 3)]), $this->theme);
  $fills = masteryBoardFills($canvas);
  $badge = array_find(array_map(static fn(array $run): array => $run, masteryCanvasRuns($canvas)), static fn(array $run): bool => $run['text'] === 'Multiple foes');
  // The badge's pill is the widest 17px-high fill; the padlock's body is a 9 by 7 fill.
  $pills = array_filter($fills, static fn(array $fill): bool => abs($fill['height'] - 17) < 0.01);
  $pill = array_reduce($pills, static fn(?array $widest, array $fill): array => $widest === null || $fill['width'] > $widest['width'] ? $fill : $widest);
  $lock = array_find($fills, static fn(array $fill): bool => abs($fill['width'] - 9) < 0.01 && abs($fill['height'] - 7) < 0.01);

  expect($badge)->not->toBeNull()
    ->and(array_column(masteryCanvasRuns($canvas), 'text'))->toContain('Tempest')
    ->and($lock)->not->toBeNull()
    ->and($lock['x'])->toBeGreaterThan($pill['x'] + $pill['width'])
    ->and($badge['x'] + $badge['width'])->toBeLessThanOrEqual($pill['x'] + $pill['width']);
});

it('fills the held node and drains the drawn total, and draws neither under reduced motion', function () {
  $hold = new MasteryLearnHold('spark-2', 60, 50, 1.0);
  $at = 1.0 + MasteryLearnHold::DURATION / 2;
  $held = MasteryMenuPresentation::compose(masteryPresentationContent(['hold' => $hold]), $this->theme, $at);
  $still = MasteryMenuPresentation::compose(masteryPresentationContent(['hold' => $hold, 'reducedMotion' => true]), $this->theme, $at);
  // The fill rises from the disc's bottom: a masked fill half the disc's height.
  $isFill = static fn(array $fill): bool => $fill['masks'] === 1 && abs($fill['height'] - 34) < 0.01 && abs($fill['width'] - 68) < 0.01;

  expect(array_column(masteryCanvasRuns($held), 'text'))->toContain('35')->not->toContain('60')
    ->and(array_filter(masteryBoardFills($held), $isFill))->not->toBeEmpty()
    ->and(array_column(masteryCanvasRuns($still), 'text'))->toContain('60')
    ->and(array_filter(masteryBoardFills($still), $isFill))->toBeEmpty();
});

it('shows one framed summary per family above the tree, nine to a page, with the focused one picked out', function () {
  $families = array_map(static fn(int $n): MasteryMenuTab => new MasteryMenuTab("f{$n}", "Family {$n}", $n % 3, 5), range(1, 11));
  $canvas = MasteryMenuPresentation::compose(masteryPresentationContent(['level' => MasteryMenuLevel::FAMILY, 'families' => $families, 'familyIndex' => 10,
    'focus' => null, 'selection' => new MasteryMenuSelection('Family 11', 'Storms')]), $this->theme);
  $texts = array_column(masteryCanvasRuns($canvas), 'text');

  // The tenth and eleventh families are the second page.
  expect($texts)->toContain('Family 10', 'Family 11', '1 / 5 learned', '2 / 5 learned')
    ->and($texts)->not->toContain('Family 1')
    ->and($texts)->not->toContain('Spark II')
    ->and(masteryBoardFills($canvas))->toBe([]);
});

it('takes another theme\'s colours and composes without pictures where a theme binds none', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'mastery' => ['regionColors' => ['storms' => [200, 40, 40]]]]);
  $runs = masteryCanvasRuns(MasteryMenuPresentation::compose(masteryPresentationContent(), $theme));
  $default = MasteryMenuPresentation::compose(masteryPresentationContent(), new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']));

  expect(array_find($runs, static fn(array $run): bool => $run['text'] === 'Storms')['color'])->toBe(['kind' => 'rgb', 'r' => 200, 'g' => 40, 'b' => 40])
    ->and($default->images)->toBe([])
    ->and(array_column(masteryCanvasRuns($default), 'text'))->toContain('Spark II', 'Learn');
});

/** A text layer's drawn extent: its clip where it has one, else its grid. */
function masteryLayerExtent(object $layer): array
{
  $clip = $layer->clipRect;

  return $clip !== null ? [$clip->y, $clip->y + $clip->height] : [$layer->y, $layer->y + $layer->grid->rows * $layer->grid->cellHeight];
}

it('lays the Info sheet over the board\'s own area on every level, clear of the tabs, the family strip and the selection', function (MasteryMenuLevel $level) {
  foreach ([['One line.'], [str_repeat('A summary that runs on and on. ', 14), 'After Spark I']] as $info) {
    $canvas = MasteryMenuPresentation::compose(masteryPresentationContent(['level' => $level, 'info' => $info,
      'focus' => $level === MasteryMenuLevel::TREE ? 'spark-2' : null]), $this->theme);
    $layers = $canvas->textLayers;
    $extents = static fn(string $prefix): array => array_map(masteryLayerExtent(...),
      array_values(array_filter($layers, static fn($layer): bool => str_starts_with($layer->id, $prefix))));
    $sheet = $extents('mastery-info');
    $chrome = [...$extents('mastery-suit'), ...$extents('mastery-families')];

    expect($sheet)->not->toBeEmpty()
      ->and(min(array_column($sheet, 0)))->toBeGreaterThanOrEqual(max(array_column($chrome, 1)))
      ->and(max(array_column($sheet, 1)))->toBeLessThanOrEqual(min(array_column($extents('mastery-selection-name'), 0)))
      ->and(implode(' ', array_column(masteryCanvasRuns($canvas), 'text')))->toContain(trim(substr($info[0], 0, 20)));
  }
})->with([MasteryMenuLevel::DISCIPLINE, MasteryMenuLevel::FAMILY, MasteryMenuLevel::TREE]);

it('puts the focus colour only on the level the focus is on: the tab, then the family, then the node', function () {
  $focus = $this->theme->colors['focus']->toArray();
  $focusFills = static fn(PresentationCanvas $canvas, array $ids): int => count(array_filter(masteryBoardFills($canvas, $ids),
    static fn(array $fill): bool => $fill['color'] === $focus));
  $above = ['focus' => null, 'families' => [new MasteryMenuTab('sky', 'Sky', 1, 4), new MasteryMenuTab('rain', 'Rain', 0, 2)]];
  $discipline = MasteryMenuPresentation::compose(masteryPresentationContent(['level' => MasteryMenuLevel::DISCIPLINE, ...$above]), $this->theme);
  $family = MasteryMenuPresentation::compose(masteryPresentationContent(['level' => MasteryMenuLevel::FAMILY, ...$above]), $this->theme);
  $tree = MasteryMenuPresentation::compose(masteryPresentationContent(), $this->theme);
  $treeIds = ['mastery-board', 'mastery-board-motion', 'mastery-board-marks'];

  // On the disciplines the shown tab is framed in the focus colour and no family card is; on the families
  // a card is framed and the tab keeps its own accent; on the tree only the node's rings are.
  expect($focusFills($discipline, ['mastery-chrome']))->toBeGreaterThan($focusFills($family, ['mastery-chrome']))
    ->and($focusFills($family, ['mastery-chrome']))->toBeGreaterThan(0)
    ->and($focusFills($tree, ['mastery-chrome']))->toBe(0)
    ->and($focusFills($tree, ['mastery-board-motion']))->toBeGreaterThan(0)
    ->and($focusFills($tree, ['mastery-board', 'mastery-board-marks']))->toBe(0)
    ->and($focusFills($discipline, $treeIds) + $focusFills($family, $treeIds))->toBe(0);
});

it('redraws only the small moving picture while Learn is held, never the tree beneath it', function () {
  $hold = new MasteryLearnHold('spark-2', 60, 50, 1.0);
  $content = masteryPresentationContent(['hold' => $hold]);
  $early = MasteryMenuPresentation::compose($content, $this->theme, 1.2);
  $late = MasteryMenuPresentation::compose($content, $this->theme, 1.7);
  $picture = static fn(PresentationCanvas $canvas, string $id) => array_find($canvas->composites, static fn($composite) => $composite->id === $id);

  expect($picture($early, 'mastery-board'))->toEqual($picture($late, 'mastery-board'))
    ->and($picture($early, 'mastery-board-marks'))->toEqual($picture($late, 'mastery-board-marks'))
    ->and($picture($early, 'mastery-board-motion'))->not->toEqual($picture($late, 'mastery-board-motion'))
    ->and(count($picture($late, 'mastery-board-motion')->operations))->toBeLessThan(count($picture($late, 'mastery-board')->operations));
});

it('marks the curriculum with quiet dashes, never in the focus colour', function () {
  $nodes = masteryPresentationContent()->nodes;
  $nodes[2] = new MasteryMenuNode('spark-2', 'Spark II', MasteryNodeState::OPEN, 0.5, 2, 'spark-1', 'sky', 'II', affordable: true, curriculum: true,
    price: '50 SP');
  $with = MasteryMenuPresentation::compose(masteryPresentationContent(['nodes' => $nodes, 'curriculum' => true, 'focus' => 'sky']), $this->theme);
  $without = MasteryMenuPresentation::compose(masteryPresentationContent(['nodes' => $nodes, 'curriculum' => false, 'focus' => 'sky']), $this->theme);
  $focus = $this->theme->colors['focus']->toArray();
  $quiet = static fn(PresentationCanvas $canvas): array => masteryBoardFills($canvas, ['mastery-board', 'mastery-board-marks']);

  expect(count($quiet($with)))->toBeGreaterThan(count($quiet($without)))
    ->and(array_filter($quiet($with), static fn(array $fill): bool => $fill['color'] === $focus))->toBe([]);
});
