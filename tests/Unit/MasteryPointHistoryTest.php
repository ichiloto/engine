<?php

declare(strict_types=1);

use Ichiloto\Engine\Progression\Mastery\MasteryProgress;

it('records actual debits and keeps earned SP independent of later learned-node changes', function () {
  $progress = new MasteryProgress(100);
  $progress->spendPoints(35);
  $progress->markLearned('mutable-node');
  expect($progress->points)->toBe(65)->and($progress->spentPoints)->toBe(35)->and($progress->earnedPoints)->toBe(100);
  $progress->addPoints(20);
  $progress->points -= 10;
  $progress->markLearned('free-node');
  expect($progress->points)->toBe(75)->and($progress->spentPoints)->toBe(45)->and($progress->earnedPoints)->toBe(120);
  $progress->learned = ['renamed-node', 'another-free-node'];
  expect($progress->earnedPoints)->toBe(120);
});

it('round trips spendable and historical SP through JSON and object saves without double counting', function () {
  $progress = new MasteryProgress(90, ['authored-free-node']);
  $progress->spendPoints(30);
  foreach ([MasteryProgress::fromArray(json_decode(json_encode($progress), true)), unserialize(serialize($progress))] as $restored) {
    expect($restored->points)->toBe(60)->and($restored->spentPoints)->toBe(30)->and($restored->earnedPoints)->toBe(90);
    $restored->addPoints(5);
    $restored->spendPoints(5);
    expect($restored->points)->toBe(60)->and($restored->spentPoints)->toBe(35)->and($restored->earnedPoints)->toBe(95);
  }
  $free = new MasteryProgress(5, ['free']);
  expect(MasteryProgress::fromArray($free->jsonSerialize())->spentPoints)->toBe(0)
    ->and(new MasteryProgress()->jsonSerialize())->toBe([]);
});

it('preserves missing historical spending as unknown instead of repricing legacy learned nodes', function () {
  $legacy = MasteryProgress::fromArray(['points' => 40, 'learned' => ['legacy-node']]);
  expect($legacy->spentPoints)->toBeNull()->and($legacy->earnedPoints)->toBeNull();
  $legacy->addPoints(10);
  $legacy->spendPoints(5);
  expect($legacy->points)->toBe(45)->and($legacy->earnedPoints)->toBeNull()
    ->and(MasteryProgress::fromArray($legacy->jsonSerialize())->spentPoints)->toBeNull();
  $class = MasteryProgress::class;
  $oldObject = unserialize(sprintf('O:%d:"%s":2:{s:6:"points";i:40;s:7:"learned";a:1:{i:0;s:6:"legacy";}}', strlen($class), $class));
  expect($oldObject->points)->toBe(40)->and($oldObject->spentPoints)->toBeNull()->and($oldObject->earnedPoints)->toBeNull();
  expect(MasteryProgress::fromArray(['points' => 40])->earnedPoints)->toBe(40);
});

it('rejects negative balances, invalid spending and unsupported totals before mutating history', function () {
  $progress = new MasteryProgress(50);
  $progress->spendPoints(20);
  $before = $progress->jsonSerialize();
  foreach ([fn() => $progress->spendPoints(-1), fn() => $progress->spendPoints(31),
    fn() => $progress->addPoints(-1), fn() => $progress->points = -1,
    fn() => $progress->points = PHP_INT_MAX] as $mutate) {
    expect($mutate)->toThrow(InvalidArgumentException::class)->and($progress->jsonSerialize())->toBe($before);
  }
  foreach ([-1, 1.5, '4', []] as $invalid) {
    expect(fn() => MasteryProgress::fromArray(['points' => 3, 'spentPoints' => $invalid]))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new MasteryProgress(PHP_INT_MAX, spentPoints: 1))->toThrow(InvalidArgumentException::class);
});
