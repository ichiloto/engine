<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\ItemList;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Events\ObservableTrait;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Enumerations\AxisName;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\Input;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Windows\BorderPacks\DefaultBorderPack;
use Ichiloto\Engine\UI\Windows\Interfaces\BorderPackInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The SelectModal class.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class SelectModal implements ModalInterface
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
      $this->titleLength = strlen($value);
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
      $this->rect->setWidth(max($this->rect->getWidth(), strlen($option) + 6));
    }
    $this->totalOptions = $totalOptions;
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
    }

    if (Input::isButtonDown("confirm")) {
      $this->value = $this->activeOptionIndex;
      $this->close();
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
    return $this->totalOptions;
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $leftMargin = $this->rect->getX() + ($x ?? 0);
    $topMargin = $this->rect->getY() + ($y ?? 0);

    $this->erase($leftMargin, $topMargin);
    $this->renderTopBorder($leftMargin, $topMargin);
    $this->renderOptions($leftMargin, $topMargin + 1);
    $this->renderBottomBorder($leftMargin, $topMargin + $this->getModalHeight() - 1);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $leftMargin = $this->rect->getX() + ($x ?? 0);
    $topMargin = $this->rect->getY() + ($y ?? 0);
    $modalHeight = max($this->rect->getHeight(), $this->getModalHeight());

    for ($row = $topMargin; $row < $topMargin + $modalHeight; $row++) {
      Console::write(str_repeat(' ', $this->rect->getWidth()), $leftMargin, $row);
    }
  }

  /**
   * @inheritDoc
   */
  public function show(): void
  {
    $this->isShowing = true;
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::SHOW, true));
  }

  /**
   * @inheritDoc
   */
  public function hide(): void
  {
    $this->erase();
    $this->isShowing = false;
    $this->eventManager->dispatchEvent(new ModalEvent(ModalEventType::HIDE, true));
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
      $this->render();

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
    $this->messageLength = strlen($this->message);
  }

  public function getHelp(): string
  {
    return $this->help;
  }

  public function setHelp(string $help): void
  {
    $this->help = $help;
    $this->helpLength = strlen($this->help);
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

    Console::cursor()->moveTo($x, $y);
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
    $spacing = $this->rect->getWidth() - 5;

    if ($this->message) {
      // TODO: Render the message above the options and separate them with a line.
    }

    foreach ($this->options as $optionIndex => $option) {
      $output = $this->borderPack->getVerticalBorder();
      $content = sprintf(" %s %-{$spacing}s", $optionIndex === $this->activeOptionIndex ? '>' : ' ', $option);

      if ($optionIndex === $this->activeOptionIndex) {
        $content = Color::apply($content, Color::LIGHT_BLUE);
      }
      $output .= $content;
      $output .= $this->borderPack->getVerticalBorder();
      Console::cursor()->moveTo($x, $y + $optionIndex);
      $this->output->write($output);
    }
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

    Console::cursor()->moveTo($x, $y);
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

  /**
   * Cancel the modal.
   *
   * @return void
   */
  protected function cancel(): void
  {
    $this->value = -1;
    $this->hide();
  }
}