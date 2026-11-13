<?php

declare(strict_types=1);

use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Interfaces\{LayeredPresentationInterface, UIElementInterface};
use Tests\Support\PassiveGame;

it('keeps UI elements and presentation layers with their owning Game', function () {
    $a = new PassiveGame();
    $b = new PassiveGame();
    $one = UIManager::getInstance($a);
    $two = UIManager::getInstance($b);
    $presentation = $this->createStub(LayeredPresentationInterface::class);
    $one->present($presentation);
    $element = $this->createStub(UIElementInterface::class);
    $one->uiElements->add($element);
    expect($one)->not->toBe($two)
        ->and($a->getUIManager())->toBe($one)
        ->and(UIManager::getInstance($a))->toBe($one)
        ->and($b->getUIManager())->toBe($two)
        ->and($one->game)->toBe($a)->and($two->game)->toBe($b)
        ->and($one->uiElements->count())->toBe(1)
        ->and($two->uiElements->count())->toBe(0)
        ->and($one->getActivePresentations())->toBe([$presentation])
        ->and($two->getActivePresentations())->toBe([]);
    $one->dismiss($presentation);
    $one->commitPresentationChanges();
    expect($one->getActivePresentations())->toBe([]);
});

it('rejects a foreign UI owner and replacement after first acquisition', function () {
    $a = new PassiveGame();
    $b = new PassiveGame();
    $manager = new UIManager($a);
    expect(fn() => $b->bindUIManager($manager))->toThrow(LogicException::class);
    $a->bindUIManager($manager);
    expect(UIManager::getInstance($a))->toBe($manager)
        ->and(fn() => $a->bindUIManager(new UIManager($a)))->toThrow(LogicException::class);
    $b->getUIManager();
    expect(fn() => $b->bindUIManager(new UIManager($b)))->toThrow(LogicException::class);
});

it('releases a retired Game and UI graph without a process-wide retaining root', function () {
    $game = new PassiveGame();
    $manager = UIManager::getInstance($game);
    $weakGame = WeakReference::create($game);
    $weakManager = WeakReference::create($manager);
    unset($manager, $game);
    gc_collect_cycles();
    expect($weakGame->get())->toBeNull()->and($weakManager->get())->toBeNull();
});
