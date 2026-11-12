<?php

namespace Ichiloto\Engine\UI\Modal;

use Assegai\Collections\Stack;
use Throwable;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\UI\Interfaces\ModalInterface;
use Ichiloto\Engine\UI\Presentation\CreditsContent;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\UI\Presentation\PresentationDeliveryReceipt;

/**
 * Represents the modal manager.
 *
 * @package Ichiloto\Engine\UI\Modal
 */
class ModalManager implements CanUpdate, CanRender
{
  /**
   * @var Stack<ModalInterface> $modals The stack of modals.
   */
  protected Stack $modals;
  /** @var list<array{message: string, title: string, notice?: NoticeContent, owner?: object, delivery?: PresentationDeliveryReceipt}> */
  protected array $pendingAlerts = [];
  /** Ownership remains pending while a deferred notice is on screen. */
  private ?object $activeNoticeOwner = null;
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
  public function __construct(public readonly Game $game)
  {
    $this->modals = new Stack(ModalInterface::class);
  }

  /**
   * Returns the modal manager owned by this Game.
   *
   * @param Game $game The game instance.
   *
   * @return ModalManager The instance of the modal manager.
   */
  public static function getInstance(Game $game): self
  {
    return $game->getModalManager();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->currentModal?->update();
  }

  /** Producers may request attention without re-entering scene or modal input. */
  public function queueAlert(string $message, string $title = ''): void
  {
    $this->pendingAlerts[] = ['message' => $message, 'title' => $title];
  }

  /** A toast may transfer its receipt here when its complete text needs acknowledgement. */
  public function queueTrackedAlert(string $message, string $title = '', ?PresentationDeliveryReceipt $delivery = null): PresentationDeliveryReceipt
  {
    $delivery ??= new PresentationDeliveryReceipt();
    if (!$delivery->isTerminal) {
      $delivery->retryDelivery();
      $this->pendingAlerts[] = ['message' => $message, 'title' => $title, 'delivery' => $delivery];
    }
    return $delivery;
  }

  public function cancelDelivery(PresentationDeliveryReceipt $delivery): void
  {
    $delivery->cancel();
    $this->pendingAlerts = array_values(array_filter($this->pendingAlerts,
      static fn(array $entry): bool => ($entry['delivery'] ?? null) !== $delivery));
  }

  /** Notices and alerts preserve one producer order and never re-enter scene input. */
  public function queueNotice(NoticeContent $notice, ?object $owner = null): void
  {
    $this->pendingAlerts[] = ['message' => $notice->message, 'title' => $notice->title, 'notice' => $notice,
      ...($owner === null ? [] : ['owner' => $owner])];
  }

  /** Releases one scene's queued notices while preserving other producers. */
  public function cancelNotices(object $owner): void
  {
    $this->pendingAlerts = array_values(array_filter($this->pendingAlerts,
      static fn(array $entry): bool => ($entry['owner'] ?? null) !== $owner));
  }

  public function hasPendingNotices(object $owner): bool
  {
    return $this->activeNoticeOwner === $owner
      || array_any($this->pendingAlerts, static fn(array $entry): bool => ($entry['owner'] ?? null) === $owner);
  }

  /** Called only at a normal frame boundary, never from the blocked-frame pump. */
  public function processPendingAlerts(): void
  {
    if ($this->currentModal !== null || $this->pendingAlerts === []) { return; }
    // Cooperative dialogue and pause menus register with the scene's UI owner,
    // without entering the blocking modal stack. Defer alerts until that owner releases input.
    $scene = isset($this->game->sceneManager) ? $this->game->sceneManager->currentScene : null;
    if ($scene?->getUI()->hasActiveModal ?? false) {
      return;
    }
    $alert = array_shift($this->pendingAlerts);
    $delivery = $alert['delivery'] ?? null;
    if ($delivery !== null && !$delivery->beginDelivery()) { return; }
    $this->activeNoticeOwner = $alert['owner'] ?? null;
    try {
      if (isset($alert['notice'])) { $this->showPendingNotice($alert['notice']); }
      else { $this->showPendingAlert($alert['message'], $alert['title']); }
      if ($delivery !== null) {
        if ($this->game->hasStopped()) { $delivery->cancel(); }
        else { $delivery->completeDelivery(); }
      }
    }
    catch (\Throwable $error) {
      $delivery?->retryDelivery();
      if ($delivery === null || !$delivery->isTerminal) { array_unshift($this->pendingAlerts, $alert); }
      throw $error;
    }
    finally { $this->activeNoticeOwner = null; }
  }

  protected function showPendingAlert(string $message, string $title): void
  {
    $this->runModal(new PagedAlertModal($this->game, $message, $title));
  }

  protected function showPendingNotice(NoticeContent $notice): void
  {
    $this->runModal(new NoticeModal($this->game, $notice));
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
    $position = $this->modals->count();
    $this->modals->push($modal);
    try { $modal->show(); }
    catch (Throwable $error) {
      try { $modal->hide(); }
      catch (Throwable $cleanup) { Debug::warn('Modal show cleanup failed: ' . $cleanup->getMessage()); }
      finally { $this->releaseModal($modal, $position); }
      throw $error;
    }
  }

  /** One blocking owner; every exit releases only its own stack entry. */
  private function runModal(ModalInterface $modal): mixed
  {
    $position = $this->modals->count();
    $this->modals->push($modal);
    try { return $modal->open(); }
    finally { $this->releaseModal($modal, $position); }
  }

  /** Nested callbacks may leave another modal above this operation's entry. */
  private function releaseModal(ModalInterface $modal, int $position): void
  {
    $entries = $this->modals->toArray();
    if (($entries[$position] ?? null) !== $modal) { return; }
    array_splice($entries, $position, 1);
    $this->modals->clear();
    $this->modals->pushAll(...$entries);
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
    $this->runModal(new AlertModal($this->game, $message, $title, $width));
  }

  /** Keeps long credits bounded without turning them into character dialogue. */
  public function showCredits(CreditsContent $content): void
  {
    $this->runModal(new CreditsModal($this->game, $content));
  }

  /**
   * Displays a confirmation box with the specified message and an OK and Cancel button.
   *
   * @param string $message The message to display.
   * @param string $title The title of the alert.
   * @param int $width The width of the alert.
   * @param string $confirmLabel The confirming button's label, such as "Yes".
   * @param string $cancelLabel The declining button's label, such as "No".
   * @return bool The result of the confirmation.
   */
  public function confirm(string $message, string $title = '', int $width = DEFAULT_DIALOG_WIDTH,
    string $confirmLabel = 'OK', string $cancelLabel = 'Cancel'): bool
  {
    return (bool)$this->runModal(new ConfirmModal($this->game, $message, $title, $width, $confirmLabel, $cancelLabel));
  }

  /** Selects a bounded amount without performing the caller's action. */
  public function selectQuantity(string $message, int $maximum, string $title = '', int $initial = 1,
    int $width = DEFAULT_DIALOG_WIDTH): ?int
  {
    return $this->runModal(new QuantityModal($this->game, $message, $maximum, $title, $initial, $width));
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
    return $this->runModal($modal);
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
    int $width = DEFAULT_SELECT_DIALOG_WIDTH,
    int $height = DEFAULT_SELECT_DIALOG_HEIGHT
  ): int
  {
    $position = $position ?? new Vector2(intval((get_screen_width() - $width) / 2), intval((get_screen_height() - DEFAULT_DIALOG_HEIGHT) / 2));
    return (int)$this->runModal(new SelectModal(
      $this->game,
      $message,
      $options,
      $title,
      $default,
      new Rect(
        $position->x,
        $position->y,
        $width,
        $height
      )
    ));
  }

  /**
   * Displays a dialog box with a message.
   *
   * @param string $message The message to display.
   * @param string $title The title of the dialog box.
   * @param string $help The help text to display.
   * @param WindowPosition|null $position Explicit position, or the narration/speech default.
   * @param float $charactersPerSecond The number of characters to display per second.
   * @return void
   */
  public function showText(
    string $message,
    string $title = '',
    string $help = '',
    ?WindowPosition $position = null,
    float $charactersPerSecond = 1,
    ?\Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback $playback = null,
    ?\Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext $presentation = null,
  ): void
  {
    $modal = new TextBoxModal($this->game, $message, $title, $help, $position, charactersPerSecond: $charactersPerSecond,
      playback: $playback, presentation: $presentation ?? new \Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext());
    try { $this->runModal($modal); }
    finally { $playback?->finishLine(); }
  }
}
