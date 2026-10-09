<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\Commands\ContinueGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\QuitGameCommand;
use Ichiloto\Engine\Core\Menu\Commands\ToTitleMenuCommand;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasProviderInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\GameOver\GameOverScene;
use Ichiloto\Engine\Scenes\GameOver\Menus\GameOverMenu;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\GameOverMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuRow;
use Ichiloto\Engine\UI\Presentation\MenuRowKind;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class GameOverPresentationConfig extends ProjectConfig
{
  protected function load(): array { return $this->options; }
  public function persist(): void {}
}

final class GameOverPresentationGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
  public function quit(): void { $this->quits++; }
  public int $quits = 0;
}

final class GameOverPresentationScene extends GameOverScene
{
  protected function loadHeaderContent(): string { return 'TERMINAL GAME OVER'; }
  public function getCommandMenu(): GameOverMenu { return $this->menu; }
}

function getGameOverPresentationText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(static fn($layer) => implode('', array_map(static fn($run) =>
    $run->foreground === null ? '' : $run->text, $layer->runs)), $canvas->textLayers));
}

function startGameOverTestRenderer(object $fixture, array $caps = MenuPresentationCatalog::CAPABILITIES): void
{
  $fixture->transport = new FakeRendererTransport();
  $fixture->transport->batches = [[RendererEvent::fromJson(json_encode(
    ['type' => 'ready', 'protocol' => 2, 'capabilities' => $caps], JSON_THROW_ON_ERROR))]];
  $fixture->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']),
    $fixture->root, requiredCapabilities: $caps), $fixture->transport);
  $fixture->runtime->start('Synthetic Game Over', 135, 36);
  $fixture->game->useRendererRuntime($fixture->runtime);
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, ConfigStore::class, InputManager::class, Debug::class, AudioManager::class,
    SaveManager::class, GameLoader::class, EventManager::class, UIManager::class, ModalManager::class, ActionHints::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-game-over-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  Console::setTerminalOutputEnabled(false);
  Console::enterAlternateScreen();
  Console::syncDimensions(135, 36);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  ConfigStore::put(ProjectConfig::class, new GameOverPresentationConfig([
    'audio' => ['master_volume' => 0, 'music' => false, 'sfx' => false],
    'vocab' => ['game' => ['continue' => 'Load Save', 'to_title' => 'Return To Title', 'shutdown' => 'Exit Test', 'game_over' => 'Defeat']],
  ]));
  $this->game = new GameOverPresentationGame();
  $this->audio = new class extends AudioManager {
    public function __construct() {}
    public function playSystemSound(SystemSound $sound): void {}
  };
  new ReflectionProperty($this->game, 'audioManager')->setValue($this->game, $this->audio);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->audio);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  $this->saves = new class extends SaveManager {
    public bool $available = false;
    public function __construct() {}
    public function hasSaveFiles(bool $includeQuickSaves = false): bool { return $this->available; }
    public function getLatestLoadableSaveFile(bool $includeQuickSaves = false): ?string { return null; }
  };
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $this->saves);
  new ReflectionProperty(GameLoader::class, 'instance')->setValue(null,
    new ReflectionClass(GameLoader::class)->newInstanceWithoutConstructor());
  $this->manager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($this->manager, 'game')->setValue($this->manager, $this->game);
  new ReflectionProperty($this->manager, 'saveManager')->setValue($this->manager, $this->saves);
  new ReflectionProperty($this->game, 'sceneManager')->setValue($this->game, $this->manager);
  $this->scene = new ReflectionClass(GameOverPresentationScene::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($this->scene, 'sceneManager')->setValue($this->scene, $this->manager);
  new ReflectionProperty($this->scene, 'uiManager')->setValue($this->scene, UIManager::getInstance($this->game));
  $camera = new Camera($this->scene, 135, 36);
  new ReflectionProperty($this->scene, 'camera')->setValue($this->scene, $camera);
  new ReflectionProperty($this->scene, 'eventManager')->setValue($this->scene, EventManager::getInstance($this->game));
  $this->manager->currentScene = $this->scene;
  $this->runtime = null;
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::ENTER]],
    'up' => ['keys' => [KeyCode::UP]], 'down' => ['keys' => [KeyCode::DOWN]]]);
  ob_start();
  $this->scene->start();
});

afterEach(function () {
  if ($this->scene->isStarted()) { $this->scene->stop(); }
  $this->runtime?->shutdown();
  ob_end_clean();
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('retains the original terminal screen and real commands without a graphical session', function () {
  $menu = $this->scene->getCommandMenu();
  expect($this->scene)->toBeInstanceOf(CanvasProviderInterface::class)
    ->and($this->scene->getPresentationCanvas())->toBeNull()
    ->and($menu->getItemByIndex(0))->toBeInstanceOf(ContinueGameCommand::class)
    ->and($menu->getItemByIndex(1))->toBeInstanceOf(ToTitleMenuCommand::class)
    ->and($menu->getItemByIndex(2))->toBeInstanceOf(QuitGameCommand::class);
  $text = implode("\n", Console::snapshot()->rows);
  expect($text)->toContain('TERMINAL GAME OVER', 'Load Save', 'Return To', 'Exit Test');
});

it('uses a graphical default even without project artwork and does not mutate terminal state', function () {
  startGameOverTestRenderer($this);
  $before = Console::snapshot();
  $canvas = $this->scene->getPresentationCanvas();
  expect($canvas)->toBeInstanceOf(PresentationCanvas::class)
    ->and(getGameOverPresentationText($canvas))->toContain('Defeat', 'Load Save', 'Return To Title', 'Exit Test')
    ->not->toContain('TERMINAL GAME OVER')
    ->and(Console::snapshot())->toEqual($before);
  $this->runtime->present($this->scene);
  $frame = RetainedFrameState::getLatestFrame($this->transport->sent);
  expect($frame['canvas']['width'])->toBe(PresentationCanvas::DEFAULT_WIDTH)
    ->and($frame['textLayers'])->toBe([]);
});

it('follows real focus and save availability through suspend resume and resize', function () {
  startGameOverTestRenderer($this);
  $menu = $this->scene->getCommandMenu();
  expect($menu->getItemByIndex(0)->isDisabled())->toBeTrue();
  $menu->setActiveItemByIndex(2);
  $before = $this->scene->getPresentationCanvas();
  $this->scene->suspend();
  $this->scene->resume();
  $this->scene->onScreenResize(100, 30);
  expect($menu->activeIndex)->toBe(2)->and($this->scene->getPresentationCanvas())->toEqual($before);
  $this->saves->available = true;
  $this->scene->resume();
  expect($menu->getItemByIndex(0)->isDisabled())->toBeFalse()
    ->and($this->scene->getPresentationCanvas())->not->toEqual($before);
  InputManager::setInputSource(new FakeInputSource(KeyCode::ENTER));
  InputManager::handleInput();
  $menu->update();
  expect($this->game->quits)->toBe(1);
  $this->scene->stop();
  expect($this->scene->getPresentationCanvas())->toBeNull();
});

it('retains terminal presentation when the selected renderer lacks canvas support', function () {
  startGameOverTestRenderer($this, []);
  expect($this->scene->getPresentationCanvas())->toBeNull();
});

it('composes centered steady buttons with two replaceable themes and disabled labels', function () {
  foreach ([[10, 24, [200, 170, 90]], [12, 28, [90, 180, 160]]] as [$cw, $ch, $accent]) {
    $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
      'colors' => ['accent' => $accent], 'metrics' => ['cellWidth' => $cw, 'cellHeight' => $ch]]);
    $rows = [new MenuRow('load', 'Load Save', kind: MenuRowKind::BUTTON, disabled: true),
      new MenuRow('title', 'Return To Title', kind: MenuRowKind::BUTTON, focused: true, selected: true),
      new MenuRow('exit', 'Exit Test', kind: MenuRowKind::BUTTON)];
    $canvas = GameOverMenuPresentation::compose($theme, 'Game Over', $rows);
    $title = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'game-over-heading');
    expect($title->x + $title->bounds->width / 2)->toEqual($canvas->width / 2)
      ->and($title->runs[0]->foreground)->toEqual($theme->colors['accent']);
    foreach (['load', 'title', 'exit'] as $id) {
      $layer = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'game-over-' . $id . '-text');
      expect($layer)->not->toBeNull();
      $run = $layer->runs[0];
      $center = $layer->x + ($run->column + mb_strlen($run->text) / 2) * $cw;
      expect(abs($center - $canvas->width / 2))->toBeLessThanOrEqual($cw / 2);
      if ($id === 'load') { expect($run->foreground)->toEqual($theme->colors['disabled']); }
    }
    expect(getGameOverPresentationText($canvas))->not->toContain('>', '»');
  }
});

it('uses current frame artwork and preserves the screen when optional artwork is replaced or removed', function () {
  writeTestPng($this->root . '/frame.png', 48, 48);
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ' . var_export([
    'schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'frames' => ['panel' => ['asset' => 'frame.png', 'cuts' => [8, 8, 8, 8]]],
  ], true) . ';');
  startGameOverTestRenderer($this);
  $first = $this->scene->getPresentationCanvas();
  expect(array_column($first->images, 'asset'))->toContain('frame.png');
  writeTestPng($this->root . '/frame.png', 63, 57);
  clearstatcache(true, $this->root . '/frame.png');
  $replacement = $this->scene->getPresentationCanvas();
  expect(getGameOverPresentationText($replacement))->toBe(getGameOverPresentationText($first));
  unlink($this->root . '/frame.png');
  expect($this->scene->getPresentationCanvas())->toBeInstanceOf(PresentationCanvas::class);
});

it('diagnoses invalid theme data without hiding or replacing its terminal commands', function () {
  mkdir($this->root . '/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/' . MenuPresentationCatalog::FILE, '<?php return ["schema" => "invalid"];');
  startGameOverTestRenderer($this);
  expect($this->scene->getPresentationCanvas())->toBeNull();
  $log = file_get_contents($this->root . '/error.log');
  expect($log)->toContain('Game Over presentation degraded to terminal');
  expect($this->scene->getPresentationCanvas())->toBeNull()
    ->and(file_get_contents($this->root . '/error.log'))->toBe($log)
    ->and($this->scene->getCommandMenu()->getItems()->count())->toBe(3);
});

it('preserves the native screen beneath alerts and restores its original focus after dismissal', function () {
  startGameOverTestRenderer($this);
  $this->scene->getCommandMenu()->setActiveItemByIndex(1);
  $before = $this->scene->getPresentationCanvas();
  $manager = ModalManager::getInstance($this->game);
  new ReflectionProperty($this->game, 'modalManager')->setValue($this->game, $manager);
  $modal = new AlertModal($this->game, 'Save unavailable', '');
  $manager->open($modal);
  $canvas = $this->scene->getPresentationCanvas();
  expect($canvas)->toBeInstanceOf(PresentationCanvas::class)
    ->and(getGameOverPresentationText($canvas))->toContain('Defeat', 'Save unavailable', 'Return To Title');
  $modal->hide();
  expect($this->scene->getPresentationCanvas())->toEqual($before)
    ->and($this->scene->getCommandMenu()->activeIndex)->toBe(1);
});
