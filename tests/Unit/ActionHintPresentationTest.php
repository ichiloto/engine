<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHintProvider;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ControlHint;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputBindings;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\UI\Presentation\ControlGlyphPresentation;
use Ichiloto\Engine\UI\Presentation\MenuActionHints;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

function actionHintPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xD0\xC0\xA0\xFF", $width), $height))) . $chunk('IEND', ''));
}

/** Every text run of a composition, in layer order. */
function actionHintText(PresentationCanvas $canvas): string
{
  return implode(' ', array_filter(array_map(static fn($layer) => implode(' ', array_column(array_filter($layer->runs,
    static fn($run) => $run->foreground !== null), 'text')), $canvas->textLayers), static fn(string $text) => $text !== ''));
}

/** Engine-drawn key shapes: background-only pixel layers. */
function actionHintKeyLayers(PresentationCanvas $canvas): array
{
  return array_values(array_filter($canvas->textLayers, static fn($layer) => $layer->grid->cellWidth === 1
    && $layer->runs !== [] && array_all($layer->runs, static fn($run) => $run->background !== null)));
}

beforeEach(function () {
  $this->saved = [];
  foreach ([InputManager::class, ActionHints::class, Debug::class] as $class) { $this->saved[$class] = new ReflectionClass($class)->getStaticProperties(); }
  ActionHints::useProvider(null);
  $this->root = sys_get_temp_dir() . '/ichiloto-action-hints-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  actionHintPng($this->root . '/glyph.png', 81, 37);
  actionHintPng($this->root . '/keycap.png', 24, 24);
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::SPACE, KeyCode::ENTER]],
    'cancel' => ['keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE]]]);
});

afterEach(function () {
  foreach ($this->saved as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  foreach (glob($this->root . '/*') as $path) { unlink($path); }
  rmdir($this->root);
});

it('draws each bound control as a keycap with its name and leaves bindings and input untouched', function () {
  $source = new FakeInputSource(KeyCode::ENTER);
  InputManager::setInputSource($source);
  $before = InputManager::getBindings();
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, new CanvasRectangle(10, 10, 620, 48));
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'hints');
  // Each name sits one cell inside its key; each label follows its key.
  expect(array_column($text->runs, 'text'))->toBe(['Enter', ' Confirm', 'Esc', ' Cancel'])
    ->and(array_map(fn($run) => $text->x + $run->column * 10, $text->runs))->toBe([20.0, 80.0, 200.0, 240.0])
    ->and($frame->images)->toBeEmpty()->and(InputManager::getBindings())->toBe($before)
    ->and(new InputBindings()->describeKeys('cancel'))->toBe('C, c, Esc')->and($source->keys)->toBe([KeyCode::ENTER]);
  // Both keys share one shape layer: rounded edges in the theme's edge colour around its key face.
  $keys = actionHintKeyLayers($frame);
  expect($keys)->toHaveCount(1)
    ->and(array_unique(array_map(fn($run) => $run->background, $keys[0]->runs), SORT_REGULAR))
    ->toContain($theme->colors['edge'], $theme->colors['selected'], $theme->colors['panel']);
  InputManager::handleInput();
  expect(Input::isButtonDown('confirm'))->toBeTrue()->and(Input::isButtonDown('cancel'))->toBeFalse();
});

it('lets a theme hide inline hints without changing actions or the full Controls lookup', function () {
  $bindings = InputManager::getBindings();
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'controls' => ['keyboard' => ['glyphs' => ['ENTER' => 'glyph.png']]]]);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 640, 24));
  expect(MenuActionHints::height($hints, $theme, 640))->toBe(0)
    ->and($frame->images)->toBeEmpty()->and($frame->textLayers)->toBeEmpty()
    ->and(InputManager::getBindings())->toBe($bindings)
    ->and(new InputBindings()->describeKeys('cancel'))->toBe('C, c, Esc');
  expect(fn() => new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => 'false']))
    ->toThrow(InvalidArgumentException::class);
});

it('keeps a hint whole when it wraps, keeps unbound actions plain and pairs previous and next keys', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  expect(MenuActionHints::height($hints, $theme, 340))->toBe(24)
    ->and(MenuActionHints::height($hints, $theme, 250))->toBe(48);
  $frame = MenuActionHints::compose(340, 48, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 250, 48));
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'hints');
  expect(array_map(fn($run) => [$text->y + $run->row * 24, $text->x + $run->column * 10, $run->text], $text->runs))
    ->toBe([[0.0, 10.0, 'Enter'], [0.0, 70.0, ' Confirm'], [24.0, 10.0, 'Esc'], [24.0, 50.0, ' Cancel']]);
  InputManager::setBindings([]);
  $plain = MenuActionHints::compose(340, 48, 'hints', [ActionHints::resolve('cancel', 'Cancel')], $theme,
    new CanvasRectangle(0, 0, 340, 48));
  expect(actionHintText($plain))->toBe('Unbound  Cancel')->and(actionHintKeyLayers($plain))->toBeEmpty()
    ->and($plain->textLayers[0]->runs[0]->foreground)->toBe($theme->colors['disabled']);
  InputManager::setBindings(['previous' => ['keys' => [KeyCode::Q]], 'next' => ['keys' => [KeyCode::E]]]);
  $pair = MenuActionHints::compose(340, 48, 'hints', [ActionHints::resolvePair('previous', 'next', 'Suits')], $theme,
    new CanvasRectangle(0, 0, 340, 48));
  expect(array_column(array_find($pair->textLayers, fn($layer) => $layer->id === 'hints')->runs, 'text'))
    ->toBe(['Q', ' / ', 'E', ' Suits'])
    ->and(TerminalControlText::formatHint(ActionHints::resolvePair('previous', 'next', 'Suits')))->toBe('[Q]/[E] Suits');
});

it('names keys once, centrally, with symbols for arrows and keycap words for the rest', function () {
  expect(array_map(fn(KeyCode $key) => ControlHint::keyboard($key)->label,
    [KeyCode::UP, KeyCode::DOWN, KeyCode::LEFT, KeyCode::RIGHT, KeyCode::ESCAPE, KeyCode::ENTER, KeyCode::SPACE,
      KeyCode::SHIFT_TAB, KeyCode::PAGE_UP, KeyCode::q]))
    ->toBe(['↑', '↓', '←', '→', 'Esc', 'Enter', 'Space', 'Shift+Tab', 'PgUp', 'q'])
    ->and(TerminalControlText::formatControl(ControlHint::keyboard(KeyCode::ENTER)))->toBe('[Enter]')
    ->and(TerminalControlText::formatControl(null))->toBe('Unbound')
    ->and(TerminalControlText::formatControls(new InputBindings()->getControlsForAction('cancel')))->toBe('[C] [c] [Esc]')
    ->and(TerminalControlText::formatActions([['confirm', 'Read'], ['cancel', 'Back']]))->toBe('[Enter] Read  [Esc] Back');
});

it('uses only actual owner-declared aliases and keeps external profile identity separate from labels', function () {
  InputManager::setBindings([]);
  expect(ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB)->control->label)->toBe('Shift+Tab')
    ->and(ActionHints::resolve('back', 'Back')->control)->toBeNull()
    ->and(ActionHints::resolveControl('back'))->toBeNull();
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return null; }
  });
  expect(ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB)->control)->toBeNull();
  $control = new ControlHint('pad-family', 'face-south', 'Localized primary control');
  expect($control->family)->toBe('pad-family')->and($control->label)->toBe('Localized primary control');
});

it('uses a theme keycap frame for every key, stretched to its name, without Engine-drawn shapes', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
    'controls' => ['keyboard' => ['keycap' => ['asset' => 'keycap.png', 'cuts' => [8, 8, 8, 8]], 'colors' => ['label' => 'accent']]]]);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, new CanvasRectangle(10, 10, 600, 24));
  $frames = array_values(array_filter($frame->images, fn($image) => $image->asset === 'keycap.png'));
  $right = fn(string $prefix) => max(array_map(fn($image) => $image->destination->x + $image->destination->width,
    array_filter($frames, fn($image) => str_starts_with($image->id, $prefix))));
  expect($frames)->not->toBeEmpty()->and(actionHintKeyLayers($frame))->toBeEmpty()
    ->and($right('hints-control-0-') - 10)->toEqualWithDelta(70, 0.001)
    ->and($right('hints-control-1-') - 10)->toEqualWithDelta(230, 0.001);
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'hints');
  expect(array_find($text->runs, fn($run) => $run->text === 'Enter')->foreground)->toBe($theme->colors['accent']);
});

it('swaps a whole controller icon set in one place and falls back to keycaps for missing glyphs', function (string $missing) {
  file_put_contents($this->root . '/corrupt.png', 'not a PNG');
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'controls' => [
    'gamepad.synthetic' => ['glyphs' => ['face-south' => 'glyph.png', 'face-east' => $missing]]]]);
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint
    {
      return match ($action) {
        'confirm' => new ControlHint('gamepad.synthetic', 'face-south', 'A'),
        'cancel' => new ControlHint('gamepad.synthetic', 'face-east', 'B'),
        default => null,
      };
    }
  });
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $frame = MenuActionHints::compose(640, 48, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 640, 48));
  expect(array_column($frame->images, 'asset'))->toBe(['glyph.png'])
    ->and(actionHintText($frame))->toBe(' Confirm B  Cancel')->and(actionHintKeyLayers($frame))->toHaveCount(1)
    ->and(file_get_contents($this->root . '/warning.log'))->toContain($missing)
    ->and(TerminalControlText::formatHints($hints))->toBe('[A] Confirm  [B] Cancel');
  // The same composition with the keyboard profile restored needs no theme change.
  ActionHints::useProvider(null);
  $keyboard = MenuActionHints::compose(640, 48, 'hints', [ActionHints::resolve('confirm', 'Confirm')], $theme,
    new CanvasRectangle(0, 0, 640, 48));
  expect($keyboard->images)->toBeEmpty()->and(actionHintText($keyboard))->toBe('Enter  Confirm');
})->with(['missing.png', 'corrupt.png']);

it('rejects control artwork in the general icon map and unknown keycap colours', function () {
  expect(fn() => new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
    'icons' => ['input.keyboard.ENTER' => 'glyph.png']]))->toThrow(InvalidArgumentException::class, 'controls sets')
    ->and(fn() => new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
      'controls' => ['keyboard' => ['colors' => ['face' => 'missing-role']]]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
      'controls' => ['keyboard' => ['shape' => 'round']]]))->toThrow(InvalidArgumentException::class);
});

it('measures complete wrapped hints using the same snapshot and rejects insufficient space', function (bool $glyph) {
  $data = ['schema' => 'ichiloto.menu/1'];
  if ($glyph) { $data['controls'] = ['keyboard' => ['glyphs' => ['ENTER' => 'glyph.png']]]; }
  $theme = new MenuPresentationCatalog($this->root, $data);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), new ActionHint('cancel', str_repeat('Long action ', 6), ControlHint::keyboard(KeyCode::ESCAPE))];
  $height = MenuActionHints::height($hints, $theme, 200);
  $frame = MenuActionHints::compose(400, 300, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 200, $height));
  $text = array_find($frame->textLayers, fn($layer) => $layer->id === 'hints');
  expect($height)->toBeGreaterThan(24)->and(actionHintText($frame))->toContain('Confirm', 'Esc', 'Long action')
    ->and($text->grid->rows * $text->grid->cellHeight)->toBeLessThanOrEqual($height);
  foreach ($text->runs as $run) {
    expect($run->column + mb_strlen($run->text))->toBeLessThanOrEqual(20);
  }
  expect(fn() => MenuActionHints::compose(400, 300, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 200, $height - 1)))
    ->toThrow(RuntimeException::class, 'finite viewport');
})->with([false, true]);

it('centres a lone control on its row in either theme and shows a rebound key at once', function (bool $alternate) {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
    'metrics' => $alternate ? ['cellWidth' => 8, 'cellHeight' => 18, 'rowHeight' => 32] : []]);
  $row = new CanvasRectangle(100, 200, 400, $theme->metrics->rowHeight);
  $controls = new ControlGlyphPresentation($theme, 'advance');
  $box = $controls->renderControl('advance', ActionHints::resolveControl('confirm'), $row, HorizontalAlignment::RIGHT);
  $canvas = $controls->compose(640, 480);
  expect($box->y + $box->height / 2)->toBe($row->y + $row->height / 2)
    ->and($box->x + $box->width)->toBe(500.0)
    ->and($canvas->textLayers[1]->y)->toBe($box->y)->and(actionHintText($canvas))->toBe('Enter');
  InputManager::setBindings(['confirm' => ['keys' => [KeyCode::F]]]);
  $rebound = new ControlGlyphPresentation($theme, 'advance');
  $rebound->renderControl('advance', ActionHints::resolveControl('confirm'), $row, HorizontalAlignment::RIGHT);
  expect(actionHintText($rebound->compose(640, 480)))->toBe('F');
})->with([false, true]);

it('splits wide keys across pixel tiles within grid limits', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $controls = new ControlGlyphPresentation($theme, 'wide');
  $label = str_repeat('Long control name ', 4);
  $controls->renderControl('wide', new ControlHint('keyboard', 'WIDE', $label), new CanvasRectangle(0, 0, 1300, 24), HorizontalAlignment::LEFT);
  $keys = actionHintKeyLayers($controls->compose(1350, 100));
  expect(count($keys))->toBeGreaterThan(1);
  foreach ($keys as $layer) { expect($layer->grid->columns)->toBeLessThanOrEqual(512); }
  expect(max(array_map(fn($layer) => $layer->x + $layer->grid->columns, $keys)))->toEqual((mb_strlen($label) + 2) * 10);
});

it('keeps a window help line and its semantic hints together until other help replaces them', function () {
  $window = new \Ichiloto\Engine\UI\Windows\Window('Title', '', new \Ichiloto\Engine\Core\Vector2(0, 0), 40, 4);
  $hints = [ActionHints::resolve('confirm', 'Select'), ActionHints::resolve('cancel', 'Back')];
  $window->setControlHints($hints);
  expect($window->getHelp())->toBe('[Enter] Select  [Esc] Back')->and($window->getControlHints())->toBe($hints);
  $window->setHelp('1-4 / 9');
  expect($window->getHelp())->toBe('1-4 / 9')->and($window->getControlHints())->toBe([]);
});
