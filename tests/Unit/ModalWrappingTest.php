<?php

use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\Modal\SelectModal;
use Ichiloto\Engine\UI\Modal\TextBoxModal;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * The blocked-door message that used to be cut off mid sentence.
 */
const LONG_MESSAGE = 'The shop is still shuttered. Perhaps someone at home needs something first.';

class WrappingModalProxy extends Modal
{
  private BufferedOutput $buffer;

  public function __construct(string $message, int $width = DEFAULT_DIALOG_WIDTH)
  {
    $this->title = '';
    $this->message = $message;
    $this->rect = new Rect(0, 0, $width, DEFAULT_DIALOG_HEIGHT);
    $this->borderPack = new DefaultBorderPack();
    $this->output = $this->buffer = new BufferedOutput();
  }

  public function fit(): void
  {
    $this->fitContentToWidth();
  }

  public function lines(): array
  {
    return $this->content;
  }

  public function boxHeight(): int
  {
    return $this->rect->getHeight();
  }

  public function place(int $left, int $top): void
  {
    $this->leftMargin = $left;
    $this->topMargin = $top;
  }

  /**
   * @return array{0: string, 1: string} The box content, and the cursor moves
   * that positioned it.
   */
  public function draw(): array
  {
    ob_start();
    $this->renderContent();
    $cursor = ob_get_clean();

    return [$this->buffer->fetch(), $cursor];
  }
}

class WrappingSelectModalProxy extends SelectModal
{
  public function __construct(string $message, int $width = DEFAULT_DIALOG_WIDTH)
  {
    $this->message = $message;
    $this->options = ['Yes', 'No'];
    $this->totalOptions = 2;
    $this->rect = new Rect(0, 0, $width, 3);
    $this->borderPack = new DefaultBorderPack();
    $this->wrapMessage();
  }

  public function lines(): array
  {
    return $this->messageLines;
  }

  public function optionsHeight(): int
  {
    return $this->getOptionsHeight();
  }
}

class WrappingTextBoxModalProxy extends TextBoxModal
{
  public function __construct(string $message, int $width = DEFAULT_DIALOG_WIDTH)
  {
    $this->message = $message;
    $this->rect = new Rect(0, 0, $width, 5);
    $this->window = new Window('', '', new Vector2(), $width, 5);
    $this->currentCharacterIndex = mb_strlen($message);
  }

  public function lines(): array
  {
    return $this->convertMessageToLinesOfContent($this->message);
  }

  public function contentWidth(): int
  {
    return $this->window->getContentWidth();
  }
}

it('wraps a message too long for the box instead of cutting it off', function () {
  $modal = new WrappingModalProxy(LONG_MESSAGE);
  $modal->fit();

  $lines = $modal->lines();

  expect(count($lines))->toBeGreaterThan(1)
    ->and(implode(' ', $lines))->toBe(LONG_MESSAGE);

  foreach ($lines as $line) {
    expect(mb_strlen($line))->toBeLessThanOrEqual(DEFAULT_DIALOG_WIDTH - 2);
  }
});

it('grows the box to hold every wrapped line', function () {
  $modal = new WrappingModalProxy(LONG_MESSAGE);
  $modal->fit();

  // Top border + content + button row + bottom border.
  expect($modal->boxHeight())->toBe(count($modal->lines()) + 3)
    ->and($modal->boxHeight())->toBeGreaterThan(DEFAULT_DIALOG_HEIGHT);
});

it('renders the tail of the message that used to be clipped', function () {
  $modal = new WrappingModalProxy(LONG_MESSAGE);
  $modal->fit();

  [$content] = $modal->draw();

  expect($content)->toContain('home needs something first.');
});

it('places each wrapped line on its own row', function () {
  $modal = new WrappingModalProxy(LONG_MESSAGE);
  $modal->fit();
  $modal->place(20, 4);

  [, $cursor] = $modal->draw();

  // Written back to back, the second line trails off the end of the first row.
  expect($cursor)->toContain("\033[6;21H")
    ->and($cursor)->toContain("\033[7;21H");
});

it('keeps authored line breaks as their own lines', function () {
  $modal = new WrappingModalProxy("First line.\nSecond line.");
  $modal->fit();

  expect($modal->lines())->toBe(['First line.', 'Second line.']);
});

it('leaves a message that already fits alone', function () {
  $modal = new WrappingModalProxy('Short enough.');
  $modal->fit();

  expect($modal->lines())->toBe(['Short enough.'])
    ->and($modal->boxHeight())->toBe(DEFAULT_DIALOG_HEIGHT + 1);
});

it('breaks a word longer than the box rather than overflowing it', function () {
  $modal = new WrappingModalProxy(str_repeat('x', DEFAULT_DIALOG_WIDTH * 2));
  $modal->fit();

  foreach ($modal->lines() as $line) {
    expect(mb_strlen($line))->toBeLessThanOrEqual(DEFAULT_DIALOG_WIDTH - 2);
  }
});

it('wraps a select modal prompt and grows it to fit', function () {
  $modal = new WrappingSelectModalProxy(LONG_MESSAGE);

  expect(count($modal->lines()))->toBeGreaterThan(1)
    ->and(implode(' ', $modal->lines()))->toBe(LONG_MESSAGE)
    // Message lines + the two options + spacing.
    ->and($modal->optionsHeight())->toBe(count($modal->lines()) + 4);
});

it('wraps typed dialogue to the window content width without clipping an edge character', function () {
  $message = 'Standard language. Pass the practicum, pass the exam, join the BSA.';
  $modal = new WrappingTextBoxModalProxy($message);
  $lines = $modal->lines();

  expect(implode(' ', $lines))->toBe($message)
    ->and($lines)->toContain('the exam, join the BSA.')
    ->and($modal->contentWidth())->toBe(DEFAULT_DIALOG_WIDTH - 4);

  foreach ($lines as $line) {
    expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual($modal->contentWidth());
  }
});

it('derives usable window width from borders and configured padding', function () {
  $window = new Window(
    position: new Vector2(),
    width: 20,
    height: 3,
    padding: new WindowPadding(rightPadding: 2, leftPadding: 3),
  );

  expect($window->getContentWidth())->toBe(13);
});
