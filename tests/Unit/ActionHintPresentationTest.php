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
use Ichiloto\Engine\UI\Presentation\MenuActionHints;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeInputSource;

require_once __DIR__ . '/../Support/Input/FakeInputSource.php';

function actionHintPng(string $path, int $width, int $height): void
{
  $chunk = static fn(string $type, string $bytes) => pack('N', strlen($bytes)) . $type . $bytes . pack('N', crc32($type . $bytes));
  file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 6, 0, 0, 0))
    . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xD0\xC0\xA0\xFF", $width), $height))) . $chunk('IEND', ''));
}

beforeEach(function () {
  $this->saved = [];
  foreach ([InputManager::class, ActionHints::class, Debug::class] as $class) { $this->saved[$class] = new ReflectionClass($class)->getStaticProperties(); }
  ActionHints::useProvider(null);
  $this->root = sys_get_temp_dir() . '/ichiloto-action-hints-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  Debug::configure(['log_directory' => $this->root]);
  actionHintPng($this->root . '/glyph.png', 81, 37);
  actionHintPng($this->root . '/unknown.png', 31, 73);
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

it('resolves compact readable keycaps and leaves action bindings and Controls aliases intact', function () {
  $source = new FakeInputSource(KeyCode::ENTER);
  InputManager::setInputSource($source);
  $before = InputManager::getBindings();
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, new CanvasRectangle(10, 10, 620, 48));
  expect(implode('', array_column($frame->textLayers[0]->runs, 'text')))->toBe(' Enter : Confirm   Escape : Cancel')
    ->and(array_column($hints, 'action'))->toBe(['confirm', 'cancel'])
    ->and(array_column(array_filter($frame->textLayers[0]->runs, fn($run) => $run->background !== null), 'text'))->toBe([' Enter ', ' Escape '])
    ->and($frame->images)->toBeEmpty()->and(InputManager::getBindings())->toBe($before)
    ->and(new InputBindings()->describeKeys('cancel'))->toBe('C, c, ESCAPE')->and($source->keys)->toBe([KeyCode::ENTER]);
  InputManager::handleInput();
  expect(Input::isButtonDown('confirm'))->toBeTrue()->and(Input::isButtonDown('cancel'))->toBeFalse();
});

it('lets a theme hide inline hints without changing actions or the full Controls lookup', function () {
  $bindings = InputManager::getBindings();
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => false,
    'icons' => ['input.keyboard.ENTER' => 'glyph.png']]);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 640, 24));
  expect(MenuActionHints::height($hints, $theme, 640))->toBe(0)
    ->and($frame->images)->toBeEmpty()->and($frame->textLayers)->toBeEmpty()
    ->and(InputManager::getBindings())->toBe($bindings)
    ->and(new InputBindings()->describeKeys('cancel'))->toBe('C, c, ESCAPE');
  expect(fn() => new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'showInputHints' => 'false']))
    ->toThrow(InvalidArgumentException::class);
});

it('measures padded controls as separate keycaps and keeps unbound hints plain', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1']);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  expect(MenuActionHints::height($hints, $theme, 340))->toBe(24)
    ->and(MenuActionHints::height($hints, $theme, 330))->toBe(48);
  $frame = MenuActionHints::compose(340, 48, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 330, 48));
  $caps = array_values(array_filter($frame->textLayers[0]->runs, fn($run) => $run->background !== null));
  expect(array_column($caps, 'row'))->toBe([0, 1])->and(array_column($caps, 'column'))->toBe([0, 0]);
  foreach ($caps as $cap) {
    expect($cap->text)->toStartWith(' ')->toEndWith(' ')
      ->and(mb_strlen($cap->text) - mb_strlen(trim($cap->text)))->toBe(2)
      ->and($cap->column + mb_strlen($cap->text))->toBeLessThanOrEqual(33);
  }
  InputManager::setBindings([]);
  $plain = MenuActionHints::compose(340, 48, 'hints', [ActionHints::resolve('cancel', 'Cancel')], $theme,
    new CanvasRectangle(0, 0, 340, 48));
  expect(implode('', array_column($plain->textLayers[0]->runs, 'text')))->toBe('Unbound: Cancel')
    ->and(array_filter($plain->textLayers[0]->runs, fn($run) => $run->background !== null))->toBeEmpty();
});

it('uses only actual owner-declared aliases and keeps external profile identity separate from labels', function () {
  InputManager::setBindings([]);
  expect(ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB)->control->label)->toBe('Shift+Tab')
    ->and(ActionHints::resolve('back', 'Back')->control)->toBeNull();
  ActionHints::useProvider(new class implements ActionHintProvider {
    public function controlForAction(string $action): ?ControlHint { return null; }
  });
  expect(ActionHints::resolve('character_previous', 'Prev', KeyCode::SHIFT_TAB)->control)->toBeNull();
  $control = new ControlHint('pad-family', 'face-south', 'Localized primary control');
  expect($control->iconRole())->toBe('input.pad-family.face-south')->and($control->label)->toBe('Localized primary control');
});

it('selects exact optional glyphs and falls back to readable controls for absent roles', function () {
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
    'icons' => ['unknown' => 'unknown.png', 'input.keyboard.ENTER' => 'glyph.png']]);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $box = new CanvasRectangle(10, 10, 600, 24);
  $frame = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, $box);
  expect(array_column($frame->images, 'asset'))->toBe(['glyph.png'])
    ->and(implode('', array_column($frame->textLayers[0]->runs, 'text')))->toBe(': Confirm   Escape : Cancel')
    ->and(MenuActionHints::height($hints, $theme, $box->width))->toBe(24);
  $before = $frame->images[0]->destination;
  actionHintPng($this->root . '/glyph.png', 19, 95);
  clearstatcache();
  $after = MenuActionHints::compose(640, 100, 'hints', $hints, $theme, $box)->images[0];
  expect($after->destination->width / $after->destination->height)->toEqualWithDelta(19 / 95, 0.000001)
    ->and($after->destination->width)->toBeLessThan($before->width)->and($after->destination->height)->toBeLessThanOrEqual(24)
    ->and($after->clipRect)->toBe($box);
});

it('accepts glyph-only registries and leaves unused unavailable glyphs uninspected', function () {
  $data = ['schema' => 'ichiloto.menu/1', 'icons' => ['input.keyboard.ENTER' => 'glyph.png']];
  $theme = new MenuPresentationCatalog($this->root, $data);
  expect($theme->icons->asset('input.keyboard.ENTER'))->toBe('glyph.png')
    ->and($theme->icons->asset('weapon.staff'))->toBeNull()->and($theme->icons->asset(null))->toBeNull();
  $cursorOnly = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'cursor' => 'glyph.png']);
  expect($cursorOnly->icons->cursor)->toBe('glyph.png')->and($cursorOnly->icons->asset('slot.head'))->toBeNull();
  $frame = MenuActionHints::compose(640, 48, 'hints',
    [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')], $theme,
    new CanvasRectangle(0, 0, 640, 48));
  expect(array_column($frame->images, 'asset'))->toBe(['glyph.png'])
    ->and(implode('', array_column($frame->textLayers[0]->runs, 'text')))->toBe(': Confirm   Escape : Cancel');
  file_put_contents($this->root . '/corrupt.png', 'not a PNG');
  foreach (['missing.png', 'corrupt.png'] as $asset) {
    $data['icons']['input.unused.control'] = $asset;
    $partial = new MenuPresentationCatalog($this->root, $data);
    $after = MenuActionHints::compose(640, 48, 'hints',
      [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')], $partial,
      new CanvasRectangle(0, 0, 640, 48));
    expect($after)->toEqual($frame)
      ->and(file_exists($this->root . '/warning.log'))->toBeFalse();
  }
});

it('measures complete wrapped hints using the same snapshot and rejects insufficient space', function (bool $glyph) {
  $data = ['schema' => 'ichiloto.menu/1'];
  if ($glyph) { $data['icons'] = ['unknown' => 'unknown.png', 'input.keyboard.ENTER' => 'glyph.png']; }
  $theme = new MenuPresentationCatalog($this->root, $data);
  $hints = [ActionHints::resolve('confirm', 'Confirm'), new ActionHint('cancel', str_repeat('Long action ', 6), ControlHint::keyboard(KeyCode::ESCAPE))];
  $height = MenuActionHints::height($hints, $theme, 200);
  $frame = MenuActionHints::compose(400, 300, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 200, $height));
  $text = implode('', array_column($frame->textLayers[0]->runs, 'text'));
  expect($height)->toBeGreaterThan(24)->and($text)->toContain('Confirm', ' Escape : ' . str_repeat('Long action ', 6))
    ->and($frame->textLayers[0]->bounds->height)->toBe((float)$height);
  foreach ($frame->textLayers[0]->runs as $run) {
    expect($run->column + mb_strlen($run->text))->toBeLessThanOrEqual(20);
  }
  expect(fn() => MenuActionHints::compose(400, 300, 'hints', $hints, $theme, new CanvasRectangle(0, 0, 200, $height - 1)))
    ->toThrow(RuntimeException::class, 'finite viewport');
})->with([false, true]);

it('refreshes provider families on redraw without claiming or generating device events', function () {
  $provider = new class implements ActionHintProvider {
    public string $family = 'profile-one';
    public function controlForAction(string $action): ?ControlHint { return new ControlHint($this->family, 'face-south', $this->family); }
  };
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1', 'icons' => ['unknown' => 'unknown.png',
    'input.profile-one.face-south' => 'glyph.png']]);
  $bindings = InputManager::getBindings();
  $source = new FakeInputSource(KeyCode::ENTER);
  InputManager::setInputSource($source);
  ActionHints::useProvider($provider);
  $first = ActionHints::resolve('confirm', 'Confirm');
  $provider->family = 'profile-two';
  $second = ActionHints::resolve('confirm', 'Confirm');
  $box = new CanvasRectangle(0, 0, 400, 24);
  $one = MenuActionHints::compose(400, 100, 'hints', [$first], $theme, $box);
  $two = MenuActionHints::compose(400, 100, 'hints', [$second], $theme, $box);
  expect($first->control->family)->toBe('profile-one')->and($second->control->family)->toBe('profile-two')
    ->and($first->action)->toBe($second->action)->and($one->images)->toHaveCount(1)->and($two->images)->toBeEmpty()
    ->and(implode('', array_column($two->textLayers[0]->runs, 'text')))->toBe(' profile-two : Confirm')
    ->and($source->keys)->toBe([KeyCode::ENTER])->and(InputManager::getBindings())->toBe($bindings);
  ActionHints::useProvider(null);
  expect(ActionHints::resolve('confirm', 'Confirm')->control->label)->toBe('Enter');
});


it('falls back to the active control label while retaining healthy glyphs and action bindings', function (string $asset) {
  file_put_contents($this->root . '/corrupt.png', 'not a PNG');
  $theme = new MenuPresentationCatalog($this->root, ['schema' => 'ichiloto.menu/1',
    'icons' => ['input.keyboard.ENTER' => 'glyph.png', 'input.keyboard.ESCAPE' => $asset, 'unknown' => 'unknown.png']]);
  expect(file_exists($this->root . '/warning.log'))->toBeFalse();
  $bindings = InputManager::getBindings();
  $hints = [ActionHints::resolve('confirm', 'Confirm'), ActionHints::resolve('cancel', 'Cancel')];
  $box = new CanvasRectangle(0, 0, 640, 48);
  $frame = MenuActionHints::compose(640, 48, 'hints', $hints, $theme, $box);
  expect(array_column($frame->images, 'asset'))->toBe(['glyph.png'])
    ->and(implode('', array_column($frame->textLayers[0]->runs, 'text')))->toBe(': Confirm   Escape : Cancel')
    ->and(MenuActionHints::height($hints, $theme, $box->width))->toBe(24)
    ->and(InputManager::getBindings())->toBe($bindings)
    ->and(array_column($hints, 'action'))->toBe(['confirm', 'cancel'])
    ->and(file_get_contents($this->root . '/warning.log'))->toContain($asset);
})->with(['missing.png', 'corrupt.png']);
