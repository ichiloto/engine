<?php

use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Elements\ElementalAffinityTier;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\UI\Presentation\ElementalAffinityDetails;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuDetailEntry;
use Ichiloto\Engine\UI\Presentation\MenuDetailIcon;
use Ichiloto\Engine\UI\Presentation\MenuDetailSheet;
use Ichiloto\Engine\UI\Presentation\MenuDetailStyle;
use Ichiloto\Engine\UI\Presentation\MenuDetailViewport;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Text\TextViewport;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(fn() => ElementRegistry::configure(['Heat', 'Cold', 'Gale']));
afterEach(fn() => ElementRegistry::configure());

function createSyntheticValueSheet(): MenuDetailSheet
{
  return new MenuDetailSheet([[MenuDetailEntry::createHeading('Rewards'),
    MenuDetailEntry::createNumber('Experience', '76'), MenuDetailEntry::createField('Kind', 'Creature'),
    MenuDetailEntry::createIcons('Weaknesses', [new MenuDetailIcon('element.heat', 'Heat'), new MenuDetailIcon('element.gale', 'Gale')]),
    MenuDetailEntry::createIcons('Immunities', [])]]);
}

/** @return array{0: \Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas, 1: MenuPresentationCatalog, 2: array} */
function composeSyntheticValues(array $details): array
{
  $root = createTestDirectory('menu-detail-values');
  writeTestPng($root . '/heat.png', 16, 16);
  writeTestPng($root . '/header.png', 32, 32);
  $theme = new MenuPresentationCatalog($root, ['schema' => 'ichiloto.menu/1', 'icons' => ['element.heat' => 'heat.png'],
    'frames' => ['details.header' => ['asset' => 'header.png', 'cuts' => [4, 4, 4, 4]]], 'details' => $details]);
  $m = $theme->metrics;
  $sheet = createSyntheticValueSheet();
  $bounds = new CanvasRectangle(50, 40, 40 * $m->cellWidth, 16 * $m->cellHeight);
  $view = new MenuCanvas($theme);
  $positions = $view->details('values', $sheet->layout(40, $theme->createDetailStyle()),
    $sheet->createPage(new TextViewport(), new MenuDetailViewport(40, (int)$bounds->height, $m->cellHeight), $theme->createDetailStyle()), $bounds);
  return [$view->finish(), $theme, $positions];
}

it('aligns each value kind by the style and keeps empty icon rows as records', function () {
  $layout = createSyntheticValueSheet()->layout(40, new MenuDetailStyle(iconCells: 3, iconRoles: ['element.heat']));
  $number = array_find($layout->lines, fn($line) => $line->label === 'Experience');
  $text = array_find($layout->lines, fn($line) => $line->label === 'Kind');
  $icons = array_find($layout->lines, fn($line) => $line->label === 'Weaknesses');
  $empty = array_find($layout->lines, fn($line) => $line->label === 'Immunities');
  expect($number->column + mb_strlen('76'))->toBe(40)
    ->and($text->column)->toBe(mb_strlen('Experience') + 1)
    ->and($icons->items)->toBe([['column' => $text->column, 'role' => 'element.heat', 'text' => ''],
      ['column' => $text->column + 4, 'role' => null, 'text' => 'Gale']])
    ->and($empty->closesField)->toBeTrue()->and($empty->text)->toBe('')
    ->and(array_find($layout->lines, fn($line) => $line->isHeading)->text)->toBe('Rewards');
});

it('shows icon names, an empty marker and the attached suffix in Terminal', function () {
  $lines = array_column(createSyntheticValueSheet()->layout(40, MenuDetailStyle::createTerminal())->getWrappedLines(), 1);
  expect($lines)->toContain('Rewards')
    ->and(implode("\n", $lines))->toMatch('/Weaknesses: +Heat, Gale/')->toMatch('/Immunities: +-/')->toMatch('/Experience: +76$/m');
});

it('draws bound icons, names for unbound ones, themed headings and placement below from theme data', function () {
  [$frame, $theme, $positions] = composeSyntheticValues(['valuePlacement' => ['icons' => 'below'], 'headingMinHeight' => 40,
    'headingColor' => 'focus', 'recordMinHeight' => 40]);
  $images = array_column($frame->images, 'asset');
  $runs = array_merge(...array_map(fn($layer) => $layer->runs, array_filter($frame->textLayers, fn($layer) => str_starts_with($layer->id, 'values'))));
  expect($images)->toContain('heat.png', 'header.png')
    ->and(array_column($runs, 'text'))->toContain('Gale', 'Weaknesses', 'Immunities')->not->toContain('Heat')
    ->and(array_find($runs, fn($run) => $run->text === 'Rewards')->foreground)->toEqual($theme->colors['focus'])
    ->and($positions[0]['height'])->toBe(40);
  $layout = createSyntheticValueSheet()->layout(40, $theme->createDetailStyle());
  $weak = array_search(array_find($layout->lines, fn($line) => $line->label === 'Weaknesses'), $layout->lines, true);
  expect($layout->lines[$weak]->items)->toBe([])->and($layout->lines[$weak + 1]->items[0]['role'])->toBe('element.heat');
});

it('classifies affinity tiers from the multipliers combat applies, every tier present', function () {
  expect(ElementalAffinityTier::classify(1.5))->toBe(ElementalAffinityTier::WEAKNESS)
    ->and(ElementalAffinityTier::classify(0.75))->toBe(ElementalAffinityTier::LESSER_RESISTANCE)
    ->and(ElementalAffinityTier::classify(0.5))->toBe(ElementalAffinityTier::GREATER_RESISTANCE)
    ->and(ElementalAffinityTier::classify(0.0))->toBe(ElementalAffinityTier::IMMUNITY)
    ->and(ElementalAffinityTier::classify(-1.0))->toBe(ElementalAffinityTier::ABSORPTION)
    ->and(ElementalAffinityTier::classify(1.0))->toBeNull();
  expect(ElementalAffinityTier::group(['Gale' => 2.0, 'Heat' => 2.0, 'Cold' => 0.0]))->toBe(['weakness' => ['Heat', 'Gale'],
    'lesser_resistance' => [], 'greater_resistance' => [], 'immunity' => ['Cold'], 'absorption' => []]);
  $entries = ElementalAffinityDetails::createResistanceEntries(['Cold' => -1.0], ['weakness' => ['heat', 'Unlisted']]);
  expect(array_column($entries, 'label'))->toHaveCount(5)
    ->and(array_map(fn($icon) => [$icon->role, $icon->name], $entries[0]->icons))->toBe([['element.heat', 'Heat'], ['element.unlisted', 'Unlisted']])
    ->and($entries[4]->icons[0]->name)->toBe('Cold');
});

it('carries summon affinities through the element vocabulary and writes them only when authored', function () {
  $timeline = ['fps' => 12, 'lengthFrames' => 1, 'tracks' => [], 'cues' => []];
  $plain = SummonCutsceneDefinition::fromArrays(['id' => 'plain', 'name' => 'Plain'], $timeline);
  expect($plain->toDataArray())->not->toHaveKeys(['elementAffinities', 'effectiveAgainst']);
  $summon = SummonCutsceneDefinition::fromArrays(['id' => 'spirit', 'name' => 'Spirit',
    'elementAffinities' => ['heat' => 0.25, 'Cold' => '1.5'], 'effectiveAgainst' => ['cold', 'Cold']], $timeline);
  expect($summon->elementAffinities)->toBe(['Heat' => 0.25, 'Cold' => 1.5])->and($summon->effectiveAgainst)->toBe(['Cold'])
    ->and($summon->toDataArray()['elementAffinities'])->toBe(['Heat' => 0.25, 'Cold' => 1.5]);
  expect(fn() => SummonCutsceneDefinition::fromArrays(['id' => 'bad', 'name' => 'Bad', 'effectiveAgainst' => ['Unlisted']], $timeline))
    ->toThrow(InvalidArgumentException::class);
});
