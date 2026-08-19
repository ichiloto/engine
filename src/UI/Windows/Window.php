<?php

namespace Ichiloto\Engine\UI\Windows;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\VerticalAlignment;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Interfaces\WindowInterface;
use Ichiloto\Engine\Util\Debug;

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
  /** How authored content affects the window's vertical footprint. */
  protected WindowHeightPolicy $heightPolicy = WindowHeightPolicy::GROW_TO_CONTENT;
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
    protected ?Color $foregroundColor = null,
    WindowHeightPolicy $heightPolicy = WindowHeightPolicy::GROW_TO_CONTENT,
  )
  {
    $this->height = max(2, $this->height);
    $this->heightPolicy = $heightPolicy;
    $this->observers = new ItemList(ObserverInterface::class);
    $this->setContent([]);
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $leftMargin = max(0, ($this->position->x + ($x ?? 1)));
    $topMargin = max(0, ($this->position->y + ($y ?? 1)));
    $bufferX = max(0, $leftMargin - 1);
    $bufferY = max(0, $topMargin - 1);

    Console::beginFrame();

    try {
      // Render the top border
      $output = $this->applyForegroundColor($this->getTopBorder());
      Console::write($output, $bufferX, $bufferY);

      // Render the content
      $linesOfContent = $this->getLinesOfContent();
      foreach ($linesOfContent as $index => $line) {
        $output = TerminalText::truncateToWidth($line, $this->width);
        Console::write(
          $this->applyForegroundColor($output),
          $bufferX,
          $bufferY + $index + 1,
        );
      }

      // Render the bottom border
      $bottomY = $bufferY + count($linesOfContent) + 1;
      $output = $this->applyForegroundColor($this->getBottomBorder());
      Console::write($output, $bufferX, $bottomY);
    } finally {
      Console::endFrame();
    }
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $leftMargin = max(0, ($this->position->x + ($x ?? 1)));
    $topMargin = max(0, ($this->position->y + ($y ?? 1)));
    $bufferX = max(0, $leftMargin - 1);
    $bufferY = max(0, $topMargin - 1);

    Console::beginFrame();

    try {
      for ($row = 0; $row < $this->height; $row++) {
        Console::write(str_repeat(' ', $this->width), $bufferX, $bufferY + $row);
      }
    } finally {
      Console::endFrame();
    }
  }

  /**
   * Applies the configured foreground color without bypassing the console's
   * canonical cell buffer.
   */
  private function applyForegroundColor(string $output): string
  {
    if (! $this->foregroundColor) {
      return $output;
    }

    return $this->foregroundColor->value . $output . Color::RESET->value;
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
   * @inheritDoc
   */
  public function getHeight(): int
  {
    return $this->height;
  }

  /**
   * @inheritDoc
   */
  public function getWidth(): int
  {
    return $this->width;
  }

  /**
   * @inheritDoc
   */
  public function getBounds(): Rect
  {
    return new Rect(
      $this->position->x,
      $this->position->y,
      $this->width,
      $this->height,
    );
  }

  /**
   * @inheritDoc
   */
  public function getContentHeight(): int
  {
    return max(
      0,
      $this->height
        - 2
        - $this->padding->getTopPadding()
        - $this->padding->getBottomPadding()
    );
  }

  /**
   * @inheritDoc
   */
  public function fitHeightToContent(int $contentRows): void
  {
    if (! isset($this->height, $this->padding)) {
      return;
    }

    $this->height = max(
      $this->height,
      max(0, $contentRows)
        + $this->padding->getTopPadding()
        + $this->padding->getBottomPadding()
        + 2,
    );
  }

  /**
   * @inheritDoc
   */
  public function getHeightPolicy(): WindowHeightPolicy
  {
    return $this->heightPolicy;
  }

  /**
   * @inheritDoc
   */
  public function setHeightPolicy(WindowHeightPolicy $heightPolicy): void
  {
    $this->heightPolicy = $heightPolicy;

    if (
      $this->heightPolicy === WindowHeightPolicy::GROW_TO_CONTENT
      && isset($this->height, $this->padding)
    ) {
      $this->fitHeightToContent(count($this->content));
    }
  }

  /**
   * Returns the width available to content after borders and padding.
   *
   * Content producers must use this value for wrapping and truncation. The
   * window applies the same limit while rendering, so duplicating the
   * calculation outside this class can otherwise lose edge characters.
   *
   * @return int The usable content width in terminal cells.
   */
  public function getContentWidth(): int
  {
    return max(
      0,
      $this->width
        - 2
        - $this->padding->getLeftPadding()
        - $this->padding->getRightPadding()
    );
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

    if (
      $this->heightPolicy === WindowHeightPolicy::GROW_TO_CONTENT
      && isset($this->height, $this->padding)
    ) {
      $this->fitHeightToContent(count($this->content));
    }
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

    if (
      $this->heightPolicy === WindowHeightPolicy::GROW_TO_CONTENT
      && isset($this->height, $this->padding)
    ) {
      $this->fitHeightToContent(count($this->content));
    }
  }

  /**
   * Removes a line of content from the window.
   *
   * @param string $content The line of content to remove.
   * @return void
   */
  public function removeContent(string $content): void
  {
    $this->setContent(array_values(array_diff($this->content, [$content])));
  }

  /**
   * Clears the window's content.
   *
   * @return void
   */
  public function clearContent(): void
  {
    $this->setContent([]);
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
    $titleLength = TerminalText::displayWidth($this->title);
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

    // Height is the authoritative render and erase footprint. Ordinary
    // windows have already grown in setContent()/addContent() so every line
    // fits. FIXED windows deliberately expose only their viewport because
    // their content owner paginates or scrolls the remaining rows.
    $contentRows = max(0, $this->height - 2);
    $content = array_slice($content, 0, $contentRows);

    while (count($content) < $contentRows) {
      $content[] = $this->borderPack->getVerticalBorder()
        . str_repeat(' ', max(0, $this->width - 2))
        . $this->borderPack->getVerticalBorder();
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
    $helpLength = TerminalText::displayWidth($this->help);
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
      $leftPaddingLength = $this->padding->getLeftPadding();
      $rightPaddingLength = $this->padding->getRightPadding();
      $availableWidth = $this->getContentWidth();

      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', $leftPaddingLength);
      $output .= TerminalText::padRight($content, $availableWidth);
      $output .= str_repeat(' ', $rightPaddingLength);
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
      $leftPaddingLength = $this->padding->getLeftPadding();
      $rightPaddingLength = $this->padding->getRightPadding();
      $availableWidth = $this->getContentWidth();

      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', max($leftPaddingLength, 0));
      $output .= TerminalText::padCenter($content, $availableWidth);
      $output .= str_repeat(' ', max($rightPaddingLength, 0));
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
      $leftPaddingLength = $this->padding->getLeftPadding();
      $rightPaddingLength = $this->padding->getRightPadding();
      $availableWidth = $this->getContentWidth();

      $output = $this->borderPack->getVerticalBorder();
      $output .= str_repeat(' ', max($leftPaddingLength, 0));
      $output .= TerminalText::padLeft($content, $availableWidth);
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

}
