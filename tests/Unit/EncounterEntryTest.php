<?php

use Ichiloto\Engine\Field\EncounterEntry;

it('reads legacy and structured encounter weights through the same contract', function (mixed $value, int $weight) {
  $entry = EncounterEntry::getFromValue($value);
  expect($entry?->weight)->toBe($weight)
    ->and($entry?->getBattleSettings(['battleArena' => 'arena.map']))->toBe(['battleArena' => 'arena.map']);
})->with([
  'integer' => [5, 5],
  'numeric string' => ['5', 5],
  'legacy fractional coercion' => [5.8, 5],
  'structured' => [['weight' => 5], 5],
  'structured numeric string' => [['weight' => '5'], 5],
  'unowned metadata' => [['weight' => 5, 'weatherBias' => ['rain' => 2]], 5],
]);

it('keeps unreadable and nonpositive weights out of the encounter table', function (mixed $value) {
  expect(EncounterEntry::getFromValue($value))->toBeNull();
})->with([
  'zero' => [0], 'negative' => [-1], 'null' => [null], 'boolean' => [true],
  'text' => ['often'], 'missing structured weight' => [['battleArena' => 'arena.row']],
  'structured zero' => [['weight' => 0]], 'nested weight' => [['weight' => []]],
]);

it('selects the encounter scene without binding an arena to its troop', function () {
  $lake = EncounterEntry::getFromValue(['weight' => 1, 'battleArena' => 'arena.lake']);
  $road = EncounterEntry::getFromValue(['weight' => 1]);
  expect($lake->getBattleSettings(['battleArena' => 'arena.map']))->toBe(['battleArena' => 'arena.lake'])
    ->and($lake->getBattleSettings([]))->toBe(['battleArena' => 'arena.lake'])
    ->and($road->getBattleSettings(['battleArena' => 'arena.map']))->toBe(['battleArena' => 'arena.map'])
    ->and($road->getBattleSettings([]))->toBe([]);
});

it('preserves an invalid optional arena for graphical diagnostics without discarding a playable encounter', function (mixed $arena) {
  $entry = EncounterEntry::getFromValue(['weight' => 2, 'battleArena' => $arena]);
  expect($entry?->weight)->toBe(2)
    ->and($entry?->getBattleSettings(['battleArena' => 'arena.map']))->toBe(['battleArena' => $arena]);
})->with(['null' => [null], 'number' => [42], 'array' => [[]], 'empty key' => ['']]);
