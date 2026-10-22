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

it('sets a sub-family inside the tree of the family it grows within, under that family\'s top, keeping its own top, name and nodes', function () {
  $node = static fn(string $id, string $kind, array $extra = []): array => ['id' => $id, 'kind' => $kind, ...($kind === 'foundation' ? ['name' => ucfirst($id)] : ['skill' => ucfirst($id)]), ...$extra];
  $board = new MasteryBoard(MasteryTree::fromArray([
    'regions' => [['id' => 'stars', 'name' => 'Stars']],
    'branches' => [
      // Declared before the family it grows within, as an author may leave it: the board still sets it inside.
      ['id' => 'hours', 'region' => 'stars', 'name' => 'Hours', 'kind' => 'non-elemental', 'within' => 'odd', 'nodes' => [
        $node('hasten', 'application'), $node('linger', 'application'), $node('skip', 'advanced')]],
      ['id' => 'flame', 'region' => 'stars', 'name' => 'Flame', 'kind' => 'element', 'element' => 'Fire', 'nodes' => [
        $node('flame', 'foundation'), $node('spark-1', 'application', ['grade' => 1])]],
      ['id' => 'ember', 'region' => 'stars', 'name' => 'Ember', 'kind' => 'aspect', 'within' => 'flame', 'nodes' => [
        $node('ember', 'foundation'), $node('glow', 'application')]],
      ['id' => 'odd', 'region' => 'stars', 'name' => 'Odd', 'kind' => 'non-elemental', 'nodes' => [$node('quirk', 'application')]],
    ],
  ]));
  $odd = $board->findFamily('odd');
  $hours = $board->findFamily('hours');
  $heading = MasteryFamily::getHeadingId('hours');

  // The discipline lists only the families growing from it; a sub-family is found by its id and counts within its family.
  expect(array_map(static fn(MasteryFamily $family): string => $family->id, $board->getFamilies('stars')))->toBe(['flame', 'odd'])
    ->and($hours->parent)->toBe('odd')
    ->and($odd->nodes)->toBe(['quirk', 'hasten', 'linger', 'skip'])
    ->and($odd->ownNodes)->toBe(['quirk'])
    ->and($board->getFamilyPlaceIds($odd))->toBe([MasteryFamily::getHeadingId('odd'), 'quirk', $heading, 'hasten', 'linger', 'skip'])
    ->and($board->places[$heading]['parent'])->toBe(MasteryFamily::getHeadingId('odd'))
    ->and($board->places['skip']['parent'])->toBe($heading)
    ->and($board->isHeading($heading))->toBeTrue()
    // A sub-family's places belong to the outer family on the discipline, and are the sub-family's own.
    ->and([$board->places['hasten']['family'], $board->places['hasten']['subfamily']])->toBe(['odd', 'hours'])
    ->and($board->findPlaceFamily($heading))->toBe($odd)
    ->and($board->findPlaceSubfamily($heading))->toBe($hours)
    ->and($board->findPlaceSubfamily('quirk'))->toBe($odd)
    // A sub-family with a foundation hangs it from the outer foundation its chain grows from.
    ->and($board->places['ember']['parent'])->toBe('flame')
    ->and($board->children['flame'])->toBe(['spark-1', 'ember'])
    ->and($board->places['glow']['parent'])->toBe('ember')
    ->and($board->widths['stars'])->toBe(6);
});

/** A synthetic discipline whose own nodes hang from its heading as a row of $count, and a family whose top carries wide subtrees. */
function masteryWideTree(int $count): MasteryTree
{
  $node = static fn(string $id, string $kind, array $extra = []): array => ['id' => $id, 'kind' => $kind, ...($kind === 'foundation' ? ['name' => ucfirst($id)] : ['skill' => ucfirst($id)]), ...$extra];
  $chain = static fn(string $prefix): array => [$node("{$prefix}-1", 'application', ['grade' => 1]), $node("{$prefix}-2", 'application', ['grade' => 2])];

  return MasteryTree::fromArray([
    'regions' => [['id' => 'stones', 'name' => 'Stones', 'nodes' => array_map(static fn(int $n): array => $node("stone-{$n}", 'application'), range(1, $count))]],
    'branches' => [
      ['id' => 'tide', 'region' => 'stones', 'name' => 'Tide', 'kind' => 'aspect', 'nodes' => [$node('tide', 'foundation'), ...$chain('wave')]],
      ...array_map(static fn(string $id): array => ['id' => $id, 'region' => 'stones', 'name' => ucfirst($id), 'kind' => 'aspect', 'within' => 'tide',
        'nodes' => [$node($id, 'foundation'), $node("{$id}-a", 'application'), $node("{$id}-b", 'application'), $node("{$id}-c", 'application')]],
        ['shoal', 'reef', 'cove']),
    ],
  ]);
}

it('wraps a row of siblings wider than four lanes onto rows below, each within reach of what it hangs from and joined by straight lines', function (int $count) {
  $board = new MasteryBoard(masteryWideTree($count));
  $subtree = static function (string $id) use (&$subtree, $board): array {
    return [$id, ...array_merge([], ...array_map($subtree, $board->children[$id] ?? []))];
  };
  foreach ($board->places as $id => $place) {
    $children = $board->children[$id] ?? [];
    if ($children === []) {
      continue;
    }
    $rows = [];
    foreach ($children as $child) {
      $rows[$board->places[$child]['row']][] = $child;
    }
    ksort($rows);
    foreach ($rows as $row => $members) {
      $lanes = array_map(static fn(string $child): float => $board->places[$child]['lane'], $members);
      $stages = array_unique(array_map(static fn(string $child): int => $board->places[$child]['stage'], $members));
      // A row is one stage, below everything of the rows before it, and no wider than four lanes of single places.
      expect($stages)->toHaveCount(1);
      $below = max(array_map(static fn(string $member): int => max(array_map(static fn(string $place): int => $board->places[$place]['stage'],
        $subtree($member))), $rows[$row - 1] ?? [$id]));
      expect(reset($stages))->toBe($row === 0 ? $place['stage'] + 1 : $below + 1)
        // Every sibling stands within one and a half lanes of what it hangs from.
        ->and(max(array_map(static fn(float $lane): float => abs($lane - $place['lane']), $lanes)))->toBeLessThanOrEqual(1.5);
      if ($row === array_key_last($rows)) {
        // The last row is centred under it.
        expect($place['lane'])->toBe((min($lanes) + max($lanes)) / 2);
      } else {
        // Its line runs down through a gap: nothing of an earlier row's subtrees stands within half a lane of it.
        foreach ($members as $member) {
          foreach ($subtree($member) as $inside) {
            expect(abs($board->places[$inside]['lane'] - $place['lane']))->toBeGreaterThanOrEqual(0.5);
          }
        }
      }
    }
  }
  // Wrapping changes where places stand, never the order a list shows them in.
  $trunk = $board->getFamilies('stones')[1];
  expect($board->getFamilyPlaceIds($trunk))->toBe([MasteryFamily::getHeadingId(MasteryFamily::getTrunkId('stones')),
    ...array_map(static fn(int $n): string => "stone-{$n}", range(1, $count))])
    ->and(max(array_column(array_intersect_key($board->places, array_flip($board->getFamilyPlaceIds($trunk))), 'row')))->toBe(intdiv($count - 1, 4));
  // Siblings and cousins never share a place.
  $taken = [];
  foreach ($board->places as $id => $place) {
    $key = $place['stage'] . ':' . $place['lane'];
    expect($taken)->not->toHaveKey($key);
    $taken[$key] = $id;
  }
})->with([5, 8, 9, 12]);
