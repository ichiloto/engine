<?php

use Ichiloto\Engine\Rendering\Presentation\RetainedOrder;

function expectStrictlyIncreasingRanks(array $keys, array $orders): void
{
    $ranks = array_map(fn(string $key) => $orders[$key], $keys);
    expect(array_keys($orders))->toEqualCanonicalizing($keys)
        ->and(min($ranks))->toBeGreaterThanOrEqual(0)->and(max($ranks))->toBeLessThanOrEqual(RetainedOrder::MAX_RANK);
    for ($index = 1; $index < count($ranks); $index++) { expect($ranks[$index])->toBeGreaterThan($ranks[$index - 1]); }
}

it('keeps every surviving rank across an insertion, a removal and a single move', function () {
    $keys = array_map(fn(int $n) => "item-$n", range(0, 49));
    $first = RetainedOrder::getStableOrders($keys, []);
    expectStrictlyIncreasingRanks($keys, $first);
    foreach ([['new', ...$keys], [...array_slice($keys, 0, 20), 'new', ...array_slice($keys, 20)], [...$keys, 'new'],
        array_slice($keys, 1), [...array_slice($keys, 1, 30), $keys[0], ...array_slice($keys, 31)]] as $next) {
        $orders = RetainedOrder::getStableOrders($next, $first);
        expectStrictlyIncreasingRanks($next, $orders);
        $changed = array_filter($next, fn(string $key) => ($first[$key] ?? null) !== $orders[$key]);
        expect(count($changed))->toBeLessThanOrEqual(1);
    }
});

it('renumbers once a gap is exhausted and still returns strictly increasing ranks', function () {
    $orders = RetainedOrder::getStableOrders(['a', 'b'], ['a' => 10, 'b' => 11]);
    expect($orders)->toBe(['a' => 10, 'b' => 11]);
    $keys = ['a', 'x', 'y', 'b'];
    $orders = RetainedOrder::getStableOrders($keys, ['a' => 10, 'b' => 11]);
    expectStrictlyIncreasingRanks($keys, $orders);
    $keys = ['last', 'first'];
    expectStrictlyIncreasingRanks($keys, RetainedOrder::getStableOrders($keys, ['first' => 0, 'last' => RetainedOrder::MAX_RANK]));
});
