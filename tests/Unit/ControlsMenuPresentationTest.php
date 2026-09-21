<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\ControlsMenuState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Presentation\ControlGlyphPresentation;
use Ichiloto\Engine\UI\Presentation\ControlsMenuContent;
use Ichiloto\Engine\UI\Presentation\ControlsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\InputConfig;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

class ControlsPresentationConfig extends InputConfig
{
  public array $written = [];
  public bool $fail = false;
  protected function load(): array { return $this->options['initial'] ?? []; }
  protected function getFilename(): string { return 'input.php'; }
  public function persist(): void
  {
    if ($this->fail) { throw new RuntimeException('Fixture write failure.'); }
    $this->written[] = $this->all();
  }
}

class ControlsPresentationOwner extends ControlsMenuState
{
  public function boot(): void
  {
    $this->bindings = new InputBindings();
    $this->reloadRows();
    $this->initializeUI();
    $this->refreshUI();
  }
  public function select(int $index): void { $this->activeIndex = $index; }
  public function terminalRows(): array { return $this->listPanel->getContent(); }
}

function controlsPresentationText(PresentationCanvas $canvas): string
{
  return implode("\n", array_map(fn($layer) => implode('', array_column(
    array_filter($layer->runs, fn($run) => $run->foreground !== null), 'text')), $canvas->textLayers));
}

function controlsPresentationKey(ControlsMenuState $state, KeyCode $key): void
{
  InputManager::setInputSource(new FakeInputSource($key));
  InputManager::handleInput();
  $state->execute();
}

beforeEach(function () {
  $this->saved = [];
  foreach ([InputManager::class, ActionHints::class, Console::class, ConfigStore::class, Debug::class] as $class) {
    $this->saved[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->root = sys_get_temp_dir() . '/ichiloto-controls-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  $chunk = fn($type, $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($this->root . '/glyph.png', "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 3, 5, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xAA\xCC\xEE\xFF", 3), 5))) . $chunk('IEND', ''));
  Debug::configure(['log_directory' => $this->root . '/logs']);
  ActionHints::useProvider(null);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 135, 'height' => 36]));
  Console::syncDimensions(135, 36);
  Console::setTerminalOutputEnabled(false);
  $this->bindings = ['confirm' => ['keys' => [KeyCode::SPACE, KeyCode::ENTER], 'description' => 'Confirm a selection.'],
    'cancel' => ['keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE], 'description' => 'Cancel the current selection.'],
    'back' => ['keys' => [KeyCode::ESCAPE], 'description' => 'Return.'],
    'up' => ['keys' => [KeyCode::UP], 'description' => 'Navigate up.'],
    'down' => ['keys' => [KeyCode::DOWN], 'description' => 'Navigate down.']];
  for ($i = 0; $i < 10; $i++) { $this->bindings['command_' . $i] = ['keys' => [], 'description' => 'Complete command description ' . $i]; }
  InputManager::setBindings($this->bindings);
  $this->effectiveBindings = InputManager::getBindings();
  new ReflectionProperty(InputManager::class, 'defaultConfig')->setValue(null, $this->effectiveBindings);
  $this->config = new ControlsPresentationConfig(['initial' => $this->bindings]);
  ConfigStore::put(InputConfig::class, $this->config);
  $this->owner = new ControlsPresentationOwner(new SceneStateContext(makeBareScene(GameScene::class)));
  $this->owner->boot();
});

afterEach(function () {
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
  rmdir($this->root);
});

it('keeps every binding reachable in both themed sizes without helper strips', function (bool $alternate, int $width, int $height) {
  $data = ['schema' => 'ichiloto.menu/1', 'showInputHints' => false];
  if ($alternate) {
    $data['colors'] = ['text' => [30, 20, 10], 'panel' => [240, 230, 220], 'selected' => [195, 165, 120]];
    $data['metrics'] = ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32, 'panelPadding' => 16, 'sectionGap' => 8];
  }
  $theme = new MenuPresentationCatalog($this->root, $data);
  $before = InputManager::getBindings();
  foreach ($this->owner->getPresentationContent()->rows as $index => $row) {
    $this->owner->select($index);
    $frame = ControlsMenuPresentation::compose($this->owner->getPresentationContent(), $theme, width: $width, height: $height);
    $text = controlsPresentationText($frame);
    expect($text)->toContain(ucfirst(str_replace('_', ' ', $row['action'])), $row['description'], 'Keyboard bindings: ' . $row['keys']);
    expect(count($frame->textLayers))->toBeLessThanOrEqual(64);
    foreach ($frame->textLayers as $layer) { $layer->bounds->assertWithin($width, $height); }
  }
  expect(InputManager::getBindings())->toBe($before)->and($this->config->written)->toBeEmpty()
    ->and(array_column($this->owner->getPresentationContent()->rows, 'action'))->not->toContain('back');
})->with([false, true])->with([[1350,720], [1100,720], [960,540]]);

it('uses provider identity live while preserving all keyboard aliases', function () {
  $this->owner->select(1);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'icons' => ['input.gamepad.xbox.face_east' => 'glyph.png']]);
  $keyboard = $this->owner->getPresentationContent();
  expect($keyboard->rows[1]['control']->label)->toBe('Escape')->and($keyboard->rows[1]['keys'])->toBe('C, c, ESCAPE');
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    { return $action === 'cancel' ? new ControlHint('gamepad.xbox', 'face_east', 'B') : null; }
  });
  $frame = ControlsMenuPresentation::compose($this->owner->getPresentationContent(), $theme);
  expect(array_column($frame->images, 'asset'))->toContain('glyph.png')
    ->and(controlsPresentationText($frame))->toContain('Keyboard bindings: C, c, ESCAPE', 'Unbound');
  ActionHints::useProvider(null);
  expect($this->owner->getPresentationContent()->rows[1]['control']->family)->toBe('keyboard')
    ->and($keyboard->rows[1]['control']->label)->toBe('Escape');
});

it('keeps readable controls without corresponding artwork and rejects insufficient glyph space', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false, 'icons' => ['unknown' => 'glyph.png']]);
  $control = new ControlHint('device.alternate', 'primary', 'Primary');
  $frame = ControlGlyphPresentation::compose(300, 100, 'control', $control, $theme, new CanvasRectangle(0, 0, 200, 40));
  expect($frame->images)->toBeEmpty()->and(controlsPresentationText($frame))->toBe(' Primary ')
    ->and($frame->textLayers[0]->runs[0]->background)->not->toBeNull();
  expect(fn() => ControlGlyphPresentation::compose(300, 100, 'control', $control, $theme, new CanvasRectangle(0, 0, 10, 40)))
    ->toThrow(RuntimeException::class);
});

it('consumes the opening and cancel edges without changing a binding', function () {
  controlsPresentationKey($this->owner, KeyCode::ENTER);
  expect($this->owner->getPresentationContent()->listening)->toBeTrue()->and(InputManager::getPressedKeyCode())->toBeNull();
  $this->owner->execute();
  expect($this->owner->getPresentationContent()->listening)->toBeTrue();
  controlsPresentationKey($this->owner, KeyCode::ESCAPE);
  expect($this->owner->getPresentationContent()->listening)->toBeFalse()
    ->and($this->owner->getPresentationContent()->status)->toBe('Rebinding cancelled.')
    ->and(InputManager::getPressedKeyCode())->toBeNull()->and(InputManager::getBindings())->toBe($this->effectiveBindings)
    ->and($this->config->written)->toBeEmpty();
});

it('does not also navigate when a semantic confirmation shares a directional key', function () {
  InputManager::setBinding('confirm', [KeyCode::DOWN]);
  controlsPresentationKey($this->owner, KeyCode::DOWN);
  expect($this->owner->getPresentationContent()->index)->toBe(0)
    ->and($this->owner->getPresentationContent()->listening)->toBeTrue()
    ->and(InputManager::getPressedKeyCode())->toBeNull();
});

it('applies and persists a binding once and refreshes the native and terminal lookup', function () {
  controlsPresentationKey($this->owner, KeyCode::ENTER);
  controlsPresentationKey($this->owner, KeyCode::K);
  $this->owner->execute();
  expect(InputManager::getBindings()['confirm']['keys'])->toBe([KeyCode::K])
    ->and($this->owner->getPresentationContent()->rows[0]['control']->label)->toBe('K')
    ->and($this->owner->getPresentationContent()->status)->toBe('Confirm is now bound to K.')
    ->and($this->config->written)->toHaveCount(1)->and(implode('', $this->owner->terminalRows()))->toContain('K');
});

it('reports a session-only rebind honestly when persistence fails', function () {
  $this->config->fail = true;
  controlsPresentationKey($this->owner, KeyCode::ENTER);
  controlsPresentationKey($this->owner, KeyCode::K);
  expect(InputManager::getBindings()['confirm']['keys'])->toBe([KeyCode::K])
    ->and($this->owner->getPresentationContent()->status)->toContain('for this session; the file could not be written.')
    ->and($this->owner->getPresentationContent()->listening)->toBeFalse();
});

it('restores defaults using the retained shortcut and consumes the edge', function (bool $fail) {
  $this->config->fail = $fail;
  InputManager::setBinding('confirm', [KeyCode::K]);
  InputManager::setBinding('info', [KeyCode::F2]);
  controlsPresentationKey($this->owner, KeyCode::R);
  expect(InputManager::getBindings())->toBe($this->effectiveBindings)->and(InputManager::getPressedKeyCode())->toBeNull()
    ->and($this->owner->getPresentationContent()->status)->toBe($fail
      ? 'Controls restored for this session; the file could not be written.' : 'Controls restored to the defaults.');
})->with([false, true]);

it('discovers fallback Info without inventing a confirmation binding for an empty project lookup', function () {
  InputManager::setBindings([]);
  $this->owner->boot();
  controlsPresentationKey($this->owner, KeyCode::ENTER);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $frame = ControlsMenuPresentation::compose($this->owner->getPresentationContent(), $theme);
  expect(controlsPresentationText($frame))->toContain('Info', 'Read the next Info page; wrap to the first.', 'Keyboard bindings: i, I')
    ->and($this->owner->getPresentationContent()->listening)->toBeFalse()
    ->and(array_column($this->owner->getPresentationContent()->rows, 'action'))->toBe(['info']);
});

it('scrolls the full owner list in terminal and native without losing the active action', function () {
  $bindings = $this->bindings;
  for ($i = 10; $i < 50; $i++) { $bindings['command_' . $i] = ['keys' => [], 'description' => 'Action ' . $i]; }
  InputManager::setBindings($bindings);
  $this->owner->boot();
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
  for ($i = 0; $i < 53; $i++) { controlsPresentationKey($this->owner, KeyCode::DOWN); }
  expect($this->owner->getPresentationContent()->index)->toBe(53)
    ->and(implode('', $this->owner->terminalRows()))->toContain('Command 49');
  $frame = ControlsMenuPresentation::compose($this->owner->getPresentationContent(), $theme);
  expect(controlsPresentationText($frame))->toContain('Command 49', '/ 55');
  controlsPresentationKey($this->owner, KeyCode::DOWN);
  expect($this->owner->getPresentationContent()->index)->toBe(54)
    ->and($this->owner->getPresentationContent()->rows[54]['action'])->toBe('info');
  controlsPresentationKey($this->owner, KeyCode::DOWN);
  expect($this->owner->getPresentationContent()->index)->toBe(0);
});

it('exposes the missing Info action in both Controls views and rebinds it through the current owner', function (bool $conflict) {
  if ($conflict) {
    $bindings = $this->bindings;
    $bindings['command_0']['keys'] = [KeyCode::i, KeyCode::I];
    InputManager::setBindings($bindings);
    $this->owner->boot();
  }
  $content = $this->owner->getPresentationContent();
  $index = array_search('info', array_column($content->rows, 'action'), true);
  expect($index)->toBeInt();
  $this->owner->select($index);
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false]);
  $frame = ControlsMenuPresentation::compose($this->owner->getPresentationContent(), $theme);
  expect(controlsPresentationText($frame))->toContain('Info', 'Read the next Info page; wrap to the first.',
    'Keyboard bindings: ' . ($conflict ? 'Unbound' : 'i, I'))
    ->and(implode('', $this->owner->terminalRows()))->toContain('Info', $conflict ? 'Unbound' : 'i, I')
    ->and($this->config->written)->toBeEmpty()->and($this->config->all())->not->toHaveKey('info');
  controlsPresentationKey($this->owner, KeyCode::ENTER);
  controlsPresentationKey($this->owner, KeyCode::F2);
  expect($this->owner->getPresentationContent()->rows[$index]['keys'])->toBe('F2')
    ->and(implode('', $this->owner->terminalRows()))->toContain('F2')
    ->and($this->config->written)->toHaveCount(1)
    ->and(InputManager::getBindings()['command_0']['keys'])->toBe($conflict ? [KeyCode::i, KeyCode::I] : []);
})->with([false, true]);

it('keeps the list cursor decorative and respects reduced motion', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'cursor' => 'glyph.png']);
  $snapshot = $this->owner->getPresentationContent();
  $a = ControlsMenuPresentation::compose($snapshot, $theme, 0);
  $b = ControlsMenuPresentation::compose($snapshot, $theme, 0.6);
  $cursor = fn($frame) => array_values(array_filter($frame->images, fn($image) => str_contains($image->id, '-cursor-')))[0];
  expect($cursor($a)->destination->x)->not->toBe($cursor($b)->destination->x)
    ->and($this->owner->getPresentationContent())->toEqual($snapshot)->and($this->config->written)->toBeEmpty();
  $settings = new SceneAudioConfigStub(['accessibility' => ['reducedMotion' => true]]);
  ConfigStore::put(ProjectConfig::class, $settings);
  $c = ControlsMenuPresentation::compose($snapshot, $theme, 0.6);
  expect($cursor($c)->destination)->toEqual($cursor($a)->destination);
});
