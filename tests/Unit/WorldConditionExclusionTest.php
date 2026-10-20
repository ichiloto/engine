<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\WorldConditionExclusion;

/**
 * Two condition lists exclude each other only through a complement the
 * Engine's evaluator makes certain; anything that might hold together
 * proves nothing.
 */

it('proves lists exclusive through an explicit complement, either way round', function (array $conditions, array $others) {
    expect(WorldConditionExclusion::proveExclusive($conditions, $others))->toBeTrue()
        ->and(WorldConditionExclusion::proveExclusive($others, $conditions))->toBeTrue();
})->with([
    'negated event' => [[['type' => 'event', 'name' => 'x']], [['type' => 'event', 'name' => 'x', 'negate' => true]]],
    'trimmed names' => [[['type' => 'event', 'name' => ' x ']], [['type' => 'event', 'name' => 'x', 'negate' => true]]],
    'switch value' => [[['type' => 'switch', 'name' => 's']], [['type' => 'switch', 'name' => 's', 'value' => false]]],
    'negated switch' => [[['type' => 'switch', 'name' => 's', 'value' => true]], [['type' => 'switch', 'name' => 's', 'negate' => true]]],
    'key item' => [[['type' => 'key_item', 'name' => 'k']], [['type' => 'key_item', 'name' => 'k', 'negate' => true]]],
    'item quantity default' => [[['type' => 'item', 'name' => 'i']], [['type' => 'item', 'name' => 'i', 'quantity' => 1, 'negate' => true]]],
    'quest status default' => [[['type' => 'quest', 'name' => 'q']], [['type' => 'quest', 'name' => 'q', 'status' => 'completed', 'negate' => true]]],
    'map event' => [[['type' => 'map_event', 'name' => 'm', 'marker' => 'A']], [['type' => 'map_event', 'name' => 'm', 'marker' => ' A', 'negate' => true]]],
    'variable equality' => [[['type' => 'variable', 'name' => 'v', 'value' => 2]], [['type' => 'variable', 'name' => 'v', 'op' => '!=', 'value' => 2]]],
    'among others' => [
        [['type' => 'item', 'name' => 'i'], ['type' => 'event', 'name' => 'x']],
        [['type' => 'quest', 'name' => 'q'], ['type' => 'event', 'name' => 'x', 'negate' => true]],
    ],
]);

it('proves nothing when both lists could hold together', function (array $conditions, array $others) {
    expect(WorldConditionExclusion::proveExclusive($conditions, $others))->toBeFalse()
        ->and(WorldConditionExclusion::proveExclusive($others, $conditions))->toBeFalse();
})->with([
    'no conditions' => [[], []],
    'one side unguarded' => [[['type' => 'event', 'name' => 'x']], []],
    'the same condition' => [[['type' => 'event', 'name' => 'x']], [['type' => 'event', 'name' => 'x']]],
    'different names' => [[['type' => 'event', 'name' => 'x']], [['type' => 'event', 'name' => 'y', 'negate' => true]]],
    'different types' => [[['type' => 'event', 'name' => 'x']], [['type' => 'switch', 'name' => 'x', 'negate' => true]]],
    'item quantities' => [[['type' => 'item', 'name' => 'i', 'quantity' => 2]], [['type' => 'item', 'name' => 'i', 'negate' => true]]],
    'quest statuses' => [[['type' => 'quest', 'name' => 'q', 'status' => 'active']], [['type' => 'quest', 'name' => 'q', 'status' => 'completed']]],
    'variable ranges' => [[['type' => 'variable', 'name' => 'v', 'op' => '<', 'value' => 3]], [['type' => 'variable', 'name' => 'v', 'op' => '>=', 'value' => 3]]],
    'variable values' => [[['type' => 'variable', 'name' => 'v', 'value' => 1]], [['type' => 'variable', 'name' => 'v', 'value' => 2]]],
    'markers' => [[['type' => 'map_event', 'name' => 'm', 'marker' => 'A']], [['type' => 'map_event', 'name' => 'm', 'marker' => 'B', 'negate' => true]]],
    'empty names the Engine skips' => [[['type' => 'event', 'name' => '']], [['type' => 'event', 'name' => '', 'negate' => true]]],
    'unknown types' => [[['type' => 'mood', 'name' => 'x']], [['type' => 'mood', 'name' => 'x', 'negate' => true]]],
    'missing markers' => [[['type' => 'map_event', 'name' => 'm']], [['type' => 'map_event', 'name' => 'm', 'negate' => true]]],
    'structured names' => [[['type' => 'event', 'name' => ['x']]], [['type' => 'event', 'name' => ['x'], 'negate' => true]]],
]);
