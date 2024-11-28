<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\Stack;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;

class ModalManager implements CanUpdate, CanRender
{
  /**
   * @var ModalManager|null $instance The instance of the modal manager.
   */
  protected static ?self $instance = null;
  /**
   * @var Stack<ModalInterface> $modals The stack of modals.
   */
  protected Stack $modals;
  /**
   * @var ModalInterface|null $currentModal The current modal.
   */
  public ?ModalInterface $currentModal {
    get {
      return $this->modals->peek();
    }
  }

  /**
   * Constructs a new instance of the modal manager.
   *
   * @param Game $game The game instance.
   */
  private function __construct(protected Game $game)
  {
    $this->modals = new Stack(ModalInterface::class);
  }

  /**
   * Returns the singleton instance of the modal manager.
   *
   * @param Game $game The game instance.
   *
   * @return ModalManager The instance of the modal manager.
   */
  public static function getInstance(Game $game): self
  {
    if (!self::$instance) {
      self::$instance = new self($game);
    }

    return self::$instance;
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->currentModal?->update();
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->currentModal?->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->currentModal?->erase();
  }

  /**
   * Opens a modal.
   *
   * @param ModalInterface $modal The modal to open.
   * @return void
   */
  public function open(ModalInterface $modal): void
  {
    $this->modals->push($modal);
    $modal->show();
  }

  /**
   * Displays an alert box with the specified message and an OK button.
   *
   * @param string $message The message to display.
   * @param string $title The title of the alert.
   * @param int $width The width of the alert.
   * @return void
   */
  public function alert(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): void
  {
    $this->modals->push(new AlertModal($this->game, $message, $title, $width));
    $this->modals->peek()->open();
    $this->modals->pop();
  }

  /**
   * Displays a confirmation box with the specified message and an OK and Cancel button.
   *
   * @param string $message The message to display.
   * @param string $title The title of the alert.
   * @param int $width The width of the alert.
   * @return bool The result of the confirmation.
   */
  public function confirm(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH): bool
  {
    $this->modals->push(new ConfirmModal($this->game, $message, $title, $width));
    $result = $this->modals->peek()->open();
    $this->modals->pop();

    return (bool)$result;
  }

  /**
   * Displays a dialog box that prompts the user for input with specified message and an OK and Cancel button.
   *
   * @param string $message The message to display.
   * @param string $title The title of the alert.
   * @param string $default The default value of the prompt.
   * @param int $width The width of the alert.
   * @return string The result of the prompt.
   */
  public function prompt(string $message, string $title = '', string $default = '', int $width = DEFAULT_DIALOG_WIDTH): string
  {
    $modal = new PromptModal($this->game, $message, $title, $default, $width);
    $modal->message = $message;
    $this->modals->push($modal);

    return '';
  }

  /**
   * Displays a dialog box that prompts the user to select an option from a list of options.
   *
   * @param string $message The message to display.
   * @param array $options The options to display.
   * @param string $title The title of the dialog box.
   * @param int $default The default option.
   * @param Vector2|null $position
   * @param int $width The width of the dialog box.
   * @return int
   */
  public function select(
    string $message,
    array $options,
    string $title = '',
    int $default = 0,
    ?Vector2 $position = null,
    int $width = DEFAULT_SELECT_DIALOG_WIDTH
  ): int
  {
    $position = $position ?? new Vector2(0, 0);
    $this->modals->push(new SelectModal(
      $this->game,
      $message,
      $options,
      $title,
      $default,
      new Rect(
        $position->x,
        $position->y,
        $width,
        DEFAULT_DIALOG_HEIGHT
      )
    ));
    $result = $this->modals->peek()->open();
    $this->modals->pop();

    return (int)$result;
  }

  /**
   * Displays a dialog box with a message.
   *
   * @param string $message The message to display.
   * @param string $title The title of the dialog box.
   * @param string $help The help text to display.
   * @param WindowPosition $position The position of the dialog box.
   * @param float $charactersPerSecond The number of characters to display per second.
   * @return void
   */
  public function showText(
    string $message,
    string $title = '',
    string $help = '',
    WindowPosition $position = WindowPosition::BOTTOM,
    float $charactersPerSecond = 1
  ): void
  {
    $this->modals->push(new TextBoxModal($this->game, $message, $title, $help, $position, charactersPerSecond: $charactersPerSecond));
    $this->modals->peek()->open();
    $this->modals->pop();
  }
}