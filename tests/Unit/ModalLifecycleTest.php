<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Modal\AlertModal;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

final class PositionedAlertModalProbe extends AlertModal
{
  public function prepareAndRender(): void
  {
    $this->fitContentToWidth();
    $this->positionForOpen();
    $this->show();
    $this->render();
  }

  public function position(): Vector2
  {
    return $this->rect->position;
  }

  public function size(): array
  {
    return [$this->rect->getWidth(), $this->rect->getHeight()];
  }
}

final class DismissOnFirstInputModalProbe extends Modal
{
  public int $renderCount = 0;

  protected function handleInput(): void
  {
    $this->hide();
  }

  public function update(): void
  {
    // The first simulated input dismisses the modal; no further input work is
    // needed for this lifecycle regression.
  }

  public function render(): void
  {
    $this->renderCount++;
    parent::render();
  }
}

final class PositionedSelectModalProbe extends SelectModal
{
  public function position(): Vector2
  {
    return $this->rect->position;
  }

  public function size(): array
  {
    return [$this->rect->getWidth(), $this->rect->getHeight()];
  }
}

final class PositionedTextBoxModalProbe extends TextBoxModal
{
  public function prepareAndRender(): void
  {
    $this->positionForOpen();
    $this->show();
    $this->render();
  }

  /** @return array{Vector2, Vector2} */
  public function positions(): array
  {
    return [$this->rect->position, $this->window->getPosition()];
  }

  public function size(): array
  {
    return [$this->rect->getWidth(), $this->rect->getHeight()];
  }
}

beforeEach(function () {
  ConfigStore::put(PlaySettings::class, new PlaySettings([
    'width' => 80,
    'height' => 24,
  ]));

  $console = new ReflectionClass(Console::class);
  $console->getProperty('width')->setValue(null, 80);
  $console->getProperty('height')->setValue(null, 24);
  $console->getProperty('buffer')->setValue(null, array_fill(0, 24, str_repeat('.', 80)));
  $console->getProperty('frameDepth')->setValue(null, 0);
  $console->getProperty('frameBuffer')->setValue(null, '');
  $console->getProperty('terminalHandedBack')->setValue(null, false);

  (new ReflectionProperty(EventManager::class, 'instance'))->setValue(null, null);
});

afterEach(function () {
  ConfigStore::remove(PlaySettings::class);
  (new ReflectionProperty(EventManager::class, 'instance'))->setValue(null, null);
});

it('erases the centered alert footprint rather than an obsolete origin', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedAlertModalProbe($game, 'Equipment optimized!', 'Equipment');

  ob_start();
  $modal->prepareAndRender();
  ob_end_clean();

  $position = $modal->position();
  [$width, $height] = $modal->size();
  $rendered = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  expect($position)->toEqual(new Vector2(
    intdiv(80 - $width, 2),
    intdiv(24 - $height, 2),
  ))
    ->and(substr($rendered[$position->y + 1], $position->x, $width))->toContain('Equipment optimized!');

  ob_start();
  $modal->hide();
  ob_end_clean();

  $erased = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  for ($row = 0; $row < $height; $row++) {
    expect(substr($erased[$position->y + $row], $position->x, $width))->toBe(str_repeat(' ', $width));
  }

  expect(substr($erased[0], 0, $width))->toBe(str_repeat('.', $width));
});

it('does not redraw a modal after input dismisses it in the same frame', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new DismissOnFirstInputModalProbe(
    $game,
    'Equipment optimized!',
    'Equipment',
    new Rect(0, 0, DEFAULT_DIALOG_WIDTH, DEFAULT_DIALOG_HEIGHT),
  );

  ob_start();
  $modal->open();
  ob_end_clean();

  expect($modal->renderCount)->toBe(1)
    ->and(implode('', array_map(TerminalText::stripAnsi(...), Console::getBuffer())))
    ->not->toContain('Equipment optimized!');
});

it('uses the same buffered footprint for select modal rendering and erasure', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedSelectModalProbe(
    $game,
    'Choose one.',
    ['Potion', 'Ether'],
    'Items',
    rect: new Rect(9, 5, 30, 5),
  );

  ob_start();
  $modal->show();
  ob_end_clean();

  $position = $modal->position();
  [$width, $height] = $modal->size();
  $rendered = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  expect(substr($rendered[$position->y + 1], $position->x, $width))->toContain('Choose one.');

  ob_start();
  $modal->hide();
  ob_end_clean();

  $erased = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  for ($row = 0; $row < $height; $row++) {
    expect(substr($erased[$position->y + $row], $position->x, $width))->toBe(str_repeat(' ', $width));
  }

  expect(substr($erased[0], 0, $width))->toBe(str_repeat('.', $width));
});

it('retains authored dialogue placement while sharing buffered erasure', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedTextBoxModalProbe(
    $game,
    'Dialogue stays in its authored screen region.',
    'Kaelion',
    position: WindowPosition::BOTTOM,
  );

  ob_start();
  $modal->prepareAndRender();
  ob_end_clean();

  [$rectPosition, $windowPosition] = $modal->positions();
  [$width, $height] = $modal->size();
  $expectedPosition = WindowPosition::BOTTOM->getCoordinates($width, $height);

  expect($rectPosition)->toEqual($expectedPosition)
    ->and($windowPosition)->toEqual($expectedPosition);

  ob_start();
  $modal->hide();
  ob_end_clean();

  $erased = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  for ($row = 0; $row < $height; $row++) {
    expect(substr($erased[$expectedPosition->y + $row], $expectedPosition->x, $width))
      ->toBe(str_repeat(' ', $width));
  }
});
