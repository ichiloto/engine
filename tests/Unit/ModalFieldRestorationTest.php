<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\Enumerations\EventType;
use Ichiloto\Engine\Events\Enumerations\ModalEventType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\ModalEventPresentation;
use Ichiloto\Engine\Events\ModalEvent;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\UI\Modal\Modal;
use Ichiloto\Engine\UI\UIManager;
use Assegai\Collections\ItemList;
use Ichiloto\Engine\UI\Interfaces\UIElementInterface;

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

final class FieldCompositionMapProbe extends MapManager
{
  public int $renderCount = 0;

  public function __construct()
  {
  }

  public function render(?int $x = null, ?int $y = null): void
  {
    $this->renderCount++;
  }
}

final class FieldCompositionNpcProbe extends NpcManager
{
  public int $renderCount = 0;

  public function __construct()
  {
  }

  public function render(): void
  {
    $this->renderCount++;
  }
}

final class FieldCompositionPlayerProbe extends Player
{
  public int $renderCount = 0;
  public int $cueRenderCount = 0;
  public int $reconcileCount = 0;

  public function __construct()
  {
  }

  public function render(): void
  {
    $this->renderCount++;
  }

  public function renderEventCues(): void
  {
    $this->cueRenderCount++;
  }

  public function reconcileActiveEventState(): void
  {
    $this->reconcileCount++;
  }
}

final class FieldCompositionHudProbe extends LocationHUDWindow
{
  public int $renderCount = 0;

  public function __construct()
  {
  }

  public function render(?int $x = null, ?int $y = null): void
  {
    $this->renderCount++;
  }

  public function isPresentationVisible(): bool
  {
    return true;
  }
}

final class FieldCompositionSceneProbe extends GameScene
{
  public function __construct(
    MapManager $mapManager,
    NpcManager $npcManager,
    Player $player,
    LocationHUDWindow $hud,
  )
  {
    $hud->activate();
    $uiManager = (new ReflectionClass(UIManager::class))->newInstanceWithoutConstructor();
    $uiManager->locationHUDWindow = $hud;
    $elements = new ItemList(UIElementInterface::class);
    $elements->add($hud);
    $uiElements = (new ReflectionClass(UIManager::class))->getProperty('uiElements');
    $uiElements->setValue($uiManager, $elements);
    $this->uiManager = $uiManager;
    $this->mapManager = $mapManager;
    $this->npcManager = $npcManager;
    $this->player = $player;
    $this->fieldState = new FieldState(new SceneStateContext($this));
    $this->state = $this->fieldState;
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

it('composites map NPCs player and HUD on every field restoration', function () {
  $map = new FieldCompositionMapProbe();
  $npcs = new FieldCompositionNpcProbe();
  $player = new FieldCompositionPlayerProbe();
  $hud = new FieldCompositionHudProbe();
  $scene = new FieldCompositionSceneProbe($map, $npcs, $player, $hud);
  $state = new FieldState(new SceneStateContext($scene));

  $state->renderTheField();

  expect($map->renderCount)->toBe(1)
    ->and($player->cueRenderCount)->toBe(1)
    ->and($npcs->renderCount)->toBe(1)
    ->and($player->renderCount)->toBe(1)
    ->and($hud->renderCount)->toBe(1);
});

it('recomposites every field layer after the camera scrolls', function () {
  $map = new FieldCompositionMapProbe();
  $npcs = new FieldCompositionNpcProbe();
  $player = new FieldCompositionPlayerProbe();
  $hud = new FieldCompositionHudProbe();
  $scene = new FieldCompositionSceneProbe($map, $npcs, $player, $hud);

  expect($scene->recomposeFieldAfterCameraScroll())->toBeTrue()
    ->and($map->renderCount)->toBe(1)
    ->and($player->cueRenderCount)->toBe(1)
    ->and($npcs->renderCount)->toBe(1)
    ->and($player->renderCount)->toBe(1)
    ->and($hud->renderCount)->toBe(1);
});

it('rebuilds dynamic field layers once when world state makes them dirty', function () {
  $map = new FieldCompositionMapProbe();
  $npcs = new FieldCompositionNpcProbe();
  $player = new FieldCompositionPlayerProbe();
  $hud = new FieldCompositionHudProbe();
  $scene = new FieldCompositionSceneProbe($map, $npcs, $player, $hud);

  $scene->requestFieldPresentationReconciliation();
  $scene->reconcileFieldPresentation();
  $scene->reconcileFieldPresentation();

  expect($map->renderCount)->toBe(1)
    ->and($player->reconcileCount)->toBe(1)
    ->and($player->cueRenderCount)->toBe(1)
    ->and($npcs->renderCount)->toBe(1)
    ->and($player->renderCount)->toBe(1)
    ->and($hud->renderCount)->toBe(1);
});
