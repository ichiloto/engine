<?php

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;

use Override;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;
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
  /** Ordinary dialogue keeps a compact three-line footprint. */
  private const int DEFAULT_CONTENT_LINES = 3;
  /** A fourth wrapped line may grow the box; longer dialogue is paginated. */
  private const int MAX_CONTENT_LINES_PER_PAGE = 4;
  /**
   * @var string|null $help The help text to display.
   */
  protected ?string $help {
    get {
      return $this->help;
    }
    set {
      $this->help = $value;

      if (isset($this->window)) {
        $this->window->setHelp($value);
      }
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
  /** @var string[] Fully wrapped dialogue pages. */
  protected array $messagePages = [];
  /** The page currently being typed. */
  protected int $currentPageIndex = 0;
  /** Help authored by the caller, restored while a new page is typing. */
  protected string $authoredHelp = '';

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
    $width = min(DEFAULT_DIALOG_WIDTH, max(4, get_screen_width()));
    $contentWidth = max(1, $width - 4); // borders and Window's default horizontal padding
    $wrappedLines = $this->wrapMessageIntoLines($message, $contentWidth);
    $screenContentLines = max(1, get_screen_height() - 2);
    $linesPerPage = min(self::MAX_CONTENT_LINES_PER_PAGE, $screenContentLines);
    $this->messagePages = array_map(
      static fn(array $page): string => implode("\n", $page),
      array_chunk($wrappedLines, $linesPerPage),
    );
    $this->messagePages = $this->messagePages ?: [''];
    $contentLines = min(
      $screenContentLines,
      max(self::DEFAULT_CONTENT_LINES, min(count($wrappedLines), $linesPerPage)),
    );
    $height = $contentLines + 2;
    $positionCoordinates = $position->getCoordinates($width, $height);
    $this->messageLength = mb_strlen($this->currentPageMessage());
    $this->authoredHelp = $help;

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

    $this->rebuildWindow();
  }

  /**
   * @inheritDoc
   */
  public function show(): void
  {
    parent::show();
    $this->leftMargin = $this->rect->getX();
    $this->topMargin = $this->rect->getY();
    $this->currentPageIndex = 0;
    $this->currentCharacterIndex = 0;
    $this->messageLength = mb_strlen($this->currentPageMessage());
    $this->help = $this->authoredHelp;
    $this->isPrinting = true;

    $this->updateContent();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    if ($this->isPrinting) {
      $this->updateContent();
    }

    // Dialogue owns its confirm lifecycle. Calling Modal::update() and then
    // checking Space separately made one Space press submit twice because
    // Space is normally bound to confirm: it could finish typing and dismiss
    // (or skip a page) in the same tick. Use the player's binding once.
    if (Input::isButtonDown('confirm')) {
      $this->submit();
    } elseif (Input::isButtonDown('cancel')) {
      $this->cancel();
    }
  }

  public function updateContent(): void
  {
    if ($this->isPrinting) {
      $now = microtime(true);

      if ($now >= $this->nextPrintTime) {
        $this->nextPrintTime = $now + (1 / $this->charactersPerSecond);
        $this->currentCharacterIndex++;
      }

      // The content is built *after* the cursor advances, and printing stops
      // only once that final content has been set — otherwise the closing
      // character of every message would never be drawn.
      $this->isPrinting = $this->currentCharacterIndex < $this->messageLength;
      $this->content = $this->convertMessageToLinesOfContent($this->currentPageMessage());

      // Calculate the number of lines.
      $this->totalLinesOfContent = count($this->content);
      $verticalPadding = $this->rect->getHeight() - $this->totalLinesOfContent - 2; // We subtract 2 because of the top and bottom borders.

      for ($row = 0; $row < $verticalPadding; $row++) {
        $this->content[] = '';
      }

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
    PresentationLayerPolicy::ui($this, fn() => $this->window->render($x, $y));
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
    } elseif (isset($this->messagePages[$this->currentPageIndex + 1])) {
      $this->currentPageIndex++;
      $this->currentCharacterIndex = 0;
      $this->messageLength = mb_strlen($this->currentPageMessage());
      $this->help = $this->authoredHelp;
      $this->nextPrintTime = 0;
      $this->isPrinting = true;
      $this->updateContent();
    } else {
      $this->cancel();
    }
  }

  /**
   * @inheritDoc
   *
   * Dialogue boxes stay quiet: confirm merely advances or fast-forwards the
   * message, so system sounds here would turn every conversation into noise.
   */
  protected function playInteractionSound(SystemSound $sound): void
  {
    // Intentionally silent.
  }

  /**
   * The text box types its message out one character at a time and wraps as it
   * goes, so it lays out its own content. Leave it alone.
   *
   * @return void
   */
  #[Override]
  protected function fitContentToWidth(): void
  {
    // Intentionally empty.
  }

  /**
   * Dialogue owns an authored top/bottom screen position rather than using
   * the centered placement of alerts, confirms, and prompts.
   */
  #[Override]
  protected function positionForOpen(): void
  {
    $this->setPosition($this->rect->getX(), $this->rect->getY());
  }

  /** Dialogue windows retain their ordinary left-aligned text layout. */
  #[Override]
  protected function rebuildWindow(): void
  {
    $this->window = new Window(
      $this->title,
      $this->help ?? '',
      $this->rect->position,
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      $this->borderPack,
      heightPolicy: WindowHeightPolicy::FIXED,
    );
  }

  /**
   * Converts the message to lines of content.
   *
   * @param string $message
   * @return string[] The lines of content.
   */
  protected function convertMessageToLinesOfContent(string $message): array
  {
    // Split the message into lines. The cursor counts characters, so the
    // slice must too — byte slicing would cut a multibyte character in half
    // and stop short of the end on any message containing one.
    $contentString = wordwrap($message, max(1, $this->window->getContentWidth()), "\n", true);
    return explode("\n", mb_substr($contentString, 0, $this->currentCharacterIndex));
  }

  /** @return string[] Message lines wrapped to the terminal content width. */
  protected function wrapMessageIntoLines(string $message, int $contentWidth): array
  {
    $lines = [];

    foreach (explode("\n", $message) as $paragraph) {
      foreach (explode("\n", wordwrap($paragraph, max(1, $contentWidth), "\n", true)) as $line) {
        $lines[] = $line;
      }
    }

    return $lines ?: [''];
  }

  /** Returns the already-wrapped text for the active dialogue page. */
  protected function currentPageMessage(): string
  {
    return $this->messagePages[$this->currentPageIndex] ?? '';
  }
}
