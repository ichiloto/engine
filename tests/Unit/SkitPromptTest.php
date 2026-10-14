<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\SkitManager;
use Ichiloto\Engine\Field\SkitPrompt;
use Ichiloto\Engine\Field\SkitPromptPresentation;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class SkitPromptSceneProbe extends GameScene
{
  public bool $story = false;
  public function __construct() {}
  public function hasUnstableEventSession(): bool { return $this->story; }
}

final class SkitPromptScenesProbe extends SceneManager
{
  public bool $transition = false;
  public function __construct() {}
  public function hasSceneTransition(): bool { return $this->transition; }
}

final class SkitPromptUIProbe extends UIManager
{
  public array $active = [];
  public function __construct() {}
  public function getActivePresentations(): array { return $this->active; }
}

final class SkitPromptManagerProbe extends SkitManager
{
  public array $played = [];
  public bool $fail = false;
  public bool $hiddenDuringPlay = false;
  public bool $reentryPrevented = false;
  public function __construct(GameScene $scene, array $skits) { $this->gameScene = $scene; $this->skits = $skits; }
  protected function play(string $skitId, array $skit): void
  {
    $this->hiddenDuringPlay = $this->getAvailablePrompt() === null;
    $this->reentryPrevented = !$this->playNextAvailableSkit();
    if ($this->fail) { throw new RuntimeException('Interrupted'); }
    $this->played[] = $skitId;
    $this->gameScene->gameState->recordStoryEvent('skit_seen:' . $skitId);
  }
}

beforeEach(function () {
  $this->saved = [];
  foreach ([Console::class, InputManager::class, ActionHints::class, Time::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(80, 24);
  $this->root = sys_get_temp_dir() . '/skit-prompt-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  writeTestPng($this->root . '/Panel.png', 48, 48);
  $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  foreach (['gameState' => new GameState(), 'party' => new Party(), 'currentMapId' => 'test/field'] as $name => $value) {
    new ReflectionProperty(GameScene::class, $name)->setValue($this->scene, $value);
  }
  $this->manager = new SkitPromptManagerProbe($this->scene, [
    'first' => ['title' => 'First conversation', 'where' => 'test/field',
      'conditions' => [['type' => 'switch', 'name' => 'ready']], 'beats' => [['text' => 'One']]],
    'next' => ['title' => 'Next conversation', 'where' => 'test/field', 'beats' => [['text' => 'Two']]],
  ]);
});

afterEach(function () {
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  unlink($this->root . '/Panel.png');
  rmdir($this->root);
});

it('reads live gates and playback order without a notification timer or consuming seen state', function () {
  expect($this->manager->getAvailablePrompt()->id)->toBe('next');
  $this->scene->gameState->setSwitch('ready', true);
  Time::setElapsedTime(1000);
  expect($this->manager->getAvailablePrompt()->id)->toBe('first')
    ->and($this->scene->gameState->hasStoryEvent('skit_seen:first'))->toBeFalse();
  expect($this->manager->playNextAvailableSkit())->toBeTrue()
    ->and($this->manager->played)->toBe(['first'])
    ->and($this->manager->hiddenDuringPlay)->toBeTrue()->and($this->manager->reentryPrevented)->toBeTrue()
    ->and($this->manager->getAvailablePrompt()->id)->toBe('next');
  new ReflectionProperty(GameScene::class, 'currentMapId')->setValue($this->scene, 'other/map');
  expect($this->manager->getAvailablePrompt())->toBeNull();
  new ReflectionProperty(GameScene::class, 'currentMapId')->setValue($this->scene, 'test/field');
  $this->manager->playNextAvailableSkit();
  expect($this->manager->getAvailablePrompt())->toBeNull();
});

it('restores availability after interrupted playback without marking it seen', function () {
  $this->manager->fail = true;
  expect(fn() => $this->manager->playNextAvailableSkit())->toThrow(RuntimeException::class, 'Interrupted');
  expect($this->manager->isPlaying)->toBeFalse()->and($this->manager->getAvailablePrompt()->id)->toBe('next')
    ->and($this->scene->gameState->hasStoryEvent('skit_seen:next'))->toBeFalse();
});

it('uses themed bottom-right surfaces and an essential remappable control hint even with menu hints disabled', function (int $width, int $height, bool $alternate) {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'frames' => ['quiet' => ['asset' => 'Panel.png']], ...($alternate ? [
      'metrics' => ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16],
      'colors' => ['panel' => [240, 230, 210], 'text' => [20, 30, 40]]] : [])]);
  $prompt = new SkitPrompt('test', 'Available conversation');
  foreach ([KeyCode::T, KeyCode::K] as $key) {
    InputManager::setBindings(['skit' => ['keys' => [$key]]]);
    $canvas = SkitPromptPresentation::compose($prompt, ActionHints::resolve('skit', 'Watch skit'), $theme, $width, $height);
    $layers = array_column($canvas->textLayers, null, 'id');
    $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $canvas->textLayers)));
    expect($text)->toContain('Available conversation', $key->name, 'Watch skit')
      ->and($canvas->images)->not->toBeEmpty();
    $bounds = array_last($canvas->protectedAreas);
    expect($bounds->x)->toBeGreaterThanOrEqual(0)->and($bounds->y)->toBeGreaterThan($height / 2)
      ->and($bounds->x + $bounds->width)->toBe((float)($width - min(24, (int)floor(min($width, $height) / 20))));
    foreach ($canvas->textLayers as $layer) { $layer->bounds->assertWithin($width, $height); }
  }
  $controller = new ActionHint('skit', 'Watch skit', new ControlHint('playstation', 'triangle', 'Triangle'));
  $canvas = SkitPromptPresentation::compose($prompt, $controller, $theme, $width, $height);
  $text = implode('', array_merge(...array_map(static fn($layer) => array_column($layer->runs, 'text'), $canvas->textLayers)));
  expect($text)->toContain('Triangle', 'Watch skit');
})->with([[1350, 720], [800, 480], [400, 300]])->with([true, false]);

it('keeps a named Terminal prompt through repaint and removes only its own contribution', function () {
  $presentation = new SkitPromptPresentation();
  $hint = new ActionHint('skit', 'Watch skit', ControlHint::keyboard(KeyCode::K));
  $presentation->renderTerminal(new SkitPrompt('sample', 'Persistent conversation'), $hint);
  $before = Console::presentationSnapshot();
  $layer = array_column($before->textLayers, null, 'id')[SkitPromptPresentation::LAYER];
  expect(array_column($layer->runs, 'row'))->toContain(21, 22)
    ->and(implode('', array_column($layer->runs, 'text')))->toContain('Persistent conversation', 'K: Watch skit');
  Time::setElapsedTime(10000);
  Console::recomposeFrame(fn() => Console::write('Field is retained', 1, 1));
  expect(implode('', Console::getBuffer()))->toContain('Persistent conversation', 'Field is retained');
  $presentation->renderTerminal(null, $hint);
  expect(implode('', Console::getBuffer()))->not->toContain('Persistent conversation')
    ->and(implode('', Console::getBuffer()))->toContain('Field is retained');
});

it('hides the live field cue while control is unavailable and restores it without consuming the skit', function () {
  $scene = new SkitPromptSceneProbe();
  $scenes = new SkitPromptScenesProbe();
  $ui = new SkitPromptUIProbe();
  $field = new FieldState(new SceneStateContext($scene));
  $manager = new SkitPromptManagerProbe($scene, [
    'available' => ['title' => 'Available while exploring', 'beats' => [['text' => 'Synthetic beat']]],
  ]);
  foreach (['gameState' => new GameState(), 'party' => new Party(), 'currentMapId' => 'test/field',
    'sceneManager' => $scenes, 'uiManager' => $ui, 'state' => $field, 'skitManager' => $manager] as $name => $value) {
    new ReflectionProperty(GameScene::class, $name)->setValue($scene, $value);
  }
  $scenes->currentScene = $scene;
  $hasCue = static function (): bool {
    return in_array(SkitPromptPresentation::LAYER, array_column(Console::presentationSnapshot()->textLayers, 'id'), true);
  };
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeTrue();

  $scenes->transition = true;
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeFalse();
  $scenes->transition = false;
  $scene->story = true;
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeFalse();
  $scene->story = false;
  $ui->active = [new stdClass()];
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeFalse();
  $ui->active = [];
  $scenes->currentScene = null;
  $field->renderPresentationOverlay();
  expect($hasCue())->toBeFalse();
  $scenes->currentScene = $scene;
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, null);
  $field->renderPresentationOverlay();
  expect($hasCue())->toBeFalse();
  new ReflectionProperty(GameScene::class, 'state')->setValue($scene, $field);
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeTrue();
  $field->suspend();
  expect($hasCue())->toBeFalse();
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeTrue();
  new ReflectionProperty(GameScene::class, 'isStopping')->setValue($scene, true);
  $scene->renderPresentationOverlay();
  expect($hasCue())->toBeFalse()
    ->and($scene->gameState->hasStoryEvent('skit_seen:available'))->toBeFalse();
});
