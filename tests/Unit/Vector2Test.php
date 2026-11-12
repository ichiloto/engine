<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Vector2;

it('compares a moved vector using its current coordinates through direct and arithmetic mutations', function (Closure $move, float $x, float $y) {
    $position = new Vector2(2, 3);
    $old = new Vector2(2, 3);
    $move($position);
    $current = new Vector2($x, $y);
    expect($position->hash)->toBe($current->hash)
        ->and($position->equals($current))->toBeTrue()
        ->and($current->equals($position))->toBeTrue()
        ->and($position->notEquals($old))->toBeTrue()
        ->and($position->compareTo($current))->toBe(0)
        ->and($position->compareTo($old))->toBe($current->compareTo($old))
        ->and((clone $position)->equals($current))->toBeTrue()
        ->and(Vector2::getClone($position)->equals($current))->toBeTrue();
})->with([
    [static function (Vector2 $position): void { $position->x = 9; $position->y = 6; }, 9.0, 6.0],
    [static fn(Vector2 $position) => $position->add(new Vector2(7, 3)), 9.0, 6.0],
    [static fn(Vector2 $position) => $position->subtract(new Vector2(1, 2)), 1.0, 1.0],
    [static fn(Vector2 $position) => $position->multiply(new Vector2(4, 2)), 8.0, 6.0],
    [static fn(Vector2 $position) => $position->divide(new Vector2(2, 3)), 1.0, 1.0],
]);

it('restores old saved coordinates while ignoring a stale cached comparison hash', function () {
    $class = Vector2::class;
    $hash = (new Vector2(2, 3))->hash;
    // The historical default serializer wrote public hash, x and y in this order.
    $legacy = sprintf('O:%d:"%s":3:{s:4:"hash";s:%d:"%s";s:1:"x";d:9;s:1:"y";d:6;}',
        strlen($class), $class, strlen($hash), $hash);
    $restored = unserialize($legacy);
    $fresh = new Vector2(9, 6);
    expect($restored->x)->toBe(9.0)->and($restored->y)->toBe(6.0)
        ->and($restored->equals($fresh))->toBeTrue()
        ->and(serialize($restored))->toBe(serialize($fresh));
    $restored->x = 10;
    expect(unserialize(serialize($restored))->equals(new Vector2(10, 6)))->toBeTrue();
});

it('round trips a moved position without changing the existing vector wire shape', function () {
    $position = new Vector2(1, 1);
    $position->x = -4.5;
    $position->y = 12;
    $saved = serialize($position);
    $fresh = new Vector2(-4.5, 12);
    expect($saved)->toBe(serialize($fresh))
        ->and(unserialize($saved)->equals($fresh))->toBeTrue()
        ->and($position->__serialize())->toHaveKeys(['hash', 'x', 'y']);
});

it('rejects malformed saved coordinates instead of creating a default position', function (array $data) {
    $position = new Vector2();
    expect(fn() => $position->__unserialize($data))->toThrow(UnexpectedValueException::class);
})->with([[[]], [['x' => 1]], [['x' => 'bad', 'y' => 2]], [['x' => 1, 'y' => null]]]);
