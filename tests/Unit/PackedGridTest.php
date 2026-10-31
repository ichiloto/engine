<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\PackedGrid;

it('holds ragged rows of any width of value and reads them back exactly', function (int $largest) {
    $rows = [[0, 1, $largest], [], [$largest, 7]];
    $grid = PackedGrid::fromRows($rows);
    expect($grid->readRows())->toBe($rows)
        ->and([$grid->width, $grid->height])->toBe([3, 3])
        ->and(array_map($grid->getRowLength(...), [0, 1, 2, 3, -1]))->toBe([3, 0, 2, 0, 0])
        ->and($grid->getValueAt(2, 0))->toBe($largest)
        ->and($grid->getValueAt(0, 2))->toBe($largest)
        ->and($grid->getValueAt(1, 2))->toBe(7)
        // Past a row's end, above, below or left of the grid there is no cell.
        ->and([$grid->getValueAt(3, 0), $grid->getValueAt(0, 1), $grid->getValueAt(-1, 0), $grid->getValueAt(0, -1),
            $grid->getValueAt(0, 3)])->toBe([null, null, null, null, null])
        ->and($grid->getRowValues(9))->toBe([]);
})->with(['one byte' => [0xFF], 'two bytes' => [0xFFFF], 'four bytes' => [PackedGrid::MAX_VALUE]]);

it('reads rows one at a time from a generator and refuses values it cannot hold', function () {
    $read = 0;
    $grid = PackedGrid::fromRows((function () use (&$read): Generator {
        foreach ([[3, 2], [1, 0]] as $row) { $read++; yield $row; }
    })());
    expect($read)->toBe(2)->and($grid->readRows())->toBe([[3, 2], [1, 0]])
        ->and(fn() => PackedGrid::fromRows([[-1]]))->toThrow(InvalidArgumentException::class)
        ->and(fn() => PackedGrid::fromRows([[PackedGrid::MAX_VALUE + 1]]))->toThrow(InvalidArgumentException::class);
});
