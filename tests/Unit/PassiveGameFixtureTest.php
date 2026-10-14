<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\PassiveGame;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

it('collects passive Game cycles without taking ownership of the live rendering lifecycle', function (bool $partialStub, bool $retained) {
    $globals = [];
    foreach ([Console::class, Timers::class] as $class) {
        $globals[$class] = new ReflectionClass($class)->getStaticProperties();
    }
    $transport = new FakeRendererTransport();
    $runtime = new RendererRuntime(new RendererRuntimeConfig(
        new RendererProcessConfig(['fixture']), __DIR__, cellWidth: 10, cellHeight: 20), $transport);

    try {
        foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false] as $key => $value) {
            new ReflectionProperty(Console::class, $key)->setValue(null, $value);
        }
        Console::setTerminalOutputEnabled(false);
        Console::setLayerTracking($retained);
        Console::syncDimensions(32, 4);
        $runtime->start('Passive fixture ownership', 32, 4);
        $tick = static function (): void {};
        $present = static function (): void {};
        Timers::setFrameTick($tick, $present);

        $game = $partialStub
            ? $this->getStubBuilder(PassiveGame::class)->disableOriginalConstructor()
                ->onlyMethods(['getRendererRuntime'])->getStub()
            : new PassiveGame();
        if ($partialStub) {
            $game->method('getRendererRuntime')->willReturn($runtime);
        }
        new ReflectionProperty(Game::class, 'rendererRuntime')->setValue($game, $runtime);
        expect($game->getRendererRuntime())->toBe($runtime);
        $cycle = new stdClass();
        $cycle->game = $game;
        new ReflectionProperty(Game::class, 'options')->setValue($game, ['cycle' => $cycle]);
        $weak = WeakReference::create($game);
        unset($game, $cycle);

        Console::recomposeFrame(function () use ($weak): void {
            Console::write('Before collection', 0, 0);
            gc_collect_cycles();
            expect($weak->get())->toBeNull()
                ->and(Console::isComposing())->toBeTrue()
                ->and(new ReflectionProperty(Console::class, 'terminalHandedBack')->getValue())->toBeFalse();
            Console::write('After collection', 0, 1);
        });

        $rows = array_map(TerminalText::stripAnsi(...), Console::getBuffer());
        expect($rows[0])->toStartWith('Before collection')
            ->and($rows[1])->toStartWith('After collection')
            ->and(Console::isComposing())->toBeFalse()
            ->and(new ReflectionProperty(Timers::class, 'frameTick')->getValue())->toBe($tick)
            ->and(new ReflectionProperty(Timers::class, 'framePresent')->getValue())->toBe($present)
            ->and($transport->isRunning())->toBeTrue()
            ->and($transport->shutdowns)->toBe(0);
    } finally {
        $runtime->shutdown();
        foreach ($globals as $class => $values) {
            foreach ($values as $key => $value) {
                new ReflectionProperty($class, $key)->setValue(null, $value);
            }
        }
    }
})->with([
    'plain fixture, buffered frame' => [false, false],
    'partial stub, buffered frame' => [true, false],
    'plain fixture, retained frame' => [false, true],
    'partial stub, retained frame' => [true, true],
]);
