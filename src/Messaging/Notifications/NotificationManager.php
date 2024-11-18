<?php

namespace Ichiloto\Engine\Messaging\Notifications;

use Assegai\Collections\Queue;
use Ichiloto\Engine\Core\Interfaces\CanRender;
use Ichiloto\Engine\Core\Interfaces\CanResume;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Core\Interfaces\SingletonInterface;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;

/**
 * Class NotificationManager. Handles notifications.
 *
 * @package Ichiloto\Engine\Messaging\Notifications
 */
class NotificationManager implements CanUpdate, CanResume, CanRender, SingletonInterface
{
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
  protected int $leftMargin = 0;
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

  /**
   * NotificationManager constructor.
   */
  private function __construct()
  {
    $this->notifications = new Queue(NotificationInterface::class);

    $this->eventManager = EventManager::getInstance();

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
   * @inheritDoc
   */
  public static function getInstance(): static
  {
    if (self::$instance === null)
    {
      self::$instance = new NotificationManager();
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
    $this->notifications->enqueue($notification);
    $this->openActiveNotification();
  }

  /**
   * @inheritDoc
   */
  public function render(?int $x = null, ?int $y = null): void
  {
    $this->getActiveNotification()?->render($x + $this->leftMargin, $y + $this->topMargin);
  }

  /**
   * @inheritDoc
   */
  public function erase(?int $x = null, ?int $y = null): void
  {
    $this->getActiveNotification()?->erase($x + $this->leftMargin, $y + $this->topMargin);
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
    $notificationsQueueIsNotEmpty = $this->notifications->isNotEmpty();

    if (Time::getTime() >= $this->nextNotificationShowTime)
    {
      if ($notificationsQueueIsNotEmpty)
      {
        $this->dismissActiveNotification();
        $this->openActiveNotification();
      }
    }

    $this->getActiveNotification()?->update();
  }

  /**
   * Gets the active notification.
   *
   * @return NotificationInterface|null Returns the active notification.
   */
  protected function getActiveNotification(): ?NotificationInterface
  {
    return $this->notifications->peek();
  }

  /**
   * Opens the active notification.
   *
   * @return void
   */
  protected function openActiveNotification(): void
  {
    $this->getActiveNotification()?->open();
    $this->nextNotificationShowTime = Time::getTime() + $this->getActiveNotification()?->getDuration() ?? 0;
  }

  /**
   * Dismisses the active notification.
   *
   * @return void
   */
  protected function dismissActiveNotification(): void
  {
    $this->getActiveNotification()?->dismiss();
    $this->notifications->dequeue();
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