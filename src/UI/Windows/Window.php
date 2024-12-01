<?php

namespace Ichiloto\Engine\UI\Windows;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Interfaces\WindowInterface;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Window. The base class for all windows.
 *
 * @package Ichiloto\Engine\UI\Windows
 */
class Window implements WindowInterface
{
  /**
   * The window's observers.
   *
   * @var ItemList
   */
  protected ItemList $observers;
  /**
   * @var array
   */
  protected array $content = [];
  /**
   * @var Cursor The window's cursor.
   */
  protected Cursor $cursor;
  /**
   * @var OutputInterface The window's output.
   */
  protected OutputInterface $output;

  /**
   * Window constructor.
   *
   * @param string $title The window's title.
   * @param string $help The window's help.
   * @param Vector2 $position The window's position.
   * @param int $width The window's width.
   * @param int $height The window's height.
   * @param BorderPackInterface $borderPack The window's border pack.
   * @param WindowAlignment $alignment The window's alignment.
   * @param Color $backgroundColor The window's background color.
   */
  public function __construct(
    protected string $title = '',
    protected string $help = '',
    protected Vector2 $position = new Vector2(),
    protected int $width = DEFAULT_WINDOW_WIDTH,
    protected int $height = DEFAULT_WINDOW_HEIGHT,
    protected BorderPackInterface $borderPack = new DefaultBorderPack(),
    protected WindowAlignment $alignment = new WindowAlignment(HorizontalAlignment::LEFT, VerticalAlignment::MIDDLE),
    protected WindowPadding $padding = new WindowPadding(rightPadding: 1, leftPadding: 1),
    protected Color $backgroundColor = Color::BLACK,
    protected ?Color $foregroundColor = null
  )
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->setContent(array_fill(0, $this->height - 2, ' '));
    $this->cursor = Console::cursor();
    $this->output = new ConsoleOutput();
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $leftMargin = max(0, ($this->position->x + ($x ?? 1)));
    $topMargin = max(0, ($this->position->y + ($y ?? 1)));

    // Render the top border
    $output = $this->getTopBorder();
    Console::cursor()->moveTo($leftMargin, $topMargin);
    if ($this->foregroundColor) {
      $this->output->write($this->foregroundColor->value . $output . Color::RESET->value);
    } else {
      $this->output->write($output);
    }

    // Render the content
    $linesOfContent = $this->getLinesOfContent();
    foreach ($linesOfContent as $index => $line) {
      $this->cursor->moveTo($leftMargin, $topMargin + $index + 1);
      if ($this->foregroundColor) {
        $this->output->write($this->foregroundColor->value . mb_substr($line, 0, $this->width) . Color::RESET->value);
      } else {
        $this->output->write($line);
      }
    }

    // Render the bottom border
    $topMargin = $topMargin + count($linesOfContent) + 1; // We add 1 to account for the top border
    $output = $this->getBottomBorder();
    Console::cursor()->moveTo($leftMargin, $topMargin);
    if ($this->foregroundColor) {
      $this->output->write($this->foregroundColor->value . $output . Color::RESET->value);
    } else {
      $this->output->write($output);
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $leftMargin = max(0, ($this->position->x + ($x ?? 1)));
    $topMargin = max(0, ($this->position->y + ($y ?? 1)));

    for ($row = 0; $row < $this->height; $row++) {
      Console::cursor()->moveTo($leftMargin, $topMargin + $row);
      $this->output->write(str_repeat(' ', $this->width));
    }
  }

  /**
   * @inheritDoc
   */
  public function addObserver(ObserverInterface|string $observer): void
  {
    $this->observers->add($observer);
  }

  /**
   * @inheritDoc
   */
  public function removeObserver(ObserverInterface|string $observer): void
  {
    $this->observers->remove($observer);
  }

  /**
   * @inheritDoc
   */
  public function notify(object $entity, EventInterface $event): void
  {
    /** @var ObserverInterface $observer */
    foreach ($this->observers as $observer) {
      $observer->onNotify($entity, $event);
    }
  }

  /**
   * @inheritDoc
   */
  public function getTitle(): string
  {
    return $this->title;
  }

  /**
   * @inheritDoc
   */
  public function setTitle(string $title): void
  {
    $this->title = $title;
  }

  /**
   * @inheritDoc
   */
  public function getHelp(): string
  {
    return $this->help;
  }

  /**
   * @inheritDoc
   */
  public function setHelp(string $help): void
  {
    $this->help = $help;
  }

  /**
   * Returns the window's content.
   *
   * @return array The window's content.
   */
  public function getContent(): array
  {
    return $this->content;
  }

  /**
   * Sets the window's content.
   *
   * @param array $content The window's content.
   */
  public function setContent(array $content): void
  {
    $this->content = $content;
  }

  /**
   * Adds a line of content to the window.
   *
   * @param string $content The line of content to add.
   * @return void
   */
  public function addContent(string $content): void
  {
    $this->content[] = $content;
  }

  /**
   * Removes a line of content from the window.
   *
   * @param string $content The line of content to remove.
   * @return void
   */
  public function removeContent(string $content): void
  {
    $this->content = array_diff($this->content, [$content]);
  }

  /**
   * Clears the window's content.
   *
   * @return void
   */
  public function clearContent(): void
  {
    $this->content = [];
  }

  /**
   * @inheritDoc
   */
  public function getBorderPack(): BorderPackInterface
  {
    return $this->borderPack;
  }

  /**
   * @inheritDoc
   */
  public function setBorderPack(BorderPackInterface $borderPack): void
  {
    $this->borderPack = $borderPack;
  }

  /**
   * @inheritDoc
   */
  public function getAlignment(): WindowAlignment
  {
    return $this->alignment;
  }

  /**
   * @inheritDoc
   */
  public function getBackgroundColor(): Color
  {
    return $this->backgroundColor;
  }

  /**
   * @inheritDoc
   */
  public function setBackgroundColor(Color $backgroundColor): void
  {
    $this->backgroundColor = $backgroundColor;
  }

  /**
   * Returns the window's top border.
   *
   * @return string The window's top border.
   */
  private function getTopBorder(): string
  {
    $titleLength = mb_strlen($this->title);
    $borderLength = $this->width - $titleLength  - 3;
    $output = $this->borderPack->getTopLeftCorner() . $this->borderPack->getHorizontalBorder() . $this->title;
    $output .= str_repeat($this->borderPack->getHorizontalBorder(), max($borderLength, 0));
    $output .= $this->borderPack->getTopRightCorner();

    return  $output;
  }

  /**
   * Returns the window's lines of content
   *
   * @return string[] The window's lines of content.
   */
  private function getLinesOfContent(): array
  {
    $content = [];

    // Top padding
    for ($row = 0; $row < $this->padding->getTopPadding(); $row++) {
      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', $this->width - 2);
      $output .= $this->borderPack->getVerticalBorder();

      $content[]  = $output;
    }

    $alignedContent = match ($this->alignment->horizontalAlignment) {
      HorizontalAlignment::LEFT => $this->getLeftAlignedContent(),
      HorizontalAlignment::CENTER => $this->getCenterAlignedContent(),
      HorizontalAlignment::RIGHT => $this->getRightAlignedContent(),
    };

    foreach ($alignedContent as $line)
    {
      $content[] = $line;
    }

    // Bottom padding
    for ($row = 0; $row < $this->padding->getBottomPadding(); $row++) {
      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', $this->width - 2);
      $output .= $this->borderPack->getVerticalBorder();
      $content[] = $output;
    }

    return $content;
  }

  /**
   * Returns the window's bottom border.
   *
   * @return string The window's bottom border.
   */
  private function getBottomBorder(): string
  {
    $helpLength = mb_strlen($this->help);
    $output = $this->borderPack->getBottomLeftCorner() . $this->borderPack->getHorizontalBorder() . $this->help;
    $output .= str_repeat($this->borderPack->getHorizontalBorder(), max($this->width - $helpLength - 3, 0));
    $output .= $this->borderPack->getBottomRightCorner();

    return $output;
  }

  /**
   * Returns the window's left aligned content.
   *
   * @return string[] The window's left aligned content.
   */
  private function getLeftAlignedContent(): array
  {
    $leftAlignedContent = [];

    foreach ($this->content as $content) {
      $contentLength = mb_strlen($content);
      $leftPaddingLength = $this->padding->getLeftPadding();
      $rightPaddingLength = $this->width - $contentLength - $this->padding->getRightPadding() - 2;

      $output = $this->borderPack->getVerticalBorder();
      $output .= $this->padContent($content, $leftPaddingLength, $rightPaddingLength, $this->width - 2);
      $output .= $this->borderPack->getVerticalBorder();

      $leftAlignedContent[] = $output;
    }

    return $leftAlignedContent;
  }

  /**
   * Returns the window's center aligned content.
   *
   * @return string[] The window's center aligned content.
   */
  private function getCenterAlignedContent(): array
  {
    $centerAlignedContent = [];

    foreach ($this->content as $content) {
      $contentLength = mb_strlen($content);
      $totalPadding = $this->width - $this->padding->getLeftPadding() - $contentLength - $this->padding->getRightPadding() - 2;
      $leftPaddingLength = max(floor($totalPadding / 2), 0);
      $rightPaddingLength = max(ceil($totalPadding / 2), 0);

      $output = $this->borderPack->getVerticalBorder();
      $contentRender = str_repeat(' ', max($leftPaddingLength, 0));
      $contentRender .= $content;
      $contentRender .= str_repeat(' ', max($rightPaddingLength, 0));

      $output .= str_pad($contentRender, $this->width - 2, ' ', STR_PAD_BOTH);
      $output .= $this->borderPack->getVerticalBorder();

      $centerAlignedContent[] = $output;
    }

    return $centerAlignedContent;
  }

  /**
   * Returns the window's right aligned content.
   *
   * @return string[] The window's right aligned content.
   */
  private function getRightAlignedContent(): array
  {
    $rightAlignedContent = [];

    foreach ($this->content as $content) {
      $contentLength = mb_strlen($content);
      $leftPaddingLength = $this->width - $contentLength - $this->padding->getLeftPadding() - 2;
      $rightPaddingLength = $this->padding->getRightPadding(); // -1 for the border

      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', max($leftPaddingLength, 0));
      $output .= $content;
      $output .= str_repeat(' ', max($rightPaddingLength, 0));
      $output .= $this->borderPack->getVerticalBorder();

      $rightAlignedContent[] = $output;
    }

    return $rightAlignedContent;
  }

  /**
   * @inheritDoc
   */
  public function getForegroundColor(): ?Color
  {
    return $this->foregroundColor;
  }

  /**
   * @inheritDoc
   */
  public function setForegroundColor(?Color $foregroundColor): void
  {
    $this->foregroundColor = $foregroundColor;
    $this->render();
  }

  /**
   * Sets the window's position.
   *
   * @param Vector2 $position The window's position.
   * @return void
   */
  public function setPosition(Vector2 $position): void
  {
    $this->position = $position;
  }

  /**
   * Returns the window's position.
   *
   * @return Vector2 The window's position.
   */
  public function getPosition(): Vector2
  {
    return $this->position;
  }

  /**
   * @param string $content
   * @param int $leftPaddingLength
   * @param int $rightPaddingLength
   * @param int $maxLength
   * @return string
   */
  private function padContent(string $content, int $leftPaddingLength, int $rightPaddingLength, int $maxLength = -1): string
  {
    $ansiRegex = '/\033\[[0-9;]*m/';
    $strippedString = preg_replace($ansiRegex, '', $content);

    $contentLength = mb_strlen($content) - 3;

    $leftPadding = str_repeat(' ', max($leftPaddingLength, 0));
    $rightPadding = str_repeat(' ', max($rightPaddingLength, 0));

    return mb_substr($leftPadding . $content . $rightPadding, 0, max($contentLength, $maxLength));
  }
}