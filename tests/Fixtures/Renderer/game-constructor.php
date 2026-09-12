<?php

namespace Ichiloto\Engine\IO\Console {
  // Isolate only the physical terminal probe; Game construction stays real.
  function shell_exec(string $command): ?string
  {
    return str_starts_with($command, 'stty size')
      ? (getenv('ICHILOTO_TEST_TERMINAL_SIZE') ?: '31 117') . "\n" : \shell_exec($command);
  }
}

namespace {
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Launch\RendererDescriptor;
use Ichiloto\Engine\Rendering\Launch\RendererRegistry;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\SceneManager;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$scenario = json_decode($argv[2] ?? '{}', true, flags: JSON_THROW_ON_ERROR);
$runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig([
  PHP_BINARY, __DIR__ . '/renderer-stub.php', 'normal', getcwd() . '/wire.ndjson',
]), getcwd() . '/assets'));
$registry = new RendererRegistry(descriptors: [
  new RendererDescriptor('terminal', fn(string $root) => null),
  new RendererDescriptor('gpui', fn(string $root) => $runtime),
]);
class ConstructorGeometryGame extends Game
{
  public function openInput(): void { $this->startInputSession(); }
}
$game = new ConstructorGeometryGame('Constructor geometry', ...$arguments, rendererRegistry: $registry);
foreach ($scenario['configure'] ?? [] as $options) { $game->configure($options); }
if ($scenario['attach'] ?? false) { $game->useRendererRuntime($runtime); }
if ($scenario['start'] ?? false) { $game->openInput(); }
file_put_contents('dimensions.json', json_encode([
  'width' => Console::getWidth(), 'height' => Console::getHeight(),
], JSON_THROW_ON_ERROR));
$cameras = [];
foreach (new \ReflectionProperty(SceneManager::class, 'scenes')->getValue($game->sceneManager) as $scene) {
  $cameras[] = ['width' => $scene->camera->screen->getWidth(), 'height' => $scene->camera->screen->getHeight()];
}
file_put_contents('observations.json', json_encode([
  'cameras' => $cameras, 'settings' => ['width' => get_screen_width(), 'height' => get_screen_height()],
  'options' => $game->options['screen'],
], JSON_THROW_ON_ERROR));
$game->quit();
}
