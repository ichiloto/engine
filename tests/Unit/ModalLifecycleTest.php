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
use Ichiloto\Engine\UI\Windows\Window;
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

final class LowerLayerPaintingGameProbe extends Game
{
  public function tickWhileBlocked(): void
  {
    Console::write(str_repeat('x', 50), 15, 10);
  }
}

final class ModalPrecedenceProbe extends AlertModal
{
  public int $inputCount = 0;
  public int $renderCount = 0;
  /** @var string[] */
  public array $composedBuffer = [];

  protected function handleInput(): void
  {
    if (++$this->inputCount > 1) {
      $this->hide();
    }
  }

  public function update(): void
  {
    // This probe advances solely through the blocking-loop lifecycle.
  }

  public function render(): void
  {
    parent::render();

    if (++$this->renderCount === 2) {
      $this->composedBuffer = Console::getBuffer();
    }
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

  public function renderCompletePage(): void
  {
    $this->currentCharacterIndex = $this->messageLength;
    $this->isPrinting = true;
    $this->updateContent();
    $this->render();
  }

  /** @return int[] */
  public function pageLineCounts(): array
  {
    return array_map(static fn(string $page): int => count(explode("\n", $page)), $this->messagePages);
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
  $console->getProperty('frameRows')->setValue(null, []);
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

it('renders every row of a wrapped alert over an existing field frame', function () {
  $console = new ReflectionClass(Console::class);
  $console->getProperty('width')->setValue(null, 230);
  $console->getProperty('height')->setValue(null, 39);
  $console->getProperty('buffer')->setValue(null, array_fill(0, 39, str_repeat('.', 230)));

  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedAlertModalProbe(
    $game,
    'Inspect the ROUTE CHECK enclosure west of the Field Post before checking in.',
    '',
  );

  ob_start();
  $modal->prepareAndRender();
  ob_end_clean();

  $position = $modal->position();
  [$width, $height] = $modal->size();
  $rendered = array_map(TerminalText::stripAnsi(...), Console::getBuffer());
  $footprint = array_map(
    static fn(string $row): string => TerminalText::sliceSymbols($row, $position->x, $width),
    array_slice($rendered, $position->y, $height),
  );

  expect($height)->toBe(5)
    ->and($footprint)->toHaveCount($height)
    ->and($footprint[0])->toStartWith('╔')
    ->and($footprint[1])->toContain('Inspect the ROUTE CHECK enclosure west of the')
    ->and($footprint[2])->toContain('Field Post before checking in.')
    ->and($footprint[3])->toContain('OK')
    ->and($footprint[4])->toStartWith('╚');
});

it('composes a blocking modal after lower live layers update', function () {
  $game = (new ReflectionClass(LowerLayerPaintingGameProbe::class))->newInstanceWithoutConstructor();
  $modal = new ModalPrecedenceProbe(
    $game,
    'Inspect the ROUTE CHECK enclosure west of the Field Post before checking in.',
    '',
  );

  ob_start();
  $modal->open();
  ob_end_clean();

  $composed = array_map(TerminalText::stripAnsi(...), $modal->composedBuffer);

  expect($modal->renderCount)->toBe(2)
    ->and($composed[10])->toContain('Inspect the ROUTE CHECK enclosure west of the')
    ->and($composed[10])->not->toContain(str_repeat('x', 10));
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

it('measures wrapped dialogue before bottom anchoring it inside the screen', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedTextBoxModalProbe(
    $game,
    'E-Class authority covers supervised low-tier response, civilian protection, evidence handling, regulated weapons, and incident reports.',
    'Inspector Adisa',
    position: WindowPosition::BOTTOM,
  );

  [$rectPosition, $windowPosition] = $modal->positions();
  [$width, $height] = $modal->size();

  ob_start();
  $modal->renderCompletePage();
  ob_end_clean();
  $rendered = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  expect($height)->toBe(6)
    ->and($rectPosition)->toEqual(WindowPosition::BOTTOM->getCoordinates($width, $height))
    ->and($windowPosition)->toEqual($rectPosition)
    ->and(intval($rectPosition->y + $height))->toBe(get_screen_height())
    ->and(implode("\n", array_slice($rendered, intval($rectPosition->y), $height)))->toContain('reports.')
    ->and($rendered[get_screen_height() - 1])->toContain('╚');
});

it('paginates dialogue that cannot fit its bounded text box', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new PositionedTextBoxModalProbe(
    $game,
    implode(' ', array_fill(0, 20, 'A complete sentence must remain available to the player.')),
    'Narrator',
    position: WindowPosition::BOTTOM,
  );

  expect(count($modal->pageLineCounts()))->toBeGreaterThan(1);

  foreach ($modal->pageLineCounts() as $lineCount) {
    expect($lineCount)->toBeLessThanOrEqual(4);
  }
});

it('grows a window footprint before rendering content beyond its authored minimum', function () {
  $window = new Window(
    position: new Vector2(4, 5),
    width: 20,
    height: 3,
  );
  $window->setContent(['first', 'overflow one', 'overflow two']);

  ob_start();
  $window->render();
  ob_end_clean();

  $rendered = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  expect(substr($rendered[5], 4))->toContain('╔')
    ->and(substr($rendered[6], 4, 20))->toContain('first')
    ->and(substr($rendered[7], 4, 20))->toContain('overflow one')
    ->and(substr($rendered[8], 4, 20))->toContain('overflow two')
    ->and(substr($rendered[9], 4))->toContain('╚')
    ->and(substr($rendered[10], 4, 20))->toBe(str_repeat('.', 20));
});

it('erases the complete authoritative footprint after a window grows', function () {
  $window = new Window(
    position: new Vector2(4, 5),
    width: 20,
    height: 3,
  );
  $window->setContent(['first', 'second', 'third']);

  ob_start();
  $window->render();
  $window->erase();
  ob_end_clean();

  $erased = array_map(TerminalText::stripAnsi(...), Console::getBuffer());

  for ($row = 5; $row < 10; $row++) {
    expect(substr($erased[$row], 4, 20))->toBe(str_repeat(' ', 20));
  }

  expect(substr($erased[10], 4, 20))->toBe(str_repeat('.', 20));
});
