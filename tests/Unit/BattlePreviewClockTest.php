<?php

require_once __DIR__ . '/../../tools/BattlePreviewClock.php';

it('maps inspector window controls to the same bounded clock commands', function () {
    $clock = new BattlePreviewClock(2);
    expect($clock->applyKey('right'))->toBeTrue();
    $clock->advanceTime(0);
    expect($clock->elapsedSeconds)->toBe(1.0);
    expect($clock->applyKey('down'))->toBeTrue();
    $clock->advanceTime(0);
    expect($clock->elapsedSeconds)->toBe(1.1);
    expect($clock->applyKey('space'))->toBeTrue()->and($clock->isPlaying)->toBeTrue();
    $clock->advanceTime(.1);
    expect($clock->applyKey('space'))->toBeTrue()->and($clock->isPlaying)->toBeFalse();
    $clock->advanceTime(100);
    $paused = $clock->elapsedSeconds;
    expect($paused)->toEqualWithDelta(1.2, .0000001);
    expect($clock->applyKey('left'))->toBeFalse()->and($clock->elapsedSeconds)->toBe($paused);
    expect(fn() => $clock->applyKey('escape'))->toThrow(RuntimeException::class)
        ->and($clock->isCompleted)->toBeFalse();
    $clock->applyKey('right');
    $clock->advanceTime(0);
    expect($clock->elapsedSeconds)->toBe(2.0)->and($clock->isCompleted)->toBeTrue();
});

it('holds inspection time, steps all frames, and resumes without adding paused wall time', function () {
    $clock = new BattlePreviewClock(2);
    expect($clock->advanceTime(100))->toBe([])->and($clock->elapsedSeconds)->toBe(0.0);
    $clock->applyCommand('step 0.5');
    $frames = $clock->advanceTime(100);
    expect(count($frames))->toBeGreaterThanOrEqual(30)->and(end($frames))->toBe(0.5);
    foreach ($frames as $index => $time) {
        expect($time - ($frames[$index - 1] ?? 0))->toBeLessThanOrEqual(1 / 60 + 0.000001);
    }
    $clock->applyCommand('play');
    $clock->advanceTime(0.25);
    expect($clock->elapsedSeconds)->toBe(0.75);
    $clock->applyCommand('pause');
    expect($clock->advanceTime(100))->toBe([])->and($clock->elapsedSeconds)->toBe(0.75);
    $clock->applyCommand('step 2');
    $clock->advanceTime(0);
    expect($clock->elapsedSeconds)->toBe(2.0)->and($clock->isCompleted)->toBeTrue();
});

it('refuses invalid inspection commands and never treats quitting as success', function (string $command) {
    $clock = new BattlePreviewClock(2);
    expect(fn() => $clock->applyCommand($command))->toThrow($command === 'quit' ? RuntimeException::class : InvalidArgumentException::class)
        ->and($clock->elapsedSeconds)->toBe(0.0)->and($clock->isCompleted)->toBeFalse();
})->with(['step 0', 'step -1', 'step INF', 'step 3', 'skip 1', 'quit']);

it('refuses a backwards or non-finite inspection clock', function (float $delta) {
    expect(fn() => new BattlePreviewClock(2)->advanceTime($delta))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, NAN]);
