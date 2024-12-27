<?php

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;

/**
 * This class represents a modal that displays a message in a text box.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class TextBoxModal extends Modal
{
  /**
   * @var string|null $help The help text to display.
   */
  protected ?string $help {
    get {
      return $this->help;
    }
    set {
      $this->help = $value;
      $this->window->setHelp($value);
    }
  }
  /**
   * @var bool $isPrinting Whether the message is being printed.
   */
  protected bool $isPrinting = false;
  /**
   * @var int $totalLinesOfContent The total number of lines of content.
   */
  protected int $totalLinesOfContent = 0;
  /**
   * @var float $nextPrintTime The next print time.
   */
  protected float $nextPrintTime = 0;
  /**
   * @var int $leftMargin The left margin.
   */
  protected int $currentCharacterIndex = 0;
  /**
   * @var int $topMargin The top margin.
   */
  protected int $messageLength = 0;

  /**
   * TextBoxModal constructor.
   *
   * @param Game $game The game instance.
   * @param string $message The message to display.
   * @param string $title The title of the modal.
   * @param string $help The help text to display.
   * @param WindowPosition $position The position of the modal.
   * @param BorderPackInterface $borderPack The border pack to use.
   * @param float $charactersPerSecond The number of characters to print per second.
   */
  public function __construct(
    Game $game,
    string $message,
    string $title = '',
    string $help = '',
    WindowPosition $position = WindowPosition::BOTTOM,
    BorderPackInterface $borderPack = new DefaultBorderPack(),
    protected float $charactersPerSecond = 60
  )
  {
    $width = DEFAULT_DIALOG_WIDTH;
    $height = 5;
    $positionCoordinates = $position->getCoordinates($width, $height);
    $this->messageLength = mb_strlen($message);

    $this->window = new Window(
      $title,
      $help,
      $positionCoordinates,
      $width,
      $height
    );

    parent::__construct(
      $game,
      $message,
      $title,
      new Rect(
        $positionCoordinates->x,
        $positionCoordinates->y,
        $width,
        $height
      ),
      [],
      $help,
      $borderPack
    );
  }

  /**
   * @inheritDoc
   */
  public function show(): void
  {
    parent::show();
    $this->leftMargin = $this->rect->getX();
    $this->topMargin = $this->rect->getY();
    $this->isPrinting = true;

    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    parent::update();

    if ($this->isPrinting) {
      $this->updateContent();
    }

    if (Input::isKeyDown(KeyCode::SPACE)) {
      $this->submit();
    }
  }

  public function updateContent(): void
  {
    if ($this->isPrinting) {
      $this->content = $this->convertMessageToLinesOfContent($this->message);

      // Calculate the number of lines.
      $this->totalLinesOfContent = count($this->content);
      $verticalPadding = $this->rect->getHeight() - $this->totalLinesOfContent - 2; // We subtract 2 because of the top and bottom borders.

      for ($row = 0; $row < $verticalPadding; $row++) {
        $this->content[] = '';
      }

      $now = microtime(true);
      if ($now >= $this->nextPrintTime) {
        $this->nextPrintTime = $now + (1 / $this->charactersPerSecond);
        $this->currentCharacterIndex++;
      }

      $this->isPrinting = $this->currentCharacterIndex < $this->messageLength;

      $this->window->setContent($this->content);
    } else {
      $this->help = 'space:continue';
    }
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $this->window->render($x, $y);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->window->erase($x, $y);
  }

  /**
   * @inheritDoc
   */
  protected function submit(): void
  {
    if ($this->isPrinting) {
      $this->currentCharacterIndex = $this->messageLength;
    } else {
      $this->cancel();
    }
  }

  /**
   * Converts the message to lines of content.
   *
   * @param string $message
   * @return string[] The lines of content.
   */
  protected function convertMessageToLinesOfContent(string $message): array
  {
    // Split the message into lines.
    $contentString = wordwrap($message, $this->rect->getWidth() - 3, "\n", true);
    return explode("\n", substr($contentString, 0, $this->currentCharacterIndex));
  }
}