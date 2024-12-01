<?php

namespace Ichiloto\Engine\Scenes;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\Enumerations\NotificationEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Events\NotificationEvent;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Debug;

/**
 * Class AbstractScene. The abstract scene.
 *
 * @package Ichiloto\Engine\Scenes
 */
abstract class AbstractScene implements SceneInterface
{
  /**
   * @var bool Whether the scene has started.
   */
  protected bool $started = false;
  /**
   * @var GameObject[] The root game objects.
   */
  protected array $rootGameObjects = [];
  /**
   * @var Camera The camera of the scene.
   */
  protected(set) Camera $camera;
  /**
   * @var UIManager The UI manager.
   */
  protected(set) UIManager $uiManager;
  /**
   * @var EventManager The event manager.
   */
  protected(set) EventManager $eventManager;
  /**
   * @var mixed $modalEventHandler The modal event handler.
   */
  protected mixed $modalEventHandler = null;
  /**
   * @var mixed $notificationEventHandler The notification event handler.
   */
  protected mixed $notificationEventHandler = null;

  /**
   * AbstractScene constructor.
   *
   * @param SceneManager $sceneManager The scene manager.
   * @param string $name The name of the scene.
   */
  public function __construct(
    protected     (set) SceneManager $sceneManager,
    protected(set) string $name,
  )
  {
    $this->uiManager = UIManager::getInstance($this->sceneManager->game);
    $this->camera = new Camera($this, get_screen_width(), get_screen_height());
    $this->eventManager = EventManager::getInstance($this->sceneManager->game);
  }

  /**
   * @inheritDoc
   */
  public function getRootGameObjects(): array
  {
    return $this->rootGameObjects;
  }

  /**
   * @inheritDoc
   */
  public function getUI(): UIManager
  {
    return $this->uiManager;
  }

  /**
   * @inheritDoc
   */
  public function render(): void
  {
    $this->camera->render();
  }

  /**
   * @inheritDoc
   */
  public function erase(): void
  {
    $this->camera->erase();
  }

  /**
   * @inheritDoc
   */
  public function resume(): void
  {
//    $this->camera->resume();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive) {
        $gameObject->resume();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function suspend(): void
  {
    $this->camera->suspend();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive) {
        $gameObject->suspend();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function start(): void
  {
    $this->camera->start();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive) {
        $gameObject->start();
      }
    }

    $this->initializeEventHandlers();

    $this->started = true;
  }

  /**
   * @inheritDoc
   */
  public function stop(): void
  {
    $this->camera->stop();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive) {
        $gameObject->stop();
      }
    }

    $this->deregisterEventHandlers();

    $this->started = false;
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->camera->update();

    foreach ($this->rootGameObjects as $gameObject) {
      if ($gameObject->isActive) {
        $gameObject->update();
      }
    }
  }

  /**
   * @inheritDoc
   */
  public function isStarted(): bool
  {
    return $this->started;
  }

  /**
   * @inheritDoc
   */
  public function getGame(): Game
  {
    return $this->sceneManager->game;
  }

  /**
   * @inheritDoc
   */
  public function renderBackgroundTile(int $x, int $y): void
  {
    // Do nothing. This method is meant to be overridden.
  }

  /**
   * Initialize event handlers.
   *
   * @return void
   */
  protected function initializeEventHandlers(): void
  {
    $this->modalEventHandler = function (ModalEvent $event) {
      switch ($event->modalEventType) {
        case ModalEventType::OPEN:
          $this->suspend();
          break;

        case ModalEventType::CLOSE:
          $this->resume();
          break;

        case ModalEventType::HIDE:
        case ModalEventType::UPDATE:
        case ModalEventType::RENDER:
        case ModalEventType::ACTION:
        case ModalEventType::CONFIRM:
        case ModalEventType::SHOW:
        case ModalEventType::CANCEL:
          // Do nothing
          break;
      }
    };
    $this->eventManager->addEventListener(EventType::MODAL, $this->modalEventHandler);

    $this->notificationEventHandler = function (NotificationEvent $event) {
      switch ($event->notificationEventType) {
        case NotificationEventType::OPEN:
        case NotificationEventType::RESUME:
          $this->suspend();
          break;

        case NotificationEventType::DISMISS:
          $this->resume();
          break;

        case NotificationEventType::UPDATE:
        case NotificationEventType::RENDER:
        case NotificationEventType::SUSPEND:
        case NotificationEventType::ERASE:
          // Do nothing
          break;
      }
    };

    $this->eventManager->addEventListener(EventType::NOTIFICATION, $this->notificationEventHandler);
  }

  protected function deregisterEventHandlers(): void
  {
    $this->eventManager->removeEventListener(EventType::MODAL, $this->modalEventHandler);
    $this->eventManager->removeEventListener(EventType::NOTIFICATION, $this->notificationEventHandler);
  }
}