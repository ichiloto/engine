<?php

declare(strict_types=1);

use Assegai\Collections\Stack;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Menu\Commands\MenuCommandExecutionContext;
use Ichiloto\Engine\Core\Menu\Commands\ShowCreditsCommand;
use Ichiloto\Engine\Core\Menu\TitleMenu\TitleMenu;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\Title\TitleScene;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Presentation\CreditsContent;
use Ichiloto\Engine\UI\Presentation\CreditsPlayback;
use Ichiloto\Engine\UI\Presentation\CreditsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Symfony\Component\Console\Output\NullOutput;
use Tests\Support\Input\FakeInputSource;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class CreditsLifecycleModals extends ModalManager
{
  public ?CreditsContent $shown = null;
  public function __construct() { $this->modals = new Stack(ModalInterface::class); }
  public function showCredits(CreditsContent $content): void { $this->shown = $content; }
}

final class CreditsLifecycleGame extends Game
{
  public function __construct() { $this->modalManager = new CreditsLifecycleModals(); }
  public function __destruct() {}
}

final class CreditsLifecycleMenu extends TitleMenu
{
  public function __construct() { $this->activeIndex = 3; }
  public function render(?int $x = null, ?int $y = null): void {}
}

final class CreditsLifecycleScene extends TitleScene
{
  public function __construct(Game $game)
  {
    $this->sceneManager = new ReflectionClass(SceneManager::class)->newInstanceWithoutConstructor();
    $this->sceneManager->game = $game;
    $this->menu = new CreditsLifecycleMenu();
    $this->resetTitlePresentation();
  }
  public function syncContinueAvailability(): void {}
  public function renderHeader(): void {}
  public function tickCredits(): void { $this->updateCredits(); }
  public function advancePresentation(): void { $this->advanceTitlePresentation(); }
  public function getSelectedIndex(): int { return $this->menu->activeIndex; }
  public function getMenu(): TitleMenu { return $this->menu; }
}

final class CreditsLifecycleConfig extends ProjectConfig
{
  protected function load(): array { return $this->options; }
  public function persist(): void {}
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, ConfigStore::class, InputManager::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  ConfigStore::put(ProjectConfig::class, new CreditsLifecycleConfig(['name' => 'Example game',
    'audio' => ['master_volume' => 0, 'music' => false, 'sfx' => false]]));
  $this->root = sys_get_temp_dir() . '/credits-lifecycle-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/assets/Data', 0777, true);
  $this->cwd = getcwd();
  chdir($this->root);
  $this->runtime = null;
  $this->game = new CreditsLifecycleGame();
  $this->scene = new CreditsLifecycleScene($this->game);
  $this->sections = [['title' => 'Design', 'lines' => ['Andrew Masiye']]];
});

afterEach(function () {
  $this->runtime?->shutdown();
  chdir($this->cwd);
  if (file_exists($this->root . '/assets/Data/credits.php')) { unlink($this->root . '/assets/Data/credits.php'); }
  rmdir($this->root . '/assets/Data');
  rmdir($this->root . '/assets');
  rmdir($this->root);
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

function startCreditsTestRenderer(string $root, Game $game, ?FakeRendererTransport $transport = null,
  array $caps = CreditsMenuPresentation::CAPABILITIES): RendererRuntime
{
  $transport ??= new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(json_encode(['protocol' => 2, 'type' => 'ready', 'capabilities' => $caps]))];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['never-launched']), $root,
    requiredCapabilities: $caps), $transport);
  $runtime->start('Credits test', 80, 24);
  $game->useRendererRuntime($runtime);
  return $runtime;
}

it('routes terminal and reduced motion to the same centered alert content', function (bool $graphical) {
  if ($graphical) {
    $this->runtime = startCreditsTestRenderer($this->root, $this->game);
    ConfigStore::put(ProjectConfig::class, new CreditsLifecycleConfig(['accessibility' => ['reducedMotion' => true]]));
  }
  $this->scene->openCredits($this->sections);
  expect($this->game->modalManager->shown?->sections)->toBe($this->sections)
    ->and(new ReflectionProperty(TitleScene::class, 'creditsPlayback')->getValue($this->scene))->toBeNull()
    ->and($this->scene->getSelectedIndex())->toBe(3);
})->with([false, true]);

it('rolls without title artwork and returns with focus intact on semantic dismissal', function (string $action) {
  $this->runtime = startCreditsTestRenderer($this->root, $this->game);
  $this->scene->openCredits($this->sections);
  $clockProperty = new ReflectionProperty(TitleScene::class, 'creditsPlayback');
  $clock = $clockProperty->getValue($this->scene);
  expect($clock)->toBeInstanceOf(CreditsPlayback::class)->and($this->game->modalManager->shown)->toBeNull();
  $frame = $this->scene->getPresentationCanvas();
  $offset = $clock->offset;
  expect($frame)->not->toBeNull()->and($this->scene->getPresentationCanvas()->toArray())->toBe($frame->toArray())
    ->and($clock->offset)->toBe($offset);
  new ReflectionProperty(InputManager::class, 'config')->setValue(null, [$action => ['keys' => [KeyCode::Q]]]);
  InputManager::setInputSource(new FakeInputSource(KeyCode::Q));
  InputManager::handleInput();
  $this->scene->tickCredits();
  expect($clockProperty->getValue($this->scene))->toBeNull()->and($this->scene->getSelectedIndex())->toBe(3);
  $this->scene->openCredits($this->sections);
  expect($clockProperty->getValue($this->scene))->not->toBe($clock)
    ->and($clockProperty->getValue($this->scene)->offset)->toBeLessThan(1.0);
})->with(['confirm', 'cancel', 'back']);

it('returns automatically after the last line leaves the viewport', function () {
  $this->runtime = startCreditsTestRenderer($this->root, $this->game);
  $this->scene->openCredits($this->sections);
  $property = new ReflectionProperty(TitleScene::class, 'creditsPlayback');
  $clock = $property->getValue($this->scene);
  $now = hrtime(true) / 1e9;
  for ($tick = 1; !$clock->finished; $tick++) { $clock->advance($now + $tick / 10); }
  $this->scene->tickCredits();
  expect($property->getValue($this->scene))->toBeNull()->and($this->scene->getSelectedIndex())->toBe(3);
});

it('falls back when a menu-only renderer cannot report window activation', function () {
  $this->runtime = startCreditsTestRenderer($this->root, $this->game, caps: MenuPresentationCatalog::CAPABILITIES);
  $this->scene->openCredits($this->sections);
  expect($this->game->modalManager->shown?->sections)->toBe($this->sections)
    ->and(new ReflectionProperty(TitleScene::class, 'creditsPlayback')->getValue($this->scene))->toBeNull();
});

it('pauses standalone credits on native focus loss and resumes without catching up', function () {
  $transport = new FakeRendererTransport();
  $this->runtime = startCreditsTestRenderer($this->root, $this->game, $transport);
  $this->scene->openCredits($this->sections);
  $clock = new ReflectionProperty(TitleScene::class, 'creditsPlayback')->getValue($this->scene);
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":false}')];
  $this->runtime->pump();
  $this->scene->advancePresentation();
  $offset = $clock->offset;
  $this->scene->advancePresentation();
  expect($this->runtime->windowActive)->toBeFalse()->and($clock->offset)->toBe($offset);
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"window_activation","active":true}')];
  $this->runtime->pump();
  $this->scene->advancePresentation();
  expect($this->runtime->windowActive)->toBeTrue()->and($clock->offset)->toBe($offset)
    ->and($this->game->modalManager->shown)->toBeNull();
});

it('executes the real credits command and preserves fallback metadata for invalid authoring', function (string $source, array $expected) {
  file_put_contents($this->root . '/assets/Data/credits.php', $source);
  $menu = $this->scene->getMenu();
  $command = new ShowCreditsCommand($menu);
  expect($command->execute())->toBe($command::FAILURE);
  expect($command->execute(new MenuCommandExecutionContext([], new NullOutput(), $menu, $this->scene)))->toBe($command::SUCCESS)
    ->and($this->game->modalManager->shown?->sections)->toBe($expected);
})->with([
  ["<?php return [['title' => 'Design', 'lines' => ['Andrew Masiye']]];", [['title' => 'Design', 'lines' => ['Andrew Masiye']]]],
  ["<?php return [['title' => 'Invalid', 'lines' => [false]]];", [['title' => 'Example game', 'lines' => ['Made with the Ichiloto engine.']]]],
]);
