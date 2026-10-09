#!/usr/bin/env php
<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

require dirname(__DIR__) . '/vendor/autoload.php';

// Trusted fixtures own isolated game/config/audio/save state, never the user's running game.
$runtime = null;
$game = null;
$fixture = null;
$result = 0;
$report = '';
$requireSilence = static function (): void {
    foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
        if (!ConfigStore::has($class)) { throw new RuntimeException('Scene preview requires explicit silent settings.'); }
        $config = ConfigStore::get($class);
        if ($config->get('audio.music') !== false || $config->get('audio.sfx') !== false
            || $config->get('audio.master_volume') !== 0) {
            throw new RuntimeException('Scene preview requires muted music, sound effects and master volume before launch.');
        }
    }
};
try {
    $options = getopt('', ['renderer:', 'asset-root:', 'fixture:', 'subjects:', 'duration:', 'transitions:', 'no-launch', 'reduced-motion', 'wait-start', 'help']);
    if (isset($options['help'])) {
        echo "Usage: php tools/gpui-scene-preview.php --asset-root=PATH --fixture=PHP [--renderer=PATH]\n"
            . "  [--subjects=ID,ID --duration=120 --transitions=project|on|off --reduced-motion --no-launch]\n"
            . "The trusted factory accepts root, reducedMotion, optional subject IDs and transition mode. It returns game, grid,\n"
            . "start/advance(seconds)/isComplete/verify/dispose callbacks. Initial scenes start after runtime attachment.\n"
            . "Native startup uses the resolved Game viewport after attachment, keeping the fixture's cell size.\n"
            . "Fixtures using the production wall clock must return realtime=true, including for CPU runs.\n"
            . "An explicit transition mode must be acknowledged by the fixture and applied only to its isolated settings.\n"
            . "Uses production scene rendering, including retained field/world and canvas handoffs; no synthetic field canvas.\n"
            . "--wait-start holds before scene startup until stdin receives start or the native window receives Enter.\n"
            . "The startup hold has a thirty-minute limit and does not change production time.\n"
            . "Silent, bounded to 1..300 seconds, closes automatically. Fixtures must refuse project/save writes.\n";
        exit(0);
    }
    $root = $options['asset-root'] ?? '';
    $fixturePath = $options['fixture'] ?? '';
    $renderer = $options['renderer'] ?? '';
    $duration = filter_var($options['duration'] ?? '120', FILTER_VALIDATE_FLOAT);
    $noLaunch = isset($options['no-launch']);
    $waitStart = isset($options['wait-start']);
    $reduced = isset($options['reduced-motion']);
    $transitionProvided = array_any($argv, static fn(string $argument): bool => $argument === '--transitions'
        || str_starts_with($argument, '--transitions='));
    $transitionMode = $options['transitions'] ?? ($transitionProvided ? null : 'project');
    if (!is_string($transitionMode) || !in_array($transitionMode, ['project', 'on', 'off'], true)) {
        throw new InvalidArgumentException('Transition mode must be project, on or off.');
    }
    $selectionProvided = array_any($argv, static fn(string $argument): bool => $argument === '--subjects'
        || str_starts_with($argument, '--subjects='));
    if ($selectionProvided && (!isset($options['subjects']) || !is_string($options['subjects']))) {
        throw new InvalidArgumentException('Preview subject IDs must be non-empty, distinct and at most 64.');
    }
    $subjects = isset($options['subjects']) ? array_map(trim(...), explode(',', $options['subjects'])) : null;
    if ($subjects !== null && (count($subjects) > 64 || in_array('', $subjects, true)
        || count(array_unique($subjects)) !== count($subjects))) {
        throw new InvalidArgumentException('Preview subject IDs must be non-empty, distinct and at most 64.');
    }
    if (!is_string($root) || !is_dir($root) || !is_string($fixturePath) || !is_file($fixturePath)
        || $duration === false || !is_finite($duration) || $duration < 1 || $duration > 300
        || (!$noLaunch && (!is_string($renderer) || !is_file($renderer) || !is_executable($renderer)))) {
        throw new InvalidArgumentException('Provide readable assets/fixture paths, an executable renderer and duration 1..300.');
    }
    $factory = (static fn(string $path): mixed => require $path)($fixturePath);
    if (!is_callable($factory)) { throw new InvalidArgumentException('Scene preview fixture must return a factory.'); }
    Console::setTerminalOutputEnabled(false);
    $fixture = $factory($root, $reduced, $subjects, $transitionMode);
    if (!is_array($fixture) || !($fixture['game'] ?? null) instanceof Game
        || !($fixture['grid'] ?? null) instanceof RendererGridConfig) {
        throw new InvalidArgumentException('Scene preview fixture requires a Game and RendererGridConfig.');
    }
    foreach (['start', 'advance', 'isComplete', 'verify', 'dispose'] as $callback) {
        if (!is_callable($fixture[$callback] ?? null)) {
            throw new InvalidArgumentException('Scene preview fixture requires ' . $callback . ' callback.');
        }
    }
    $game = $fixture['game'];
    $grid = $fixture['grid'];
    $realtime = $fixture['realtime'] ?? false;
    if (!is_bool($realtime)) { throw new InvalidArgumentException('Scene preview realtime policy must be a boolean.'); }
    $realtime = !$noLaunch || $realtime;
    if ($subjects !== null && ($fixture['subjects'] ?? null) !== $subjects) {
        throw new InvalidArgumentException('The fixture must validate and acknowledge the exact requested subject IDs.');
    }
    if ($transitionProvided && ($fixture['transitionMode'] ?? null) !== $transitionMode) {
        throw new InvalidArgumentException('The fixture must acknowledge the exact requested transition mode.');
    }
    $requireSilence();
    if (!$noLaunch) {
        $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig([$renderer]), $root,
            $grid->cellWidth, $grid->cellHeight, requiredCapabilities: $fixture['requiredCapabilities'] ?? []));
        $game->useRendererRuntime($runtime);
        // Attaching may reconfigure Game; check the effective settings before creating a native process.
        $requireSilence();
        // Game owns logical dimensions; attachment may replace terminal auto-size with the graphical viewport.
        $runtime->start('Ichiloto scene acceptance preview (silent)', Console::getWidth(), Console::getHeight());
    }
    if ($waitStart) {
        // Bind inspection to the owned window before the production wall clock starts.
        $runtime?->present(null);
        stream_set_blocking(STDIN, false);
        fwrite(STDERR, "Waiting before scene startup. Send start on stdin or Enter in the native window; no gameplay clock is running.\n");
        $waitingSince = hrtime(true);
        while (true) {
            $runtime?->pump();
            $requireSilence();
            if ($runtime !== null && InputManager::getInputSource()?->poll() === KeyCode::ENTER) {
                InputManager::getInputSource()?->reset(true);
                break;
            }
            $command = fgets(STDIN);
            if ($command !== false) {
                if (trim($command) !== 'start') {
                    throw new InvalidArgumentException('Scene startup accepts only start.');
                }
                break;
            }
            if (feof(STDIN)) { throw new RuntimeException('Scene startup input closed before start.'); }
            if ((hrtime(true) - $waitingSince) / 1_000_000_000 >= 1800) {
                throw new RuntimeException('Scene startup reached its thirty-minute wall limit.');
            }
            usleep(16667);
        }
    }
    $fixture['start']();
    $requireSilence();
    $frames = $changes = 0;
    $seconds = 0.0;
    $started = hrtime(true) / 1_000_000_000;
    while (true) {
        $runtime?->pump();
        $now = hrtime(true) / 1_000_000_000;
        $seconds = $frames === 0 ? 0.0 : ($realtime ? $now - $started : $frames / 60);
        if ($seconds > $duration) {
            throw new RuntimeException('Scene preview did not complete within its bounded duration.');
        }
        $requireSilence();
        // As in Game::run(), scenes own their focus/transition policy, not this driver.
        $fixture['advance']($seconds);
        $requireSilence();
        $game->sceneManager->render();
        if ($runtime !== null && $runtime->present($game->sceneManager->currentScene, $game->notificationManager)) { $changes++; }
        $frames++;
        $complete = $fixture['isComplete']();
        if (!is_bool($complete)) { throw new RuntimeException('Scene preview completion must be a boolean.'); }
        if ($complete) { break; }
        if ($realtime) { usleep(16667); }
    }
    $evidence = $fixture['verify']();
    $report = json_encode(['frames' => $frames, 'nativePresentationChanges' => $changes,
        'elapsedSeconds' => $seconds, 'realtime' => $realtime, 'reducedMotion' => $reduced, 'transitionMode' => $transitionMode,
        'subjects' => $fixture['subjects'] ?? null, 'fixture' => $evidence], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n";
    $report .= $noLaunch ? "CPU scene verification passed; no native window launched.\n"
        : "Silent scene preview closed cleanly. Presentation submissions are not visual acceptance or GPU timing.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    $result = 1;
} finally {
    try { $game?->sceneManager->stop(); }
    catch (Throwable $error) { fwrite(STDERR, 'Scene cleanup: ' . $error->getMessage() . "\n"); $result = 1; }
    try {
        $exit = $runtime?->shutdown();
        if ($exit !== null && $exit !== 0) { throw new RuntimeException('Scene preview renderer did not exit cleanly.'); }
    } catch (Throwable $error) { fwrite(STDERR, 'Renderer cleanup: ' . $error->getMessage() . "\n"); $result = 1; }
    try { if (is_callable($fixture['dispose'] ?? null)) { $fixture['dispose'](); } }
    catch (Throwable $error) { fwrite(STDERR, 'Fixture cleanup: ' . $error->getMessage() . "\n"); $result = 1; }
}
if ($result === 0) { echo $report; }
exit($result);
