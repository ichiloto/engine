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

/** The fills the board composite draws, as canvas rectangles. */
function masteryBoardFills(PresentationCanvas $canvas): array
{
  $board = array_find($canvas->composites, static fn($composite) => $composite->id === 'mastery-board');
  $fills = [];
  foreach ($board?->operations ?? [] as $operation) {
    if ($operation->data['type'] === 'fill') {
      $d = $operation->data['destination'];
      $fills[] = ['x' => $board->destination->x + $d['x'], 'y' => $board->destination->y + $d['y'], 'width' => $d['width'],
        'height' => $d['height'], 'masks' => count($operation->data['masks']), 'color' => $operation->data['brush']['color'] ?? null];
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
    'families' => [new MasteryMenuTab('sky', 'Sky', 1, 4)], 'familyIndex' => 0, 'overview' => false, 'nodes' => $nodes, 'lanes' => 1, 'stages' => 4,
    'focus' => 'spark-2', 'camera' => MasteryCamera::createAt(0.5, 2), 'selection' => new MasteryMenuSelection('Spark II', null, '50 SP', 'Learn', true),
    'footer' => [['text' => 'MP', 'color' => 'muted'], ['text' => '9', 'color' => 'text'], ['text' => 'One foe', 'color' => 'text']],
    'hints' => [new ActionHint('menu_tab_previous', 'Suits', ControlHint::keyboard(KeyCode::Q), ControlHint::keyboard(KeyCode::E), 'menu_tab_next'),
      new ActionHint('confirm', 'Hold · Learn', ControlHint::keyboard(KeyCode::ENTER))],
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
    // Hints pair the previous and next keys under one label.
    ->and(implode('', $texts))->toContain('Q / E');
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

it('zooms out to one framed summary per family, nine to a page, with the focused one picked out', function () {
  $families = array_map(static fn(int $n): MasteryMenuTab => new MasteryMenuTab("f{$n}", "Family {$n}", $n % 3, 5), range(1, 11));
  $canvas = MasteryMenuPresentation::compose(masteryPresentationContent(['overview' => true, 'families' => $families, 'familyIndex' => 10,
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
