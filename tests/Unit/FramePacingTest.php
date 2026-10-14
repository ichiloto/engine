<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\FramePacing;

it('budgets only the unslept part of a frame in whole microseconds', function (int|float|string $fps, int $workNanoseconds, int $sleepMicroseconds) {
    $pacing = new FramePacing($fps);
    $start = 8_000_000_000;
    expect($pacing->getRemainingSleepMicroseconds($start, $start + $workNanoseconds))->toBe($sleepMicroseconds);
})->with([
    '30 fps idle' => [30, 0, 33_333],
    '60 fps idle' => [60, 0, 16_666],
    '30 fps working' => [30, 4_426_000, 28_907],
    '60 fps working' => [60, 6_000_000, 10_666],
    'fractional fps' => [29.97, 10_000_000, 23_366],
    'numeric configuration string' => ['30', 10_000_000, 23_333],
    'partial microsecond remainder' => [1000, 999_001, 0],
    'exact budget' => [40, 25_000_000, 0],
    'small overrun' => [40, 25_000_001, 0],
    'large overrun' => [40, 200_000_000, 0],
    'sub-microsecond budget' => [2_000_000, 0, 0],
]);

it('starts a fresh budget after variable work overruns and scheduler oversleep without catch-up debt', function () {
    $pacing = new FramePacing(40);
    $now = 8_000_000_000;
    $sleeps = [];
    $intervals = [];
    foreach ([2_000, 22_000, 28_000, 0, 1_000] as $index => $workMicroseconds) {
        $start = $now;
        $now += $workMicroseconds * 1_000;
        $sleep = $pacing->getRemainingSleepMicroseconds($start, $now);
        $sleeps[] = $sleep;
        $now += ($sleep + ($index === 0 ? 5_000 : 0)) * 1_000;
        $intervals[] = ($now - $start) / 1_000;
    }
    expect($sleeps)->toBe([23_000, 3_000, 0, 25_000, 24_000])
        ->and($intervals)->toBe([30_000, 25_000, 28_000, 25_000, 25_000]);
});

it('rejects invalid fps instead of dividing by zero silently disabling pacing or inventing a default', function (mixed $fps) {
    expect(fn() => new FramePacing($fps))->toThrow(InvalidArgumentException::class, 'FPS must');
})->with([
    'zero' => [0], 'negative' => [-30], 'negative fraction' => [-0.5],
    'nan' => [NAN], 'infinity' => [INF], 'negative infinity' => [-INF],
    'zero string' => ['0'], 'negative string' => ['-30'], 'overflowing string' => ['1e309'],
    'empty string' => [''], 'non-numeric string' => ['fast'], 'null' => [null],
    'true' => [true], 'false' => [false], 'array' => [[]], 'object' => [new stdClass()],
    'unrepresentable budget' => [1e-300],
]);

it('paces the real shared Game loop after frame work and preserves immediate quit paths', function (string $quitPhase, array $expectedSleeps, array $expectedIntervals, int $updates, int $renders) {
    // Namespace-local clock/sleep doubles are isolated in a child PHP process, never installed in the suite.
    $script = <<<'PHP'
        namespace Ichiloto\Engine\Core {
            function hrtime(bool $asNumber): int { return $GLOBALS['pacingNow']; }
            function microtime(bool $asNumber): float {
                $GLOBALS['pacingNow'] += 1_000_000;
                return $GLOBALS['pacingNow'] / 1_000_000_000;
            }
            function usleep(int $microseconds): void {
                $GLOBALS['pacingSleeps'][] = $microseconds;
                $GLOBALS['pacingNow'] += $microseconds * 1_000;
            }
        }
        namespace {
            require $argv[1];
            use Assegai\Collections\ItemList;
            use Ichiloto\Engine\Core\Game;
            use Ichiloto\Engine\Diagnostics\LatencyTrace;
            use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
            use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
            use Ichiloto\Engine\IO\Console\Console;

            $GLOBALS['pacingNow'] = 8_000_000_000;
            $GLOBALS['pacingSleeps'] = [];
            $starts = [];
            LatencyTrace::configure(static function (array $record) use (&$starts): void {
                if ($record['stage'] === 'game.iteration.begin') { $starts[] = $record['at_ns']; }
                $GLOBALS['pacingNow'] += 1_000_000;
            }, static fn(): int => $GLOBALS['pacingNow']);
            Console::setTerminalOutputEnabled(false);
            $game = new class($argv[2]) extends Game {
                public int $updates = 0;
                public int $renders = 0;
                private array $work = [[2_000_000, 3_000_000, 5_000_000], [10_000_000, 10_000_000, 15_000_000], [1_000_000, 1_000_000, 1_000_000]];
                public function __construct(private string $quitPhase) {
                    $this->options = ['fps' => 40];
                    $this->observers = new ItemList(ObserverInterface::class);
                    $this->staticObservers = new ItemList(StaticObserverInterface::class);
                }
                public function __destruct() {}
                protected function start(): void {
                    $GLOBALS['pacingNow'] += 5_000_000_000;
                    $this->isRunning = true;
                }
                protected function handleInput(): void {
                    if ($this->quitPhase === 'input') { $this->quit(); return; }
                    $GLOBALS['pacingNow'] += $this->work[$this->renders][0] ?? 0;
                }
                protected function update(): void {
                    $this->updates++;
                    if ($this->quitPhase === 'update' || $this->renders === count($this->work)) { $this->quit(); return; }
                    $GLOBALS['pacingNow'] += $this->work[$this->renders][1];
                }
                protected function render(): void {
                    $GLOBALS['pacingNow'] += $this->work[$this->renders][2];
                    $this->renders++;
                }
            };
            $game->run();
            $intervals = [];
            for ($index = 1; $index < count($starts); $index++) { $intervals[] = $starts[$index] - $starts[$index - 1]; }
            echo json_encode([$GLOBALS['pacingSleeps'], $intervals, $game->updates, $game->renders, $game->hasStopped()], JSON_THROW_ON_ERROR);
        }
        PHP;
    $root = createTestDirectory('ichiloto-frame-pacing-');
    $process = proc_open([PHP_BINARY, '-r', $script, dirname(__DIR__, 2) . '/vendor/autoload.php', $quitPhase],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    expect(is_resource($process))->toBeTrue();
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toBe([$expectedSleeps, $expectedIntervals, $updates, $renders, true]);
})->with([
    'variable completed frames' => ['complete', [12_000, 19_000], [25_000_000, 38_000_000, 25_000_000], 4, 3],
    'quit during input' => ['input', [], [], 0, 0],
    'quit during update' => ['update', [], [], 1, 0],
]);
