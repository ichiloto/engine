<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Events\ObservableTrait;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Interfaces\LayeredPresentationInterface;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\SelectionStyle;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\WindowAlignment;
use Ichiloto\Engine\UI\Windows\WindowPadding;
use Ichiloto\Engine\Util\Debug;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The SelectModal class.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class SelectModal implements ModalInterface, LayeredPresentationInterface
{
  use ObservableTrait;

  /**
   * @var string[] $options The options.
   */
  protected array $options;
  /**
   * @var int $activeOptionIndex The active index.
   */
  protected int $activeOptionIndex = 0;
  /**
   * @var int $totalOptions The total options.
   */
  protected int $totalOptions = 0;
  /**
   * @var int $optionsOffset The options offset.
   */
  protected int $optionsOffset = 0;
  /**
   * @var string $title The title.
   */
  public string $title {
    get {
      return $this->title;
    }
    set {
      $this->title = $value;
      $this->titleLength = TerminalText::displayWidth($value);
    }
  }
  /**
   * @var string|null $help The help text.
   */
  protected ?string $help = 'c:cancel';
  /**
   * @var int $value The value.
   */
  protected int $value = -1;
  /**
   * @var bool $isShowing Whether the modal is showing.
   */
  protected bool $isShowing = false;
  /**
   * @var ItemList<ObserverInterface> $observers The observers.
   */
  protected ItemList $observers;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * @var int $width The width.
   */
  protected int $titleLength = 0;
  /**
   * @var int $width The width.
   */
  protected int $messageLength = 0;
  /**
   * @var int $width The width.
   */
  protected int $helpLength = 0;
  /**
   * @var string[] $buttons The buttons.
   */
  public array $buttons {
    get {
      return $this->buttons;
    }
    set {
      $this->buttons = $value;
    }
  }
  /**
   * @var string $activeButton The active button.
   */
  public string $activeButton {
    get {
      return $this->activeButton;
    }
  }
  /**
   * @var OutputInterface $output The output.
   */
  protected OutputInterface $output;
  /** @var Window The canonical render and erase footprint. */
  protected Window $window;
  /**
   * @var string[] $messageLines The message lines.
   */
  protected array $messageLines = [];
  /**
   * @var int $messageContentHeight The message content height.
   */
  protected int $messageContentHeight = 0;

  /**
   * Constructs a new instance of SelectModal.
   *
   * @param string $message The message.
   * @param string[] $options The options.
   * @param string $title The title.
   * @param int $default The default option.
   * @param Rect $rect The rect.
   * @param string|null $help The help text. Defaults to 'c:cancel'.
   * @param BorderPackInterface $borderPack The border pack. Defaults to DefaultBorderPack.
   */
  public function __construct(
    protected Game                $game,
    public string                 $message,
    array                         $options,
    string                        $title = '',
    protected int                 $default = 0,
    protected Rect                $rect = new Rect(0, 0, DEFAULT_DIALOG_WIDTH, 3),
    ?string                       $help = 'c:cancel',
    protected BorderPackInterface $borderPack = new DefaultBorderPack()
  )
  {
    $this->observers = new ItemList(ObserverInterface::class);
    $this->eventManager = EventManager::getInstance($game);
    $this->setOptions($options);
    $this->title = $title;
    $this->setHelp($help);
    $this->output = new ConsoleOutput();
    $this->wrapMessage();
    $this->rebuildWindow();
  }

  /**
   * Wraps the message to the modal's inner width.
   *
   * Splitting on newlines alone cuts a long prompt off mid sentence, and the
   * box's height derives from the line count, so wrapping is also what makes
   * it grow to fit.
   *
   * @return void
   */
  protected function wrapMessage(): void
  {
    $innerWidth = max(1, $this->rect->getWidth() - 2);
    $lines = [];

    foreach (explode("\n", $this->message) as $paragraph) {
      foreach (explode("\n", wordwrap($paragraph, $innerWidth, "\n", true)) as $line) {
        $lines[] = $line;
      }
    }

    $this->messageLines = $lines;
    $this->messageContentHeight = count($lines);
  }

  /**
   * Returns the options.
   *
   * @return string[] The options.
   */
  public function getOptions(): array
  {
    return $this->options;
  }

  /**
   * Sets the options.
   *
   * @param array $options The options.
   * @return void
   */
  public function setOptions(array $options): void
  {
    $this->options = $options;
    $totalOptions = 0;
    foreach ($this->options as $option) {
      $totalOptions++;
      $this->rect->setWidth(max($this->rect->getWidth(), TerminalText::displayWidth($option) + 6));
    }
    $this->totalOptions = $totalOptions;

    if (isset($this->window)) {
      $this->rebuildWindow();
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $v = Input::getAxis(AxisName::VERTICAL);

    if (abs($v) > 0) {
      if ($v > 0) {
        $this->activeOptionIndex = wrap($this->activeOptionIndex + 1, 0, $this->totalOptions - 1);
      } else {
        $this->activeOptionIndex = wrap($this->activeOptionIndex - 1, 0, $this->totalOptions - 1);
      }
      $this->playInteractionSound(SystemSound::CURSOR);
      $this->render();
    }

    if (Input::isButtonDown("confirm")) {
      $this->playInteractionSound(SystemSound::CONFIRM);
      $this->value = $this->activeOptionIndex;
      $this->hide();
    } else if (Input::isAnyKeyPressed([KeyCode::C, KeyCode::c])) {
      $this->cancel();
    }
  }

  /**
   * Returns the height of the options.
   *
   * @return int The height of the options.
   */
  protected function getOptionsHeight(): int
  {
    if ($this->message) {
      return $this->messageContentHeight + $this->totalOptions + 2;
    }

    return $this->totalOptions + 1;
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $this->window->setTitle($this->title);
    $this->window->setHelp($this->help ?? '');
    $this->window->setContent($this->renderedOptionLines());
    $this->window->render($x === null ? null : $x + 1, $y === null ? null : $y + 1);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->window->erase($x === null ? null : $x + 1, $y === null ? null : $y + 1);
  }

  /**
   * @inheritDoc
   */
  public function show(): void
  {
    $this->isShowing = true;
    $this->getUIManager()?->present($this);
    $this->render();
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::SHOW, true));
  }

  /**
   * @inheritDoc
   */
  public function hide(): void
  {
    if ($this->isShowing) {
      $this->erase();
      $this->isShowing = false;
      $this->getUIManager()?->dismiss($this);
      $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::HIDE, true));
    }
  }

  /** @inheritDoc */
  public function getPresentationBounds(): Rect
  {
    return new Rect(
      $this->rect->getX(),
      $this->rect->getY(),
      $this->rect->getWidth(),
      $this->rect->getHeight(),
    );
  }

  /** @inheritDoc */
  public function getPresentationPriority(): PresentationPriority
  {
    return PresentationPriority::MODAL;
  }

  /** Returns the active scene's UI manager when the game is fully booted. */
  protected function getUIManager(): ?UIManager
  {
    if (! isset($this->game->sceneManager)) {
      return null;
    }

    return $this->game->sceneManager->currentScene?->getUI();
  }

  /**
   * @inheritDoc
   */
  public function open(): int
  {
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::OPEN, true));
    $this->show();
    $sleepTime = (int)(1000000 / 60);

    while ($this->isShowing) {
      $this->handleInput();
      $this->update();

      usleep($sleepTime);
    }

    return $this->close();
  }

  /**
   * @inheritDoc
   */
  public function close(): int
  {
    $this->hide();
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::CLOSE, true));
    return $this->value;
  }

  /** @inheritDoc */
  public function isShowing(): bool
  {
    return $this->isShowing;
  }

  /**
   * Returns the length of the title.
   *
   * @return int The length of the title.
   */
  protected function getTitleLength(): int
  {
    return $this->titleLength;
  }

  /**
   * @inheritDoc
   */
  public function getContent(): string
  {
    return $this->message . sprintf("\n\n%s", implode("\n", $this->options));
  }

  /**
   * @inheritDoc
   */
  public function setContent(string $content): void
  {
    $this->message = $content;
    $this->messageLength = TerminalText::displayWidth($this->message);
    $this->wrapMessage();
    $this->rebuildWindow();
  }

  public function getHelp(): string
  {
    return $this->help;
  }

  public function setHelp(string $help): void
  {
    $this->help = $help;
    $this->helpLength = TerminalText::displayWidth($this->help);

    if (isset($this->window)) {
      $this->window->setHelp($help);
    }
  }

  public function getHelpLength(): int
  {
    return $this->helpLength;
  }

  /**
   * @inheritDoc
   */
  public function getButtons(): array
  {
    return $this->options;
  }

  /**
   * @inheritDoc
   */
  public function setButtons(array $buttons): void
  {
    $this->setOptions($buttons);
  }

  /**
   * @inheritDoc
   */
  public function getActiveButton(): string
  {
    return $this->options[$this->activeOptionIndex];
  }

  /**
   * @inheritDoc
   */
  public function setActiveButton(int $activeButtonIndex): void
  {
    $this->activeOptionIndex = $activeButtonIndex;
  }

  /**
   * @inheritDoc
   */
  public function getActiveIndex(): int
  {
    return $this->activeOptionIndex;
  }

  /**
   * @inheritDoc
   */
  public function getValue(): int
  {
    return $this->value;
  }

  /**
   * Handles the input.
   *
   * @return void
   */
  protected function handleInput(): void
  {
    InputManager::handleInput();
  }

  /**
   * Renders the top border.
   *
   * @param int $x The x-position of the top border.
   * @param int $y The y-position of the top border.
   * @return void
   */
  protected function renderTopBorder(int $x, int $y): void
  {
    $output = $this->borderPack->getTopLeftCorner();
    $output .= $this->borderPack->getHorizontalBorder();
    $output .= $this->title;
    $output .= str_repeat($this->borderPack->getHorizontalBorder(), $this->rect->getWidth() - 3 - $this->titleLength);
    $output .= $this->borderPack->getTopRightCorner();

    Console::cursor()->moveTo($x + 1, $y + 1);
    $this->output->write($output);
  }

  /**
   * Renders the modal options.
   *
   * @param int $x The x-position of the options.
   * @param int $y The y-position of the top of the options.
   * @return void
   */
  protected function renderOptions(int $x, int $y): void
  {
    $topMargin = $y;
    $vSpacingSize = 0;
    $contentWidth = max(0, $this->rect->getWidth() - 2);
    $optionWidth = max(0, $contentWidth - 3);
    $blankLine = $this->borderPack->getVerticalBorder()
      . str_repeat(' ', $contentWidth)
      . $this->borderPack->getVerticalBorder();

    if ($this->message) {
      foreach ($this->messageLines as $lineIndex => $line) {
        $output = $this->borderPack->getVerticalBorder();
        $output .= TerminalText::padRight($line, $contentWidth);
        $output .= $this->borderPack->getVerticalBorder();
        Console::cursor()->moveTo($x + 1, $y + $lineIndex + 1);
        $this->output->write($output);
      }
      $vSpacingSize = 1;
      Console::cursor()->moveTo($x + 1, $y + $this->messageContentHeight + 1);
      $this->output->write($blankLine);
    }

    foreach ($this->options as $optionIndex => $option) {
      $output = $this->borderPack->getVerticalBorder();
      $prefix = $optionIndex === $this->activeOptionIndex ? '>' : ' ';
      $content = " {$prefix} " . TerminalText::padRight($option, $optionWidth);

      if ($optionIndex === $this->activeOptionIndex) {
        $content = SelectionStyle::apply($content);
      }
      $output .= $content;
      $output .= $this->borderPack->getVerticalBorder();
      Console::cursor()->moveTo($x + 1, $y + $this->messageContentHeight + $vSpacingSize + $optionIndex + 1);
      $this->output->write($output);
    }

    Console::cursor()->moveTo($x + 1, $y + $this->messageContentHeight + $vSpacingSize + $this->totalOptions + 1);
    $this->output->write($blankLine);
  }

  /**
   * Render the bottom border.
   *
   * @param int $x The x-position of the bottom border.
   * @param int $y The y-position of the bottom border.
   * @return void
   */
  protected function renderBottomBorder(int $x, int $y): void
  {
    $output = $this->borderPack->getBottomLeftCorner();
    $output .= $this->borderPack->getHorizontalBorder();
    $output .= $this->help;
    $output .= str_repeat($this->borderPack->getHorizontalBorder(), $this->rect->getWidth() - 3 - $this->helpLength);
    $output .= $this->borderPack->getBottomRightCorner();

    Console::cursor()->moveTo($x + 1, $y + 1);
    $this->output->write($output);
  }

  /**
   * Returns the total height of the modal.
   *
   * @return int The total height of the modal.
   */
  private function getModalHeight(): int
  {
    return $this->getOptionsHeight() + 2;
  }

  /** Rebuilds the window after message wrapping or option sizing changes. */
  protected function rebuildWindow(): void
  {
    $this->rect->setHeight($this->getModalHeight());
    $this->window = new Window(
      $this->title,
      $this->help ?? '',
      $this->rect->position,
      $this->rect->getWidth(),
      $this->rect->getHeight(),
      $this->borderPack,
      WindowAlignment::middleLeft(),
      new WindowPadding(),
    );
    $this->window->setContent($this->renderedOptionLines());
  }

  /** @return string[] The prompt, options, and spacing inside the box. */
  protected function renderedOptionLines(): array
  {
    $lines = [];

    if ($this->message !== '') {
      $lines = [...$this->messageLines, ''];
    }

    foreach ($this->options as $optionIndex => $option) {
      $prefix = $optionIndex === $this->activeOptionIndex ? '>' : ' ';
      $line = " {$prefix} {$option}";
      $lines[] = $optionIndex === $this->activeOptionIndex
        ? SelectionStyle::apply($line)
        : $line;
    }

    $lines[] = '';

    return $lines;
  }

  /**
   * Cancel the modal.
   *
   * @return void
   */
  protected function cancel(): void
  {
    $this->playInteractionSound(SystemSound::CANCEL);
    $this->value = -1;
    $this->hide();
  }

  /**
   * Plays a system sound for a modal interaction.
   *
   * @param SystemSound $sound The system sound to play.
   * @return void
   */
  protected function playInteractionSound(SystemSound $sound): void
  {
    $this->game->audioManager->playSystemSound($sound);
  }
}
