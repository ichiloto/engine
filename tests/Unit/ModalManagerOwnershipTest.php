<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\Modal\{ModalManager, NoticeContent};
use Tests\Support\PassiveGame;

it('isolates notices and cancellation between Games even with the same producer token', function () {
    $a = new PassiveGame();
    $b = new PassiveGame();
    $one = ModalManager::getInstance($a);
    $two = ModalManager::getInstance($b);
    $owner = new stdClass();
    $one->queueNotice(new NoticeContent('First', 'First campaign'), $owner);
    $two->queueNotice(new NoticeContent('Second', 'Second campaign'), $owner);
    expect($one)->not->toBe($two)
        ->and($a->getModalManager())->toBe($one)->and($b->getModalManager())->toBe($two)
        ->and($one->game)->toBe($a)->and($two->game)->toBe($b);
    $one->cancelNotices($owner);
    expect($one->hasPendingNotices($owner))->toBeFalse()
        ->and($two->hasPendingNotices($owner))->toBeTrue();
    $two->cancelNotices($owner);
});

it('rejects a foreign modal owner and a replacement after acquisition', function () {
    $a = new PassiveGame();
    $b = new PassiveGame();
    $manager = new ModalManager($a);
    expect(fn() => $b->bindModalManager($manager))->toThrow(LogicException::class);
    $a->bindModalManager($manager);
    expect($a->getModalManager())->toBe($manager)
        ->and(fn() => $a->bindModalManager(new ModalManager($a)))->toThrow(LogicException::class);
});

it('releases retired Game queues without a process-wide retaining root', function () {
    $game = new PassiveGame();
    $manager = $game->getModalManager();
    $weakGame = WeakReference::create($game);
    $weakManager = WeakReference::create($manager);
    unset($manager, $game);
    gc_collect_cycles();
    expect($weakGame->get())->toBeNull()->and($weakManager->get())->toBeNull();
});
