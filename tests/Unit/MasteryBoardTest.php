<?php

declare(strict_types=1);

use Ichiloto\Engine\Progression\Mastery\MasteryBoard;
use Ichiloto\Engine\Progression\Mastery\MasteryFamily;
use Ichiloto\Engine\Progression\Mastery\MasteryTree;

/** A synthetic tree: a graded element branch, an aspect branching into two advanced nodes, a branch without a foundation, and a trunk. */
function masteryBoardTree(): MasteryTree
{
  $node = static fn(string $id, string $kind, array $extra = []): array => ['id' => $id, 'kind' => $kind, ...($kind === 'foundation' ? ['name' => ucfirst($id)] : ['skill' => ucfirst($id)]), ...$extra];

  return MasteryTree::fromArray([
    'regions' => [
      ['id' => 'stars', 'name' => 'Stars'],
      ['id' => 'stones', 'name' => 'Stones', 'nodes' => [$node('pebble', 'application'), $node('boulder', 'application'), $node('rubble', 'application')]],
    ],
    'branches' => [
      ['id' => 'flame', 'region' => 'stars', 'name' => 'Flame', 'kind' => 'element', 'element' => 'Fire', 'nodes' => [
        $node('flame', 'foundation'), $node('spark-1', 'application', ['grade' => 1]), $node('spark-2', 'application', ['grade' => 2]),
        $node('blaze', 'advanced')]],
      ['id' => 'calm', 'region' => 'stars', 'name' => 'Calm', 'kind' => 'aspect', 'nodes' => [
        $node('calm', 'foundation'), $node('soothe-1', 'application', ['grade' => 1]), $node('lull', 'advanced'), $node('hush', 'advanced')]],
      ['id' => 'odd', 'region' => 'stars', 'name' => 'Odd', 'kind' => 'non-elemental', 'nodes' => [$node('quirk', 'application'), $node('whim', 'application')]],
    ],
  ]);
}

it('gives every node one regular place: each hangs one stage below the step it builds on, centred over what hangs from it', function () {
  $board = new MasteryBoard(masteryBoardTree());

  foreach ($board->places as $id => $place) {
    $children = $board->children[$id] ?? [];
    foreach ($children as $child) {
      expect($board->places[$child]['stage'])->toBe($place['stage'] + 1)
        ->and($board->places[$child]['parent'])->toBe($id);
    }
    if ($children !== []) {
      $lanes = array_map(static fn(string $child): float => $board->places[$child]['lane'], $children);
      expect($place['lane'])->toBe((min($lanes) + max($lanes)) / 2);
    }
  }
  // Siblings and cousins never share a lane at one stage.
  $taken = [];
  foreach ($board->places as $id => $place) {
    $key = $place['region'] . ':' . $place['stage'] . ':' . $place['lane'];
    expect($taken)->not->toHaveKey($key);
    $taken[$key] = $id;
  }
  expect($board->places['blaze']['parent'])->toBe('spark-2')
    ->and($board->children['soothe-1'])->toBe(['lull', 'hush']);
});

it('sets families side by side, each topped by its foundation or a heading, and lists them top first', function () {
  $board = new MasteryBoard(masteryBoardTree());
  $families = $board->getFamilies('stars');
  $rightEdges = [];
  foreach ($families as $family) {
    $lanes = array_map(static fn(string $id): float => $board->places[$id]['lane'], $board->getFamilyPlaceIds($family));
    $rightEdges[] = [min($lanes), max($lanes)];
  }

  expect(array_map(static fn(MasteryFamily $family): string => $family->id, $families))->toBe(['flame', 'calm', 'odd'])
    ->and(MasteryBoard::getRootId($families[0]))->toBe('flame')
    ->and(MasteryBoard::getRootId($families[2]))->toBe(MasteryFamily::getHeadingId('odd'))
    ->and($board->isHeading(MasteryFamily::getHeadingId('odd')))->toBeTrue()
    ->and($board->isHeading('flame'))->toBeFalse()
    ->and($rightEdges[0][1])->toBeLessThan($rightEdges[1][0])
    ->and($rightEdges[1][1])->toBeLessThan($rightEdges[2][0])
    ->and($board->widths['stars'])->toBe(5)
    ->and($board->getFamilyPlaceIds($families[1]))->toBe(['calm', 'soothe-1', 'lull', 'hush'])
    // A discipline's own nodes gather under its name as one family.
    ->and($board->getFamilies('stones')[0]->name)->toBe('Stones')
    ->and($board->getFamilyPlaceIds($board->getFamilies('stones')[0]))->toBe([MasteryFamily::getHeadingId(MasteryFamily::getTrunkId('stones')), 'pebble', 'boulder', 'rubble']);
});
