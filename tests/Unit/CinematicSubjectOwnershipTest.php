<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionLane;
use Ichiloto\Engine\Events\Interpreter\EventCommandResult;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ConfigStore;
use function Tests\Support\Rendering\spriteSheetData;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class SubjectOwnershipMap extends MapManager
{
  public bool $blocked = false;
  public ?\Closure $onLoad = null;
  public function __construct() {}
  public function loadMap(string $filename, Player $player): self
  {
    ($this->onLoad)?->__invoke($filename, $player);
    return $this;
  }
  public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool
  {
    if ($this->blocked) {
      $collisionType = CollisionType::SOLID;
    }
    return !$this->blocked;
  }
  public function scrollMap(Player $player, Vector2 $moveDirection): bool { return false; }
}

final class SubjectOwnershipPlayer extends Player
{
  protected function renderLocationHUDWindow(): void {}
  public function renderEventCues(): void {}
  protected function handleCollision(?CollisionType $collisionType): void {}
}

final class SubjectOwnershipPresentation implements EventPresentationInterface
{
  public int $resets = 0;
  public function beginText(string $text, string $name = ''): void {}
  public function beginChoice(string $prompt, array $options, string $title = ''): void {}
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return false; }
  public function choiceResult(): ?int { return null; }
  public function reset(): void { $this->resets++; }
}

final class SubjectOwnershipScene extends GameScene
{
  public SubjectOwnershipPresentation $testPresentation;
  public array $restoredTiles = [];
  private Game $testGame;

  public function __construct()
  {
    [$this->testGame] = makeSceneAudioGame();
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->uiManager = new class extends UIManager {
      public function __construct() {}
      public function render(): void {}
      public function suspend(): void {}
      public function resume(): void {}
    };
    $this->uiManager->locationHUDWindow = new class extends LocationHUDWindow {
      public function __construct() {}
      public function updateDetails(Vector2 $position, MovementHeading $heading): void {}
      public function refreshLayout(): void {}
    };
    $this->currentMapId = 'map-a';
    $this->mapManager = new SubjectOwnershipMap();
    $this->camera = new Camera($this, 24, 8, worldSpace: array_fill(0, 30, str_repeat('.', 60)));
    $this->npcManager = new NpcManager($this);
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
    $this->cinematicController = new CinematicController($this);
    $this->testPresentation = new SubjectOwnershipPresentation();
    $this->eventInterpreter = new EventInterpreter($this, $this->testPresentation);
    $this->player = new SubjectOwnershipPlayer($this, 'hero', new Vector2(3, 4), new Rect(0, 0, 1, 1), ['v'],
      MovementHeading::SOUTH, graphicalSprites: DirectionalGraphicalSpriteSet::fromArray(spriteSheetData()));
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $this->npcManager->configure([['id' => 'guide', 'name' => 'Guide', 'sprite' => 'N', 'x' => 7, 'y' => 4,
      'movement' => 'wander', 'conditions' => [['type' => 'switch', 'name' => 'departed', 'value' => false]],
      'sprites' => ['east' => 'E', 'west' => 'W', 'south' => 'S', 'north' => 'N']]]);
    $this->camera->attach($this->player);
  }

  public function getGame(): Game { return $this->testGame; }
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  {
    $this->requestFieldPresentationReconciliation();
  }
  public function renderBackgroundTile(int $x, int $y): void { $this->restoredTiles[] = [$x, $y]; }
  public function installField(): void
  {
    $this->fieldState = new class(new SceneStateContext($this)) extends FieldState {
      public function resume(): void {}
      public function renderTheField(bool $forceFullRepaint = false): void
      {
        Console::recomposeFrame(function (): void {
          $this->getGameScene()->npcManager->render();
          $this->getGameScene()->cinematicStage->render();
          $this->getGameScene()->player->render();
        });
      }
    };
    $this->state = $this->fieldState;
  }
  public function changeMapIdentity(string $id): void { $this->currentMapId = $id; }
  public function finishSceneStopSetup(): void
  {
    $this->eventManager = EventManager::getInstance($this->testGame);
    $this->modalEventHandler = static function (): void {};
    $this->camera = new class($this, 24, 8) extends Camera {
      public function stop(): void {}
    };
  }
  public function failOnFinalizerWrite(): void
  {
    $this->eventInterpreter = new class($this, $this->testPresentation) extends EventInterpreter {
      protected function execute(EventExecutionSession $session, EventExecutionLane $lane, array $command): EventCommandResult
      {
        if ($session->isFinalizing && ($command['name'] ?? '') === 'inject-failure') {
          throw new RuntimeException('Injected finalizer boundary failure.');
        }
        return parent::execute($session, $lane, $command);
      }
    };
  }
}

function subjectOwnershipCast(array $extra = []): array
{
  return array_replace(['id' => 'pose', 'sprite' => '@',
    'subject' => ['kind' => 'npc', 'id' => 'guide'], 'sprites2d' => spriteSheetData()], $extra);
}

function subjectOwnershipDefinition(array $commands = [], array $data = []): CinematicDefinition
{
  return CinematicDefinition::fromArrays(array_replace(['id' => 'ownership', 'name' => 'Ownership',
    'cast' => [subjectOwnershipCast(['suppress' => [['kind' => 'player']]])]], $data),
    $commands ?: [['type' => 'wait', 'seconds' => 10]]);
}

beforeEach(function () {
  $this->staticBefore = [];
  foreach ([Console::class, Cursor::class, EventManager::class, ConfigStore::class] as $class) {
    $this->staticBefore[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
  ob_start();
  Console::syncDimensions(24, 8);
  Console::setLayerTracking(true);
  $this->scene = new SubjectOwnershipScene();
  $this->stage = $this->scene->cinematicStage;
  $this->npc = $this->scene->npcManager->findById('guide');
  $this->player = $this->scene->player;
});

afterEach(function () {
  ob_end_clean();
  foreach ($this->staticBefore as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('suppresses paired ordinary art and direct redraws without hiding collision or authored wandering', function () {
  $this->scene->camera->moveTo(0, 0);
  $actor = $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  Console::recomposeFrame(function (): void {
    Console::write(str_repeat('.', 24), 0, 4);
    $this->scene->npcManager->render();
    $this->player->render();
    $this->stage->render();
  });
  expect(Console::charAt(7, 4))->toBe('@')->and(Console::charAt(3, 4))->toBe('.')
    ->and($this->player->getGraphicalSpriteDefinition())->toBeNull()
    ->and($this->scene->npcManager->visibleNpcs())->toBe([$this->npc])
    ->and($this->scene->npcManager->npcAt(7, 4))->toBe($this->npc)
    ->and($this->npc->wanders)->toBeTrue();
  $this->scene->npcManager->faceNpc('guide', Vector2::right());
  $this->player->face(Vector2::left(), $this->scene->camera);
  $this->player->renderPlayer();
  $this->player->erase();
  expect(Console::charAt(7, 4))->toBe('@')->and($this->scene->restoredTiles)->toBe([]);
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  expect($actor->position->x)->toBe(8.0)->and($actor->facing)->toBe(MovementHeading::EAST)
    ->and(Console::charAt(8, 4))->not->toBe('E')->and($this->scene->restoredTiles)->toBe([]);
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(256);
  $this->scene->npcManager->faceNpc('guide', Vector2::right());
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  $this->scene->installField();
  expect(iterator_to_array($this->scene->getGraphicalSpriteProviders()))->toBe([$actor]);
});

it('animates real player routes independently of its ordinary graphical provider', function () {
  $actor = $this->stage->add(subjectOwnershipCast(['subject' => ['kind' => 'player']]));
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->stage->advanceGraphicalAnimation(0.08);
  expect($actor->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/east.png')
    ->and($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(256)
    ->and($actor->position->x)->toBe(4.0);
  $this->scene->mapManager->blocked = true;
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0)
    ->and($actor->position->x)->toBe(4.0);
  expect(fn() => $this->stage->move('pose', Vector2::right()))->toThrow(RuntimeException::class, 'real subject');
  $copy = $actor->position;
  $copy->x = 99;
  expect($this->player->position->x)->toBe(4.0);
});

it('recomposes ownership before the first cinematic yield rather than retaining old terminal subjects', function () {
  $this->scene->installField();
  $this->scene->camera->moveTo(0, 0);
  $this->scene->npcManager->render();
  $this->player->render();
  expect(Console::charAt(7, 4))->toBe('N')->and(Console::charAt(3, 4))->toBe('v');
  $this->scene->startCinematic(subjectOwnershipDefinition());
  expect(Console::charAt(7, 4))->toBe('@')->and(Console::charAt(3, 4))->not->toBe('v');
});

it('replaces sheets with cropped poses without resetting the subject or entry snapshot', function () {
  $old = $this->stage->add(subjectOwnershipCast());
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $pose = $this->stage->add(subjectOwnershipCast(['replace' => true, 'sprite' => 'P',
    'sprites2d' => ['asset' => 'pose.png', 'width' => 56, 'height' => 56,
      'sourceRect' => ['x' => 128, 'y' => 0, 'width' => 128, 'height' => 128]]]));
  expect($pose->subject)->toBe($old->subject)->and($old->getGraphicalSpriteDefinition())->toBeNull()
    ->and($pose->position->x)->toBe(8.0)->and($pose->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(128)
    ->and($pose->getGraphicalSpriteId())->toBe($old->getGraphicalSpriteId());
  $this->stage->clear();
  expect($this->npc->position->x)->toBe(7.0)->and($this->npc->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->npc->sprite)->toBe('N')->and($pose->isVisible)->toBeFalse();
});

it('releases a replaced paired participant while retaining its failure recovery', function () {
  $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->stage->add(subjectOwnershipCast(['replace' => true]));
  expect($this->stage->suppresses($this->player))->toBeFalse()
    ->and($this->player->getGraphicalSpriteDefinition())->not->toBeNull();
  $this->stage->clear();
  expect($this->player->position->x)->toBe(3.0);
});

it('keeps hidden takeover hidden and removed visuals released without discarding recovery', function () {
  $actor = $this->stage->add(subjectOwnershipCast());
  $this->stage->hide('pose');
  expect($actor->isVisible)->toBeFalse()->and($this->stage->suppresses($this->npc))->toBeTrue();
  $this->stage->show('pose');
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->stage->remove('pose');
  expect($actor->isVisible)->toBeFalse()->and($this->stage->suppresses($this->npc))->toBeFalse();
  $this->stage->clear();
  expect($this->npc->position->x)->toBe(7.0);
});

it('never resurrects an ineligible real NPC or a stale paired visual', function () {
  $actor = $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->gameState->setSwitch('departed', true);
  expect($actor->isVisible)->toBeFalse()->and($this->stage->suppresses($this->player))->toBeFalse()
    ->and($this->scene->npcManager->visibleNpcs())->toBe([]);
  $this->stage->clear();
  expect($this->npc->position->x)->toBe(8.0)->and($this->scene->gameState->getSwitch('departed'))->toBeTrue();
  Console::recomposeFrame(function (): void {
    $this->scene->npcManager->faceNpc('guide', Vector2::left());
    $this->scene->npcManager->render();
  });
  expect(Console::charAt(8, 4))->not->toBe('W');
});

it('keeps failed replacement and competing owner acquisition atomic', function () {
  $actor = $this->stage->add(subjectOwnershipCast());
  expect(fn() => $this->stage->add(subjectOwnershipCast(['id' => 'other'])))
    ->toThrow(RuntimeException::class, 'already owned');
  expect(fn() => $this->stage->add(subjectOwnershipCast(['replace' => true, 'suppress' => [['kind' => 'npc', 'id' => 'absent']]])))
    ->toThrow(RuntimeException::class, 'unavailable');
  expect($this->stage->all())->toBe([$actor])->and($actor->isVisible)->toBeTrue();
});

it('reserves ownership across temporary eligibility changes rather than creating future duplicates', function () {
  $paired = $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  $this->scene->gameState->setSwitch('departed', true);
  expect($paired->isVisible)->toBeFalse();
  expect(fn() => $this->stage->add(subjectOwnershipCast(['id' => 'other', 'subject' => ['kind' => 'player']])))
    ->toThrow(RuntimeException::class, 'already owned');
  $this->scene->gameState->setSwitch('departed', false);
  expect($this->stage->all())->toBe([$paired])->and($paired->isVisible)->toBeTrue();
});

it('restores failure transforms but retains committed transforms and story outcomes', function () {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition());
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->stage->commitSubjectTransforms($this->npc);
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->gameState->setSwitch('outcome', true);
  $this->scene->camera->detach();
  $this->scene->eventInterpreter->failActiveSession('controlled failure');
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->scene->hasUnstableEventSession())->toBeFalse()
    ->and($this->scene->cinematicController->active())->toBeNull()
    ->and($this->npc->position->x)->toBe(8.0)->and($this->player->position->x)->toBe(3.0)
    ->and($this->player->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->scene->camera->captureState()->followsPlayer)->toBeTrue()
    ->and($this->scene->gameState->getSwitch('outcome'))->toBeTrue()
    ->and($this->scene->hasStoryEvent('cinematic:ownership:completed'))->toBeFalse()
    ->and($this->stage->all())->toBe([]);
});

it('keeps intentional transforms on successful completion and rejects stale callbacks on re-entry', function () {
  $first = $this->scene->startCinematic(subjectOwnershipDefinition());
  $old = $this->stage->require('pose');
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->scene->eventInterpreter->update(10);
  expect($first->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->npc->position->x)->toBe(8.0)->and($this->player->position->x)->toBe(4.0);
  $second = $this->scene->startCinematic(subjectOwnershipDefinition());
  $new = $this->stage->require('pose');
  $this->scene->cinematicController->onEventSessionFailed($this->scene, $first);
  expect(fn() => $this->scene->cinematicController->onEventSessionCompleted($this->scene, $first))
    ->toThrow(RuntimeException::class, 'identity');
  $old->show();
  expect($old->isVisible)->toBeFalse()->and($new->isVisible)->toBeTrue()
    ->and($this->scene->eventInterpreter->activeSession())->toBe($second);
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->eventInterpreter->failActiveSession('second failure');
  expect($this->npc->position->x)->toBe(8.0);
});

it('cleans up partially acquired cast when start fails before a session is created', function () {
  $definition = subjectOwnershipDefinition(data: ['presentation' => ['initial' => 'hidden'],
    'cast' => [subjectOwnershipCast(), subjectOwnershipCast(['id' => 'missing', 'subject' => ['kind' => 'npc', 'id' => 'absent']])]]);
  expect(fn() => $this->scene->startCinematic($definition))->toThrow(RuntimeException::class, 'unavailable');
  expect($this->stage->all())->toBe([])->and($this->stage->suppresses($this->npc))->toBeFalse()
    ->and($this->scene->cinematicController->active())->toBeNull()
    ->and($this->scene->hasUnstableEventSession())->toBeFalse()
    ->and($this->scene->cinematicPresentation->hasTransitionCover())->toBeFalse()
    ->and($this->scene->camera->captureState()->followsPlayer)->toBeTrue();
});

it('never applies old-map recovery to another NPC with the same id or the transferred player', function () {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition());
  $old = $this->stage->require('pose');
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->changeMapIdentity('map-b');
  $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Other', 'sprite' => 'B', 'x' => 18, 'y' => 4]]);
  $newNpc = $this->scene->npcManager->findById('guide');
  $this->player->position->x = 15;
  expect($old->isVisible)->toBeFalse()->and($this->stage->suppresses($newNpc))->toBeFalse();
  $this->scene->eventInterpreter->failActiveSession('failure after transfer');
  expect($session->status)->toBe(EventExecutionStatus::FAILED)->and($newNpc->position->x)->toBe(18.0)
    ->and($this->player->position->x)->toBe(15.0)->and($this->npc->position->x)->toBe(8.0);
});

it('restores and releases map-local leases before transfer and makes held references inert', function () {
  $actor = $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->stage->clear();
  $this->scene->changeMapIdentity('map-b');
  $this->player->position->x = 15;
  $actor->subject->restore();
  expect($this->npc->position->x)->toBe(7.0)->and($this->player->position->x)->toBe(15.0)
    ->and($actor->isVisible)->toBeFalse();
});

it('clears the source-map lease before the real transfer path assigns destination coordinates', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition());
  $old = $this->stage->require('pose');
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->mapManager->onLoad = function (string $filename, Player $player): void {
    expect($filename)->toBe('map-b')->and($player->position->x)->toBe(18.0)
      ->and($this->npc->position->x)->toBe(7.0)->and($this->stage->all())->toBe([]);
    $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Other', 'sprite' => 'B', 'x' => 20, 'y' => 4]]);
  };
  $this->scene->transferPlayer(new Location('map-b', new Vector2(18, 4), null), useConfiguredTransition: false);
  $this->scene->eventInterpreter->failActiveSession('after real transfer');
  expect($this->player->position->x)->toBe(18.0)
    ->and($this->scene->npcManager->findById('guide')->position->x)->toBe(20.0)
    ->and($old->isVisible)->toBeFalse();
});

it('invalidates old leases even when the same map and NPC id are reloaded', function () {
  $old = $this->stage->add(subjectOwnershipCast());
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->mapManager->onLoad = function (): void {
    $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Reloaded', 'sprite' => 'R', 'x' => 20, 'y' => 4]]);
  };
  $this->scene->loadMap('map-a', $this->player);
  $new = $this->stage->add(subjectOwnershipCast());
  $old->subject->restore();
  expect($new->position->x)->toBe(20.0)->and($old->isVisible)->toBeFalse()
    ->and($old->subject)->not->toBe($new->subject)->and($this->npc->position->x)->toBe(7.0);
});

it('retains a detached cinematic camera and subject identity through suspend resume and resize', function () {
  $this->scene->installField();
  $session = $this->scene->startCinematic(subjectOwnershipDefinition());
  $actor = $this->stage->require('pose');
  $this->scene->camera->detach();
  $this->scene->camera->moveTo(3, 2);
  $this->scene->suspend();
  $this->scene->resume();
  $this->scene->onScreenResize(20, 7);
  expect($this->stage->require('pose'))->toBe($actor)
    ->and($this->scene->eventInterpreter->activeSession())->toBe($session)
    ->and($this->scene->camera->followsPlayer)->toBeFalse()
    ->and($this->scene->camera->position->x)->toBe(3.0)
    ->and($this->scene->camera->position->y)->toBe(2.0)
    ->and($this->stage->suppresses($this->player))->toBeTrue();
});

it('routes the same real subject under reduced motion without an independent visual route', function (bool $reduced) {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reduced]]);
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'secondsPerStep' => 0.1,
      'steps' => [['direction' => 'right', 'count' => 3]]],
    ['type' => 'wait', 'seconds' => 10],
  ]));
  $actor = $this->stage->require('pose');
  for ($tick = 0; $tick < 4; $tick++) {
    $this->scene->eventInterpreter->update(0.1);
  }
  expect($actor->position->x)->toBe(10.0)->and($this->npc->position->x)->toBe(10.0)
    ->and($actor->facing)->toBe(MovementHeading::EAST)
    ->and($this->scene->eventInterpreter->activeSession())->toBe($session);
  $this->scene->eventInterpreter->failActiveSession('restore route');
  expect($this->npc->position->x)->toBe(7.0);
})->with([false, true]);

it('restores transforms and camera and releases event input at scene shutdown', function () {
  $this->scene->finishSceneStopSetup();
  $this->scene->camera->attach($this->player);
  $session = $this->scene->startCinematic(subjectOwnershipDefinition());
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->scene->camera->detach();
  $this->scene->stop();
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->scene->hasUnstableEventSession())->toBeFalse()
    ->and($this->scene->camera->captureState()->followsPlayer)->toBeTrue()
    ->and($this->npc->position->x)->toBe(7.0)->and($this->stage->all())->toBe([]);
  $this->scene->stop();
});

it('keeps the restoration and explicit commit seam independent of finalizer interpretation', function () {
  $this->stage->add(subjectOwnershipCast(['suppress' => [['kind' => 'player']]]));
  $this->player->position->x = 9;
  $this->npc->position->x = 12;
  $this->stage->restoreSubjectTransforms();
  expect($this->player->position->x)->toBe(3.0)->and($this->npc->position->x)->toBe(7.0);
  $this->player->position->x = 20;
  $this->stage->commitSubjectTransforms($this->player);
  $this->stage->clear();
  expect($this->player->position->x)->toBe(20.0);
});

it('restores temporary transforms before direct interpreter skip and preserves the authored finalizer position', function () {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition(data: ['skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'move_player', 'x' => 19, 'y' => 6],
      ['type' => 'set_variable', 'name' => 'count', 'value' => 1], ['type' => 'camera', 'operation' => 'attach']]]));
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  expect($this->scene->eventInterpreter->skipActiveCinematic())->toBeTrue()
    ->and($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->npc->position->x)->toBe(7.0)->and($this->player->position->x)->toBe(19.0)
    ->and($this->player->position->y)->toBe(6.0)
    ->and($this->scene->gameState->getVariable('count'))->toBe(1)
    ->and($this->scene->hasStoryEvent('cinematic:ownership:completed'))->toBeTrue()
    ->and($this->scene->hasUnstableEventSession())->toBeFalse()
    ->and($this->scene->skipCinematic())->toBeFalse();
});

it('preserves finalizer moves and story writes when a later finalizer command fails', function (bool $skip) {
  $this->scene->failOnFinalizerWrite();
  $session = $this->scene->startCinematic(subjectOwnershipDefinition(data: ['skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'move_player', 'x' => 19, 'y' => 6],
      ['type' => 'set_switch', 'name' => 'ratified', 'value' => true],
      ['type' => 'set_switch', 'name' => 'inject-failure', 'value' => true]]]));
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  if ($skip) {
    expect($this->scene->skipCinematic())->toBeFalse();
  } else {
    $this->scene->eventInterpreter->update(10);
  }
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->player->position->x)->toBe(19.0)->and($this->player->position->y)->toBe(6.0)
    ->and($this->npc->position->x)->toBe(7.0)
    ->and($this->scene->gameState->getSwitch('ratified'))->toBeTrue()
    ->and($this->scene->hasStoryEvent('cinematic:ownership:completed'))->toBeFalse()
    ->and($this->stage->all())->toBe([])->and($this->scene->hasUnstableEventSession())->toBeFalse();
})->with([false, true]);

it('does not restore or release subjects when skipping is forbidden', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition());
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  expect($this->scene->skipCinematic())->toBeFalse()->and($this->npc->position->x)->toBe(8.0)
    ->and($this->stage->suppresses($this->npc))->toBeTrue();
});

it('provides terminal-only bound fallback and camera-relative graphical projection', function () {
  $actor = $this->stage->add(subjectOwnershipCast());
  $this->scene->camera->detach();
  $this->scene->camera->moveTo(3, 2);
  $projected = new GraphicalSpriteProjector()->project($actor, $this->scene->camera);
  expect([$projected->x, $projected->y])->toBe([4, 2]);
  $fallback = subjectOwnershipCast(['replace' => true]);
  unset($fallback['sprites2d']);
  $actor = $this->stage->add($fallback);
  Console::recomposeFrame(fn() => $this->stage->render());
  expect($actor->getGraphicalSpriteDefinition())->toBeNull()->and(Console::charAt(4, 2))->toBe('@');
});

it('validates bound ownership fields before runtime', function (array $invalid) {
  expect(fn() => subjectOwnershipDefinition(data: ['cast' => [array_replace(subjectOwnershipCast(), $invalid)]]))
    ->toThrow(InvalidArgumentException::class, 'cast[1]/subject');
})->with([
  'independent coordinates' => [['x' => 7]],
  'independent facing' => [['facing' => 'East']],
  'second collision entity' => [['collision' => true]],
  'unstable npc identity' => [['subject' => ['kind' => 'npc']]],
  'non-real anchor' => [['subject' => ['kind' => 'staged_actor', 'id' => 'other']]],
  'bad suppression list' => [['suppress' => ['kind' => 'player']]],
  'bad replacement flag' => [['replace' => 'yes']],
]);
