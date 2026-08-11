<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\ModalEventPresentation;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Modal\Modal;

final class FieldRestorationModalProbe extends Modal
{
  /** @var string[] */
  public array $timeline = [];

  public function __construct(EventManager $eventManager)
  {
    $this->eventManager = $eventManager;
  }

  public function hide(): void
  {
    $this->timeline[] = 'overlay erased';
    $this->isShowing = false;
  }

  public function recordSceneResume(): void
  {
    $this->timeline[] = 'scene resumed';
  }
}

final class FieldRestorationGameSceneProbe extends GameScene
{
  public int $fieldRestorations = 0;

  public function __construct()
  {
  }

  public function restoreFieldAfterOverlay(): void
  {
    $this->fieldRestorations++;
  }
}

it('erases a blocking modal before resuming and redrawing the scene', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $eventManager = EventManager::getInstance($game);
  $modal = new FieldRestorationModalProbe($eventManager);
  $listener = static function (ModalEvent $event) use ($modal): void {
    if ($event->modalEventType === ModalEventType::CLOSE) {
      $modal->recordSceneResume();
    }
  };
  $eventManager->addEventListener(EventType::MODAL, $listener);

  try {
    $modal->close();
  } finally {
    $eventManager->removeEventListener(EventType::MODAL, $listener);
  }

  expect($modal->timeline)->toBe(['overlay erased', 'scene resumed']);
});

it('restores the field after non-blocking story dialogue is dismissed', function () {
  $game = (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
  $modal = new FieldRestorationModalProbe(EventManager::getInstance($game));
  $scene = new FieldRestorationGameSceneProbe();
  $presentation = new ModalEventPresentation($scene);
  $modalProperty = (new ReflectionClass(ModalEventPresentation::class))->getProperty('modal');
  $modalProperty->setValue($presentation, $modal);

  $presentation->reset();

  expect($scene->fieldRestorations)->toBe(1)
    ->and($modalProperty->getValue($presentation))->toBeNull();
});
