<?php

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\GraphicalNotificationInterface;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationCanvasPresentation;
use Ichiloto\Engine\Messaging\Notifications\Presentation\NotificationContentOverflow;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Throwable;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;

use Assegai\Collections\Queue;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\MapEventType;
use Ichiloto\Engine\Events\Enumerations\SceneEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\MapEvent;
use Ichiloto\Engine\Events\SceneEvent;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;
use Ichiloto\Engine\Util\Debug;

/**
 * Class NotificationManager. Handles notifications.
 *
 * @package Ichiloto\Engine\Messaging\Notifications
 */
class NotificationManager implements CanUpdate, CanResume, CanRender
{
  /**
   * Frame deltas above this are engine-time stalls (blocking dialogue,
   * alerts, shops), not ordinary frames.
   */
  protected const float STALL_COMPENSATION_THRESHOLD_SECONDS = 0.5;
  /**
   * @var NotificationManager|null The instance of the notification manager.
   */
  protected static ?NotificationManager $instance = null;
  /**
   * @var Queue<NotificationInterface>
   */
  protected Queue $notifications;
  /**
   * @var float The time to show the next notification.
   */
  protected float $nextNotificationShowTime = 0;
  /**
   * @var int The left margin.
   */
  protected int $leftMargin = 1;
  /**
   * @var int The top margin.
   */
  protected int $topMargin = 1;
  /**
   * @var mixed $sceneEventHandler The scene event handler.
   */
  protected mixed $sceneEventHandler = null;
  /**
   * @var EventManager $eventManager The event manager.
   */
  protected EventManager $eventManager;
  /**
   * @var mixed $mapEventHandler The map event handler.
   */
  protected mixed $mapEventHandler = null;
  private ?MenuPresentationCatalog $presentationTheme = null;
  private bool $graphicalPresentation = false;
  private bool $presentationDeferred = false;
  private float $lastPresentationUpdate = 0;
  private ?CanvasRectangle $presentationAnchor = null;
  private array $presentationDiagnostics = [];

  /**
   * NotificationManager constructor.
   */
  private function __construct(protected Game $game)
  {
    $this->notifications = new Queue(NotificationInterface::class);

    $this->eventManager = EventManager::getInstance($game);

    $this->initializeEventHandlers();
    $this->eventManager->addEventListener(EventType::SCENE, $this->sceneEventHandler);
    $this->eventManager->addEventListener(EventType::MAP, $this->mapEventHandler);
  }

  /**
   * Destroys the notification manager.
   */
  public function __destruct()
  {
    $this->eventManager->removeEventListener(EventType::SCENE, $this->sceneEventHandler);
    $this->eventManager->removeEventListener(EventType::MAP, $this->mapEventHandler);
    $this->finalizeEventHandlers();
  }

  /**
   * Returns the instance of the notification manager.
   *
   * @param Game $game The game.
   */
  public static function getInstance(Game $game): static
  {
    if (self::$instance === null) {
      self::$instance = new NotificationManager($game);
    }

    return self::$instance;
  }

  /**
   * Notifies the user.
   *
   * @param NotificationInterface $notification The notification to show.
   * @return void
   */
  public function notify(NotificationInterface $notification): void
  {
    $queueWasEmpty = ! $this->notifications->isNotEmpty();
    $this->notifications->enqueue($notification);

    // A notification can arrive while the player is looking elsewhere on
    // screen, so it says so.
    play_sound(SystemSound::NOTIFICATION);

    if ($queueWasEmpty) {
      $this->openActiveNotification();
    }
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    if ($this->graphicalPresentation) { return; }
    Console::withLayer('notifications', fn() => $this->getActiveNotification()?->render(
      ($x ?? 0) + $this->leftMargin,
      ($y ?? 0) + $this->topMargin
    ), PresentationLayerPolicy::NOTIFICATIONS);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->getActiveNotification()?->erase(
      ($x ?? 0) + $this->leftMargin,
      ($y ?? 0) + $this->topMargin
    );
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
    $this->getActiveNotification()?->resume();
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->getActiveNotification()?->suspend();
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $activeNotification = $this->getActiveNotification();

    if (! $activeNotification instanceof NotificationInterface) {
      return;
    }

    $now = Time::getTime();
    $elapsed = max(0, $now - $this->lastPresentationUpdate);
    $this->lastPresentationUpdate = $now;
    if ($this->graphicalPresentation && $this->presentationDeferred) {
      $this->nextNotificationShowTime += $elapsed;
      if ($activeNotification instanceof GraphicalNotificationInterface) { $activeNotification->delayPresentation($elapsed); }
      return;
    }

    // Blocking UI (dialogue, alerts, shops) freezes engine time and then
    // releases it in one large jump. A notification issued during the stall
    // gets its deadline stamped with the frozen clock, so the jump would
    // consume its whole on-screen time at once; push the deadline out by the
    // stall instead.
    $timeJump = Time::getDeltaTime();

    if ($timeJump > self::STALL_COMPENSATION_THRESHOLD_SECONDS) {
      $this->nextNotificationShowTime += $timeJump;
    }

    if (Time::getTime() >= $this->nextNotificationShowTime) {
      $activeNotification->dismiss();
    }

    $activeNotification->update();

    if ($activeNotification->isFinished()) {
      $this->notifications->dequeue();
      $this->openActiveNotification();
    }
  }

  /**
   * Gets the active notification.
   *
   * @return NotificationInterface|null Returns the active notification.
   */
  protected function getActiveNotification(): ?NotificationInterface
  {
    return isset($this->notifications) ? $this->notifications->peek() : null;
  }

  public function hasGraphicalPresentation(): bool
  {
    return $this->graphicalPresentation && $this->getActiveNotification() !== null;
  }

  /** Renderer exclusions do not erase the terminal buffer or change other overlay owners. */
  public function getExcludedPresentationLayers(): array
  {
    $notice = $this->getActiveNotification();
    return $this->graphicalPresentation && $notice instanceof GraphicalNotificationInterface
      ? [$notice->getPresentationId()] : [];
  }

  /** PHP queue ownership is retained; an unsafe area pauses delivery rather than consuming a notice unseen. */
  public function composePresentation(?PresentationCanvas $base, int $width, int $height, array $protected): ?PresentationCanvas
  {
    $notice = $this->getActiveNotification();
    if ($notice === null) { return $base; }
    if (!$this->graphicalPresentation || !$notice instanceof GraphicalNotificationInterface || $this->presentationTheme === null) {
      // An opaque graphical menu must not conceal the existing terminal fallback notification.
      return null;
    }
    try {
      $surface = NotificationCanvasPresentation::compose($notice, $this->presentationTheme,
        $width, $height, $protected, $this->presentationAnchor);
      $this->presentationDeferred = $surface === null;
      if ($surface === null) { return $base; }
      $this->presentationAnchor = $surface->bounds;
      return $base === null ? $surface->canvas : MenuCanvas::overlay($base, $surface->canvas, $this->presentationTheme);
    } catch (Throwable $error) {
      $this->reportPresentationProblem($error->getMessage());
      if ($error instanceof NotificationContentOverflow) {
        $this->presentationDeferred = true;
        return $base;
      }
      $this->graphicalPresentation = false;
      $this->presentationDeferred = false;
      return null;
    }
  }

  private function reportPresentationProblem(string $message): void
  {
    if (!isset($this->presentationDiagnostics[$message])) {
      Debug::warn('Notification presentation: ' . $message);
      $this->presentationDiagnostics[$message] = true;
    }
  }

  /**
   * Opens the active notification.
   *
   * @return void
   */
  protected function openActiveNotification(): void
  {
    $notification = $this->getActiveNotification();

    if (! $notification instanceof NotificationInterface) {
      $this->graphicalPresentation = false;
      return;
    }

    $this->graphicalPresentation = false;
    $this->presentationDeferred = false;
    $this->presentationAnchor = null;
    $this->lastPresentationUpdate = Time::getTime();
    $runtime = $this->game->getRendererRuntime();
    if ($notification instanceof GraphicalNotificationInterface && $runtime !== null
      && array_all([...MenuPresentationCatalog::CAPABILITIES, RendererSessionConfig::CANVAS_OVERLAY], $runtime->supports(...))) {
      try {
        $this->presentationTheme ??= MenuPresentationCatalog::load($runtime->getAssetRoot());
        $this->graphicalPresentation = $this->presentationTheme !== null;
        $this->presentationDeferred = $this->graphicalPresentation;
      } catch (Throwable $error) { $this->reportPresentationProblem($error->getMessage()); }
    }
    $notification->open();
    $this->nextNotificationShowTime = Time::getTime() + $notification->getAnimationDuration() + $notification->getDuration();
  }

  /**
   * Dismisses the active notification.
   *
   * @return void
   */
  protected function dismissActiveNotification(): void
  {
    $this->getActiveNotification()?->dismiss();
  }

  /**
   * Initializes the event handlers.
   *
   * @return void
   */
  private function initializeEventHandlers(): void
  {
    $this->sceneEventHandler = function (SceneEvent $event) {
      if ($event->sceneEventType === SceneEventType::LOAD_END) {
        $this->resume();
      }
    };

    $this->mapEventHandler = function (MapEvent $event) {
      if ($event->mapEventType === MapEventType::LOAD) {
        $this->resume();
      }
    };
  }

  /**
   * Finalizes the event handlers.
   *
   * @return void
   */
  private function finalizeEventHandlers(): void
  {
    $this->sceneEventHandler = null;
    $this->mapEventHandler = null;
  }
}
