<?php

declare(strict_types=1);

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeState;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\MasteryMenuContent;
use Ichiloto\Engine\UI\Presentation\MasteryMenuNode;
use Ichiloto\Engine\UI\Presentation\MasteryMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MasteryPointsCount;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;

/** The text each canvas layer shows, by layer id. */
function masteryCanvasText(PresentationCanvas $canvas): array
{
  $text = [];
  foreach ($canvas->textLayers as $layer) {
    $runs = array_filter($layer->runs, static fn($run) => $run->foreground !== null);
    if ($runs !== []) { $text[$layer->id] = implode('', array_column($runs, 'text')); }
  }

  return $text;
}

/** A synthetic map's projection: two regions, one node building on another, a character holding 60 SP. */
function masteryMenuContent(array $nodes, ?string $focused = 'spark', ?MasteryPointsCount $count = null): MasteryMenuContent
{
  $details = new MenuDetailSheet([[MenuDetailEntry::createProse('Spark', 'accent'), MenuDetailEntry::createNumber('SP', '50'),
    MenuDetailEntry::createNumber('MP Cost', '4')], [MenuDetailEntry::createProse('Calls a spark down on one foe.')]]);

  return new MasteryMenuContent(new Character('Hero', 0, new Stats(), actorId: 'hero'), 'Mastery', ['Blades 1/1', 'Storms 0/2'], 1,
    $nodes, $focused, ['Learned' => '1/3'], $details, 'No skills to learn.', 'Choose a skill to learn.', null,
    pointsLabel: 'SP', points: 10, pointsCount: $count);
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/ichiloto-mastery-menu-' . bin2hex(random_bytes(4));
  mkdir($this->root);
  $this->theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
});

afterEach(function () {
  rmdir($this->root);
});

it('draws each region, each node with its status, the SP total and the focused node\'s details', function () {
  $canvas = MasteryMenuPresentation::compose(masteryMenuContent([
    new MasteryMenuNode('cut', 'Start Cut', 0, MasteryNodeState::LEARNED, "\u{2713} Learned", position: ['x' => 0.25, 'y' => 0.5]),
    new MasteryMenuNode('spark', 'Spark', 1, MasteryNodeState::OPEN, '50', position: ['x' => 0.75, 'y' => 0.33]),
    new MasteryMenuNode('storm', 'Storm', 1, MasteryNodeState::LOCKED, 'Lv 5', ['spark'], ['x' => 0.75, 'y' => 0.67], false),
  ]), $this->theme);
  $text = masteryCanvasText($canvas);
  $all = implode("\n", $text);
  $surfaces = array_map(static fn($layer) => $layer->id, $canvas->textLayers);

  expect($text['mastery-region-0'])->toBe('Blades 1/1')
    ->and($text['mastery-region-1'])->toBe('Storms 0/2')
    ->and($text['mastery-identity'])->toContain('Hero')->toContain('SP 10')
    ->and($text['mastery-node-cut-label'])->toContain("\u{2713} Learned")
    ->and($text['mastery-node-spark-label'])->toContain('Spark')->toContain('50')
    ->and($text['mastery-node-storm-label'])->toContain('Lv 5')
    ->and($all)->toContain('Calls a spark down on one foe.')->toContain('MP Cost')
    ->and(array_filter($surfaces, static fn(string $id): bool => str_starts_with($id, 'mastery-link-spark-storm')))->not->toBeEmpty()
    ->and(array_filter($surfaces, static fn(string $id): bool => str_starts_with($id, 'mastery-node-spark-focus')))->not->toBeEmpty();
});

it('counts the SP total down after a spend, then holds the new total', function () {
  $nodes = [new MasteryMenuNode('spark', 'Spark', 1, MasteryNodeState::LEARNED, "\u{2713} Learned", position: ['x' => 0.75, 'y' => 0.33])];
  $count = new MasteryPointsCount(60, 10, 1.0);
  $theme = $this->theme;
  $at = static fn(float $time): string => masteryCanvasText(MasteryMenuPresentation::compose(masteryMenuContent($nodes, 'spark', $count),
    $theme, $time))['mastery-identity'];

  expect($at(1.0))->toContain('SP 60')
    ->and($at(1.0 + MasteryPointsCount::DURATION / 2))->toContain('SP 35')
    ->and($at(1.0 + MasteryPointsCount::DURATION))->toContain('SP 10')
    ->and($at(9.0))->toContain('SP 10');
});

it('places an authored node where the map puts it and keeps every card inside the map', function () {
  $canvas = MasteryMenuPresentation::compose(masteryMenuContent([
    new MasteryMenuNode('spark', 'Spark', 1, MasteryNodeState::OPEN, '50', position: ['x' => 0.0, 'y' => 1.0]),
  ]), $this->theme);
  $card = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'mastery-node-spark');
  $label = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'mastery-node-spark-label');
  $region = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'mastery-region-0');

  // The card sits against the map's bottom-left corner: left of and below its own region's label.
  expect($card)->not->toBeNull()
    ->and($label->x)->toBeGreaterThanOrEqual($region->x)
    ->and($label->y)->toBeGreaterThan($region->y);
});

it('leaves a map whose nodes overlap at this size to the Terminal list', function () {
  $nodes = array_map(static fn(int $n): MasteryMenuNode => new MasteryMenuNode("n{$n}", "Skill {$n}", 0, MasteryNodeState::OPEN, '50'), range(1, 30));
  $nodes = array_map(static fn(MasteryMenuNode $node): MasteryMenuNode => new MasteryMenuNode($node->id, $node->label, 0, $node->state, $node->value, position: ['x' => 0.25, 'y' => 0.5]), $nodes);

  expect(fn() => MasteryMenuPresentation::compose(masteryMenuContent($nodes, 'n1'), $this->theme))->toThrow(RuntimeException::class, 'terminal presentation retained');
});
