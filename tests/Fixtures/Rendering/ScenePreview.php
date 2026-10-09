<?php

declare(strict_types=1);

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Notifications\NotificationManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\AbstractScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

// Synthetic driver lifecycle only; this does not claim real-game encounter acceptance.
final class ScenePreviewGame extends Game
{
    public function __construct()
    {
        $this->name = 'Scene preview';
        $this->width = 12;
        $this->height = 4;
        $this->options = [];
        $this->sceneManager = new class($this) extends SceneManager {
            public function __construct(Game $game)
            {
                $this->game = $game;
                $this->scenes = new ItemList(SceneInterface::class);
            }
        };
        $this->notificationManager = new class extends NotificationManager {
            public function __construct() {}
            public function __destruct() {}
        };
    }
    public function __destruct() {}
}

final class ScenePreviewScene extends AbstractScene implements CanvasProviderInterface
{
    public bool $canvasMode = false;
    public int $renders = 0;
    public int $stops = 0;
    public function __construct(SceneManager $manager)
    {
        $this->sceneManager = $manager;
        $this->name = 'Synthetic';
        $this->camera = new Camera($this, Console::getWidth(), Console::getHeight());
    }
    public function start(): void { $this->started = true; }
    public function stop(): void { $this->stops++; $this->started = false; }
    public function update(): void {}
    public function render(): void { $this->renders++; Console::write('FIELD BASE', 0, 0); }
    public function getPresentationCanvas(): ?PresentationCanvas
    { return $this->canvasMode ? new PresentationCanvas(120, 80) : null; }
}

return static function (string $root, bool $reduced, ?array $subjects = null, string $transitionMode = 'project'): array {
    $selected = $subjects ?? ['field'];
    $allowed = ['field', 'realtime', 'never-complete', 'unmuted', 'unmute-start', 'unmute-advance',
        'invalid-completion', 'cleanup-error', 'verify-error', 'ignore-transitions'];
    foreach ($selected as $id) {
        if (!in_array($id, $allowed, true)) { throw new InvalidArgumentException('Unknown scene preview subject: ' . $id); }
    }
    $settings = new PlaySettings(['audio' => ['music' => !in_array('unmuted', $selected, true) ? false : true,
        'sfx' => false, 'master_volume' => 0]]);
    ConfigStore::put(ProjectConfig::class, $settings);
    ConfigStore::put(PlaySettings::class, $settings);
    Console::setTerminalOutputEnabled(false);
    Console::enterAlternateScreen();
    Console::syncDimensions(12, 4);
    $game = new ScenePreviewGame();
    $scene = new ScenePreviewScene($game->sceneManager);
    $game->sceneManager->addScenes($scene);
    $calls = [];
    $last = -1.0;
    $complete = false;
    return [
        'game' => $game, 'grid' => new RendererGridConfig(12, 4, 10, 20), 'subjects' => $selected,
        'transitionMode' => in_array('ignore-transitions', $selected, true) ? 'project' : $transitionMode,
        'realtime' => in_array('realtime', $selected, true),
        'requiredCapabilities' => ['graphical_canvas'],
        'start' => static function () use ($game, $scene, &$calls, $settings, $selected): void {
            if ($game->getRendererRuntime() !== null && $game->getRendererRuntime()->grid === null) {
                throw new RuntimeException('Scene started before its attached runtime.');
            }
            $calls[] = 'start';
            $game->sceneManager->currentScene = $scene;
            $scene->start();
            if (in_array('unmute-start', $selected, true)) { $settings->set('audio.sfx', true); }
        },
        'advance' => static function (float $seconds) use ($scene, &$calls, &$last, &$complete, $settings, $selected): void {
            if ($calls === [] || $seconds < $last) { throw new RuntimeException('Scene clock/start order is invalid.'); }
            $last = $seconds;
            $calls[] = 'advance';
            $scene->canvasMode = $seconds >= 0.05 && $seconds < 0.10;
            $complete = $seconds >= 0.15;
            if (in_array('unmute-advance', $selected, true)) { $settings->set('audio.music', true); }
        },
        'isComplete' => static function () use ($selected, &$complete): mixed {
            return in_array('invalid-completion', $selected, true) ? 1
                : (!in_array('never-complete', $selected, true) && $complete);
        },
        'verify' => static function () use ($game, $scene, &$calls, &$last, $reduced, $selected, $transitionMode): array {
            if (in_array('verify-error', $selected, true)) { throw new RuntimeException('Deliberate verification failure.'); }
            return ['startedAfterRuntime' => $game->getRendererRuntime()?->grid !== null,
                'renders' => $scene->renders, 'advances' => count($calls) - 1, 'lastSeconds' => $last,
                'reducedMotion' => $reduced, 'subjects' => $selected, 'transitionMode' => $transitionMode,
                'returnedFromCanvas' => !$scene->canvasMode];
        },
        'dispose' => static function () use ($root, $scene, &$calls, $selected): void {
            file_put_contents($root . '/cleanup.json', json_encode(['calls' => $calls, 'stops' => $scene->stops], JSON_THROW_ON_ERROR));
            if (in_array('cleanup-error', $selected, true)) { throw new RuntimeException('Deliberate cleanup failure.'); }
        },
    ];
};
