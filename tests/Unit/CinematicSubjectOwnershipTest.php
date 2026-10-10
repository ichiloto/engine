<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
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
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\Events\Interpreter\MovementRoutePlanner;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicScriptValidator;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\PreparedMap;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\UI\Elements\LocationHUDWindow;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class SubjectOwnershipTestGame extends Game
{
  public function __construct() { $this->audioManager = new RecordingAudioManager($this); }
  public function __destruct() {}
}

final class SubjectOwnershipMap extends MapManager
{
  public bool $blocked = false;
  public ?\Throwable $prepareFailure = null;
  public ?\Closure $onLoad = null;
  public ?\Closure $passable = null;
  public function __construct() {}
  public function loadMap(string $filename, Player $player): self
  {
    ($this->onLoad)?->__invoke($filename, $player);
    return $this;
  }
  public function prepareMap(string $filename): PreparedMap
  {
    if ($this->prepareFailure !== null) {
      throw $this->prepareFailure;
    }
    return new PreparedMap(['id' => $filename], [], [], [], []);
  }
  public function applyPreparedMap(PreparedMap $prepared, Player $player): void
  {
    ($this->onLoad)?->__invoke($prepared->data['id'], $player);
  }
  public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool
  {
    if ($this->blocked) {
      $collisionType = CollisionType::SOLID;
    }
    return !$this->blocked && ($this->passable === null || ($this->passable)($x, $y));
  }
  public function scrollMap(Player $player, Vector2 $moveDirection): bool { return false; }
  public function render(?int $x = null, ?int $y = null): void {}
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
  public ?string $mapLoadDiagnostic = null;
  private Game $testGame;

  public function __construct(string $assetRoot)
  {
    $this->testGame = new SubjectOwnershipTestGame();
    // Staged actors check their character sheet against the running renderer's asset root.
    $this->testGame->useRendererRuntime(new RendererRuntime(new RendererRuntimeConfig(
      new RendererProcessConfig(['fixture']), $assetRoot), new FakeRendererTransport()));
    $this->sceneManager = makeBareScene(\Ichiloto\Engine\Scenes\SceneManager::class);
    $this->sceneManager->currentScene = $this;
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
      MovementHeading::SOUTH, graphicalSprites: CharacterSheet::fromArray(characterSheetData()), assetRoot: $assetRoot);
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $this->npcManager->configure([['id' => 'guide', 'name' => 'Guide', 'sprite' => 'N', 'x' => 7, 'y' => 4,
      'movement' => 'wander', 'conditions' => [['type' => 'switch', 'name' => 'departed', 'value' => false]],
      'sprites' => ['east' => 'E', 'west' => 'W', 'south' => 'S', 'north' => 'N']]]);
    $this->camera->attach($this->player);
  }

  public function getGame(): Game { return $this->testGame; }
  protected function reportMapLoadFailure(string $mapId, \Throwable $error): void
  {
    $this->mapLoadDiagnostic = $mapId . ': ' . $error->getMessage();
  }
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
    'subject' => ['kind' => 'npc', 'id' => 'guide'], 'sprites2d' => characterSheetData()], $extra);
}

function subjectOwnershipDefinition(array $commands = [], array $data = []): CinematicDefinition
{
  return CinematicDefinition::fromArrays(array_replace(['id' => 'ownership', 'name' => 'Ownership',
    'cast' => [subjectOwnershipCast(['suppress' => [['kind' => 'player']]])]], $data),
    $commands ?: [['type' => 'wait', 'seconds' => 10]]);
}

function makeSubjectOwnershipLoopCast(array $extra = []): array
{
  return array_replace(['id' => 'pulse', 'sprite' => 'P', 'subject' => ['kind' => 'npc', 'id' => 'guide'],
    'sprites2d' => ['asset' => 'Loop.png', 'animation' => ['columns' => 3,
      'frames' => [0, 1, 2], 'fps' => 10, 'restFrame' => 2]]], $extra);
}

beforeEach(function () {
  $this->staticBefore = [];
  foreach ([Console::class, Cursor::class, EventManager::class, ConfigStore::class, Time::class, Debug::class] as $class) {
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
  $this->assetRoot = sys_get_temp_dir() . '/ichiloto-subject-ownership-' . bin2hex(random_bytes(4));
  // 48 x 48 frames: a standard sheet's frame rects are whole multiples of 48.
  writeCharacterSheetPng($this->assetRoot . '/' . characterSheetData()['sheet'], 48, 48);
  writeTestPng($this->assetRoot . '/Loop.png', 24, 10);
  Debug::configure(['log_directory' => $this->assetRoot . '/logs']);
  $this->scene = new SubjectOwnershipScene($this->assetRoot);
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
  $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->assetRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($paths as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($this->assetRoot);
});

it('advances a field pose through the real scene clock while dialogue or a choice remains yielded', function (array $command) {
  $this->scene->installField();
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([$command], ['cast' => [makeSubjectOwnershipLoopCast()]]));
  $actor = $this->stage->require('pulse');
  $position = clone $this->npc->position;
  $generation = $this->stage->generation;
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, .1);
  foreach ([8, 16, 0, 8] as $x) {
    $this->scene->update();
    expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe($x)
      ->and($this->stage->require('pulse'))->toBe($actor)->and($this->stage->generation)->toBe($generation)
      ->and($session->status)->toBe(EventExecutionStatus::YIELDED)
      ->and([$this->npc->position->x, $this->npc->position->y])->toBe([$position->x, $position->y])
      ->and($actor->sprite)->toBe(['P']);
  }
})->with([
  'dialogue' => [['type' => 'text', 'text' => 'Synthetic held dialogue.']],
  'choice' => [['type' => 'choice', 'prompt' => 'Synthetic choice.', 'options' => [['text' => 'Continue', 'then' => []]]]],
]);

it('freezes pose time across scene suspension, hiding and subject ineligibility without wall-time catchup', function () {
  $this->scene->installField();
  $actor = $this->stage->add(makeSubjectOwnershipLoopCast());
  $this->stage->advanceGraphicalAnimation(.1);
  $this->scene->suspend(); $this->stage->advanceGraphicalAnimation(100);
  $this->scene->resume();
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  $this->stage->hide('pulse'); $this->stage->advanceGraphicalAnimation(100);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull();
  $this->stage->show('pulse');
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  $this->scene->gameState->setSwitch('departed', true); $this->stage->advanceGraphicalAnimation(100);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull();
  $this->scene->suspend(); $this->scene->resume();
  $this->scene->gameState->setSwitch('departed', false);
  $this->stage->advanceGraphicalAnimation(.1);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(16);
});

it('uses the authored reduced-motion rest cell and keeps turning or blocked movement separate from pose playback', function () {
  $actor = $this->stage->add(makeSubjectOwnershipLoopCast());
  $this->stage->advanceGraphicalAnimation(.1);
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true], 'ui' => ['hud' => ['location' => false]]]);
  $this->stage->advanceGraphicalAnimation(100);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(16);
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  $this->scene->npcManager->faceNpc('guide', Vector2::right());
  $this->scene->mapManager->blocked = true;
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->stage->advanceGraphicalAnimation(.1);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(16)
    ->and($actor->facing)->toBe(MovementHeading::EAST)->and($actor->hasCollision)->toBeFalse();
  $this->scene->mapManager->blocked = false;
  $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  expect($actor->getGraphicalSpriteMotion())->not->toBeNull();
  $this->stage->advanceGraphicalAnimation(.1);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
});

it('releases replaced and removed loop visuals and keeps new visuals paused if the scene is suspended', function () {
  $actor = $this->stage->add(makeSubjectOwnershipLoopCast());
  $this->stage->pauseGraphicalAnimation();
  $replacement = $this->stage->add(makeSubjectOwnershipLoopCast(['replace' => true]));
  $replacement->show(); $this->stage->advanceGraphicalAnimation(.1);
  expect($replacement->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  $this->stage->resumeGraphicalAnimation(); $this->stage->advanceGraphicalAnimation(.1);
  expect($replacement->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  $actor->show(); $actor->resumeGraphicalAnimation(); $actor->advanceGraphicalAnimation(.1);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull();
  $this->stage->remove('pulse');
  $replacement->show(); $replacement->resumeGraphicalAnimation(); $replacement->advanceGraphicalAnimation(.1);
  expect($replacement->getGraphicalSpriteDefinition())->toBeNull()->and($this->stage->all())->toBe([]);
});

it('releases pose playback on cinematic completion, failure, authored skip, transfer and shutdown', function (string $ending) {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition(data: [
    'cast' => [makeSubjectOwnershipLoopCast()], 'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'set_switch', 'name' => 'finished', 'value' => true]],
  ]));
  $actor = $this->stage->require('pulse');
  $this->stage->advanceGraphicalAnimation(.1);
  match ($ending) {
    'complete' => $this->scene->updateEventSession(10),
    'failure' => $this->scene->eventInterpreter->failActiveSession('Synthetic interruption.'),
    'skip' => $this->scene->skipCinematic(),
    'transfer' => $this->scene->loadMap('map-b', $this->player),
    'shutdown' => $this->scene->cinematicController->shutdown(),
  };
  $actor->show(); $actor->resumeGraphicalAnimation(); $actor->advanceGraphicalAnimation(10);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull()->and($this->stage->all())->toBe([])
    ->and($this->scene->hasUnstableEventSession())->toBe($ending === 'transfer');
})->with(['complete', 'failure', 'skip', 'transfer', 'shutdown']);

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
  // The first stride shows walking pattern 2; turning in place stands again on pattern 1.
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(96);
  $this->scene->npcManager->faceNpc('guide', Vector2::right());
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(48);
  $this->scene->installField();
  expect(iterator_to_array($this->scene->getGraphicalSpriteProviders()))->toBe([$actor]);
});

it('animates real player routes independently of its ordinary graphical provider', function () {
  $actor = $this->stage->add(subjectOwnershipCast(['subject' => ['kind' => 'player']]));
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $this->stage->advanceGraphicalAnimation(0.08);
  // East is the sheet's third direction row; the first stride shows walking pattern 2.
  expect($actor->getGraphicalSpriteDefinition()->asset)->toBe(characterSheetData()['sheet'])
    ->and($actor->getGraphicalSpriteDefinition()->sourceRect->y)->toBe(96)
    ->and($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(96)
    ->and($actor->position->x)->toBe(4.0);
  $this->scene->mapManager->blocked = true;
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(48)
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
    'sprites2d' => ['asset' => 'pose.png',
      'sourceRect' => ['x' => 128, 'y' => 0, 'width' => 128, 'height' => 128]]]));
  expect($pose->subject)->toBe($old->subject)->and($old->getGraphicalSpriteDefinition())->toBeNull()
    ->and($pose->position->x)->toBe(8.0)->and($pose->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(128)
    ->and($pose->getGraphicalSpriteDefinition()->width)->toBe(FieldViewport::TILE_SIZE)
    ->and($pose->getGraphicalSpriteId())->toBe($old->getGraphicalSpriteId());
  $this->stage->clear();
  expect($this->npc->position->x)->toBe(7.0)->and($this->npc->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->npc->sprite)->toBe('N')->and($pose->isVisible)->toBeFalse();
});

it('preserves each omitted independent transform and collision field when replacing a staged actor', function (array $overrides) {
  $old = $this->stage->add(['id' => 'independent', 'sprite' => 'A', 'x' => 12, 'y' => 6,
    'facing' => 'North', 'collision' => true]);
  $this->stage->move($old->id, Vector2::left());
  $this->stage->move($old->id, new Vector2(-.25, -.5));
  $position = clone $old->position;
  $facing = $old->facing;
  $collision = $old->hasCollision;
  $replacement = $this->stage->add(['id' => $old->id, 'replace' => true, 'sprite' => 'B', ...$overrides]);
  expect($replacement->position->x)->toBe((float)($overrides['x'] ?? $position->x))
    ->and($replacement->position->y)->toBe((float)($overrides['y'] ?? $position->y))
    ->and($replacement->facing)->toBe(isset($overrides['facing']) ? MovementHeading::from($overrides['facing']) : $facing)
    ->and($replacement->hasCollision)->toBe($overrides['collision'] ?? $collision)
    ->and($old->isVisible)->toBeFalse()->and($this->stage->all())->toBe([$replacement])
    ->and($replacement->position)->not->toBe($old->position)
    ->and($replacement->getGraphicalSpriteId())->toBe($old->getGraphicalSpriteId());
  $replacement->move(Vector2::right());
  expect($old->position)->toEqual($position);
})->with([
  'no transform fields' => [[]],
  'only x, including zero' => [['x' => 0]],
  'only y, including zero' => [['y' => 0]],
  'only facing' => [['facing' => 'South']],
  'explicitly remove collision' => [['collision' => false]],
  'all transform fields' => [['x' => 5, 'y' => 2, 'facing' => 'East', 'collision' => false]],
]);

it('inherits each omitted renderer role independently from its real subject', function (string $kind, array $overrides) {
  $npcSheet = characterSheetData('Graphics/Characters/Npc.png');
  writeCharacterSheetPng($this->assetRoot . '/' . $npcSheet['sheet'], 48, 48);
  writeTestPng($this->assetRoot . '/pose.png', 32, 48);
  $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Guide', 'sprite' => 'N', 'x' => 7, 'y' => 4,
    'sprites' => ['east' => 'E', 'south' => 'S'], 'sprites2d' => $npcSheet]]);
  $subject = $kind === 'player' ? $this->player : $this->scene->npcManager->findById('guide');
  $ordinaryGraphics = $subject->getGraphicalSpriteDefinition();
  $entry = ['id' => 'inherited', 'subject' => ['kind' => $kind, 'id' => 'guide'], ...$overrides];
  expect(subjectOwnershipDefinition(data: ['cast' => [$entry]])->cast)->toBe([$entry]);
  CinematicScriptValidator::validate([['type' => 'stage_actor', ...$entry]]);
  $actor = $this->stage->add($entry);
  $expectedTerminal = isset($overrides['sprite']) ? [$overrides['sprite']] : (array)$subject->sprite;
  expect($actor->sprite)->toBe($expectedTerminal)->and($actor->position)->toEqual($subject->position)
    ->and($actor->hasCollision)->toBeFalse()->and($this->stage->suppresses($subject))->toBeTrue();
  if (isset($overrides['sprites2d'])) {
    expect($actor->getGraphicalSpriteDefinition()->asset)->toBe('pose.png');
  } else {
    expect($actor->getGraphicalSpriteDefinition())->toEqual($ordinaryGraphics);
  }
  $kind === 'player' ? $this->player->tryMove(Vector2::right(), $this->scene->camera)
    : $this->scene->npcManager->moveNpcById('guide', Vector2::right());
  $this->stage->advanceGraphicalAnimation(0.08);
  $expectedTerminal = $overrides['sprites']['east'] ?? (isset($overrides['sprite']) ? [$overrides['sprite']] : (array)$subject->sprite);
  expect($actor->sprite)->toBe($expectedTerminal)->and($actor->facing)->toBe(MovementHeading::EAST)
    ->and($actor->getGraphicalSpriteMotion())->not->toBeNull();
  if (!isset($overrides['sprites2d'])) {
    expect($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(96)
      ->and($actor->getGraphicalSpriteDefinition()->sourceRect->y)->toBe(96);
  }
  $this->scene->camera->moveTo(0, 0);
  Console::recomposeFrame(fn() => $this->stage->render());
  expect(Console::charAt((int)$subject->position->x, (int)$subject->position->y))->toBe($expectedTerminal[0]);
  $this->stage->hide($actor->id);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull()->and($this->stage->suppresses($subject))->toBeTrue();
  $this->stage->show($actor->id);
  expect($actor->getGraphicalSpriteDefinition())->not->toBeNull();
  $this->stage->remove($actor->id);
  expect($actor->getGraphicalSpriteDefinition())->toBeNull()->and($this->stage->suppresses($subject))->toBeFalse();
})->with(['player', 'npc'])->with([
  'no explicit roles' => [[]],
  'terminal glyph only' => [['sprite' => 'T']],
  'terminal directions only' => [['sprites' => ['east' => ['>']]]],
  'graphical pose only' => [['sprites2d' => ['asset' => 'pose.png']]],
  'both roles' => [['sprite' => 'T', 'sprites2d' => ['asset' => 'pose.png']]],
]);

it('replaces explicit bound roles with live subject inheritance without discarding its recovery lease', function () {
  $position = clone $this->player->position;
  writeTestPng($this->assetRoot . '/pose.png', 32, 48);
  $old = $this->stage->add(subjectOwnershipCast(['subject' => ['kind' => 'player'], 'sprite' => 'P',
    'sprites2d' => ['asset' => 'pose.png']]));
  $this->player->tryMove(Vector2::right(), $this->scene->camera);
  $inherited = $this->stage->add(['id' => $old->id, 'replace' => true, 'subject' => ['kind' => 'player']]);
  expect($inherited->subject)->toBe($old->subject)->and($old->isVisible)->toBeFalse()
    ->and($inherited->sprite)->toBe($this->player->sprite)
    ->and($inherited->position)->toEqual($this->player->position)
    ->and($inherited->getGraphicalSpriteDefinition()->asset)->toBe(characterSheetData()['sheet']);
  // Replacing the file does not require rebinding the subject or storing new dimensions.
  writeCharacterSheetPng($this->assetRoot . '/' . characterSheetData()['sheet'], 64, 56);
  expect($inherited->getGraphicalSpriteDefinition()->sourceRect->width)->toBe(64)
    ->and($inherited->getGraphicalSpriteDefinition()->sourceRect->height)->toBe(56)
    ->and($inherited->position)->toEqual($this->player->position);
  $this->stage->clear();
  expect($this->player->position)->toEqual($position)->and($inherited->isVisible)->toBeFalse();
});

it('keeps a routed unbound actor in place through the stage_actor interpreter command', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'subject' => 'staged_actor', 'actorId' => 'independent', 'secondsPerStep' => .1,
      'steps' => [['direction' => 'left']]],
    ['type' => 'stage_actor', 'id' => 'independent', 'replace' => true, 'sprite' => 'B'],
    ['type' => 'wait', 'seconds' => 10],
  ], ['cast' => [['id' => 'independent', 'sprite' => 'A', 'x' => 12, 'y' => 6, 'collision' => true]]]));
  $old = $this->stage->require('independent');
  for ($tick = 0; $tick < 4; $tick++) {
    $this->scene->eventInterpreter->update(.1);
  }
  $replacement = $this->stage->require('independent');
  expect($replacement)->not->toBe($old)->and($old->isVisible)->toBeFalse()
    ->and($replacement->sprite)->toBe(['B'])
    ->and([$replacement->position->x, $replacement->position->y])->toBe([$old->position->x, $old->position->y])
    ->and($replacement->facing)->toBe($old->facing)->and($replacement->hasCollision)->toBeTrue()
    ->and($this->scene->hasUnstableEventSession())->toBeTrue();
});

it('keeps inherited graphical art when the interpreter applies a terminal-only bound replacement', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'stage_actor', 'id' => 'inherited', 'replace' => true, 'subject' => ['kind' => 'player'], 'sprite' => 'T'],
    ['type' => 'move_route', 'secondsPerStep' => .5, 'steps' => [['direction' => 'right']]],
    ['type' => 'wait', 'seconds' => 10],
  ], ['cast' => [['id' => 'inherited', 'subject' => ['kind' => 'player']]]]));
  $actor = $this->stage->require('inherited');
  $this->scene->eventInterpreter->update(.1);
  $this->stage->advanceGraphicalAnimation(.08);
  expect($actor->sprite)->toBe(['T'])->and($actor->position)->toEqual($this->player->position)
    ->and($actor->getGraphicalSpriteDefinition()->asset)->toBe(characterSheetData()['sheet'])
    ->and($actor->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(96)
    ->and($actor->getGraphicalSpriteMotion()?->seconds)->toBe(.5)
    ->and($this->player->getGraphicalSpriteDefinition())->toBeNull();
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

it('preserves the current field when a destination map fails preflight', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition());
  $old = $this->stage->require('pose');
  $beforeWorld = $this->scene->camera->worldSpace;
  $this->scene->mapManager->prepareFailure = new InvalidArgumentException('destination grid is invalid');

  $this->scene->transferPlayer(new Location('map-b', new Vector2(18, 4), null), useConfiguredTransition: false);

  expect($this->scene->mapLoadDiagnostic)->toBe('map-b: destination grid is invalid')
    ->and($this->scene->currentMapId)->toBe('map-a')
    ->and($this->player->position->x)->toBe(3.0)
    ->and($this->player->position->y)->toBe(4.0)
    ->and($this->scene->camera->worldSpace)->toBe($beforeWorld)
    ->and($this->stage->require('pose'))->toBe($old);
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

it('walks authored waypoints and retraces the actual entry with its original facing', function (bool $reduced) {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reduced]]);
  $this->scene->mapManager->passable = static fn(int $x, int $y): bool => $x !== 4 || $y !== 4;
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'remember' => 'approach', 'secondsPerStep' => 0.1,
      'waypoints' => [['x' => 5, 'y' => 4], ['y' => 6]]],
    ['type' => 'move_route', 'retrace' => 'approach', 'secondsPerStep' => 0.1],
  ], ['cast' => []]));
  $positions = [[3, 4]];
  for ($tick = 0; $tick < 50 && $this->scene->hasUnstableEventSession(); $tick++) {
    $this->scene->eventInterpreter->update(0.1);
    $position = [intval($this->player->position->x), intval($this->player->position->y)];
    if ($position !== $positions[array_key_last($positions)]) {
      $positions[] = $position;
    }
  }
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->player->position->x)->toBe(3.0)->and($this->player->position->y)->toBe(4.0)
    ->and($this->player->heading)->toBe(MovementHeading::SOUTH);
  if (!$reduced) {
    $outbound = [[3, 4], [3, 3], [4, 3], [5, 3], [5, 4], [5, 5], [5, 6]];
    expect($positions)->toBe([...$outbound, ...array_slice(array_reverse($outbound), 1)]);
  }
})->with([false, true]);

it('records a zero-length approach and returns without inventing a movement unit', function () {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'remember' => 'here', 'waypoints' => [['x' => 3, 'y' => 4]]],
    ['type' => 'move_route', 'retrace' => 'here'],
  ], ['cast' => []]));
  for ($tick = 0; $tick < 4; $tick++) {
    $this->scene->eventInterpreter->update(1);
  }
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->player->position->x)->toBe(3.0)->and($this->player->position->y)->toBe(4.0);
});

it('fails a newly blocked step and restores a real subject even without replacement art', function (bool $reduced) {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reduced]]);
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'remember' => 'approach', 'waypoints' => [['x' => 6]], 'secondsPerStep' => 0.1],
  ], ['cast' => []]));
  if (!$reduced) {
    $this->scene->eventInterpreter->update(0.1);
    expect($this->player->position->x)->toBe(4.0);
  }
  $this->scene->mapManager->blocked = true;
  $this->scene->eventInterpreter->update(0.1);
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session->failureMessage)->toContain('was blocked')
    ->and($this->player->position->x)->toBe(3.0)->and($this->player->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->scene->hasUnstableEventSession())->toBeFalse();
})->with([false, true]);

it('rejects two simultaneous movement owners of one real subject', function () {
  $route = ['type' => 'move_route', 'secondsPerStep' => 1, 'steps' => [['direction' => 'right', 'count' => 3]]];
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'parallel', 'lanes' => [['id' => 'first', 'commands' => [$route]],
      ['id' => 'second', 'commands' => [$route]]]],
  ], ['cast' => []]));
  $this->scene->eventInterpreter->update(0.1);
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session->failureMessage)->toContain('Only one movement route')
    ->and($this->player->position->x)->toBe(3.0);
});

it('rejects missing duplicate and incomplete remembered routes', function (string $case) {
  $this->scene->startCinematic(subjectOwnershipDefinition(data: ['cast' => []]));
  $session = $this->scene->eventInterpreter->activeSession();
  if ($case !== 'missing') {
    $route = new MovementRouteRunner($this->scene, ['remember' => 'entry',
      'steps' => [['direction' => 'right']]], $session);
  }
  $command = $case === 'duplicate'
    ? ['remember' => 'entry', 'steps' => [['direction' => 'right']]] : ['retrace' => 'entry'];
  expect(fn() => new MovementRouteRunner($this->scene, $command, $session))->toThrow(RuntimeException::class);
})->with(['missing', 'duplicate', 'incomplete']);

it('refuses stale displaced wrong-subject and consumed return paths', function (string $case) {
  $this->scene->startCinematic(subjectOwnershipDefinition(data: ['cast' => []]));
  $session = $this->scene->eventInterpreter->activeSession();
  $route = new MovementRouteRunner($this->scene, ['remember' => 'entry',
    'steps' => [['direction' => 'right']]], $session);
  $route->update(0.1);
  $command = ['retrace' => 'entry'];
  match ($case) {
    'map' => $this->scene->changeMapIdentity('map-b'),
    'generation' => $this->stage->clear(false),
    'displaced' => $this->player->position->x = 9,
    'subject' => $command += ['subject' => 'npc', 'npcId' => 'guide'],
    'consumed' => (new MovementRouteRunner($this->scene, $command, $session))->cancel(),
  };
  expect(fn() => new MovementRouteRunner($this->scene, $command, $session))->toThrow(RuntimeException::class);
})->with(['map', 'generation', 'displaced', 'subject', 'consumed']);

it('pins an in-flight NPC route to its original object rather than reusing a stable id', function () {
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'waypoints' => [['x' => 10]]],
  ], ['cast' => []]));
  $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Replacement', 'sprite' => 'R', 'x' => 20, 'y' => 4]]);
  $this->scene->eventInterpreter->update(1);
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->scene->npcManager->findById('guide')->position->x)->toBe(20.0);
});

it('treats the player as an occupied tile when planning an NPC waypoint leg', function () {
  $this->player->position->x = 8;
  $steps = MovementRoutePlanner::plan($this->scene, $this->npc->position, new Vector2(9, 4), true);
  expect($steps)->toBe([['direction' => 'up'], ['direction' => 'right'], ['direction' => 'right'], ['direction' => 'down']]);
});

it('identifies unavailable movement subjects and their map', function (array $subject, string $label) {
  expect(fn() => new MovementRouteRunner($this->scene, [
    ...$subject, 'steps' => [['direction' => 'right']],
  ]))->toThrow(RuntimeException::class, sprintf(
    'Movement-route %s is not available on map "map-a".', $label,
  ));
})->with([
  'missing NPC' => [['subject' => 'npc', 'npcId' => 'missing-npc'], 'NPC "missing-npc"'],
  'missing staged actor' => [['subject' => 'staged_actor', 'actorId' => 'missing-stage'], 'staged actor "missing-stage"'],
]);

it('does not let repeated cancellation release a later movement owner', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition(data: ['cast' => []]));
  $session = $this->scene->eventInterpreter->activeSession();
  $command = ['steps' => [['direction' => 'right']]];
  $first = new MovementRouteRunner($this->scene, $command, $session);
  $first->update(1);
  $second = new MovementRouteRunner($this->scene, $command, $session);
  $first->cancel();
  expect(fn() => new MovementRouteRunner($this->scene, $command, $session))->toThrow(RuntimeException::class);
  $second->cancel();
});

it('restores a directionless entry pose after walking home', function () {
  $this->player->restoreFieldTransform(clone $this->player->position, MovementHeading::NONE, ['@']);
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'remember' => 'approach', 'steps' => [['direction' => 'right']]],
    ['type' => 'move_route', 'retrace' => 'approach'],
  ], ['cast' => []]));
  for ($tick = 0; $tick < 4; $tick++) {
    $this->scene->eventInterpreter->update(1);
  }
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and($this->player->heading)->toBe(MovementHeading::NONE)->and($this->player->sprite)->toBe(['@']);
});

it('validates both waypoint endpoints before flattening map coordinates', function (float $x, float $y) {
  $origin = new Vector2();
  $origin->x = $x;
  $origin->y = $y;
  expect(fn() => MovementRoutePlanner::plan($this->scene, $origin, new Vector2(0, 1), false))
    ->toThrow(RuntimeException::class);
})->with([[60.0, 0.0], [-1.0, 1.0], [1.5, 1.0], [INF, 1.0], [1.0, NAN]]);

it('bounds waypoint search work on a large unreachable map', function () {
  $this->scene->camera->worldSpaceWidth = 260;
  $this->scene->camera->worldSpaceHeight = 260;
  $this->scene->mapManager->passable = static fn(int $x, int $y): bool => $x !== 259 || $y !== 259;
  expect(fn() => MovementRoutePlanner::plan($this->scene, new Vector2(0, 0), new Vector2(259, 259), false))
    ->toThrow(RuntimeException::class, 'cell budget');
});

it('does not reuse route history in a fresh cinematic session', function () {
  $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'remember' => 'approach', 'steps' => [['direction' => 'right']]],
  ], ['cast' => []]));
  $this->scene->eventInterpreter->update(1);
  $session = $this->scene->startCinematic(subjectOwnershipDefinition([
    ['type' => 'move_route', 'retrace' => 'approach'],
  ], ['cast' => []]));
  expect($session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($session->failureMessage)->toContain('was not recorded in this session');
});

it('rejects malformed route extensions in both authoring and runtime', function (array $command) {
  $command = ['type' => 'move_route', ...$command];
  expect(fn() => CinematicScriptValidator::validate([$command]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new MovementRouteRunner($this->scene, $command))->toThrow(RuntimeException::class);
})->with([
  [['waypoints' => []]], [['waypoints' => [['x' => 2.5]]]], [['waypoints' => [['x' => -1]]]],
  [['waypoints' => [['z' => 3]]]], [['waypoints' => [['x' => 3]], 'steps' => [['direction' => 'up']]]],
  [['retrace' => 'entry', 'remember' => 'other']], [['retrace' => '../entry']],
  [['subject' => 'staged_actor', 'actorId' => 'pose', 'waypoints' => [['x' => 1]]]],
  [['waypoints' => [['x' => 3]], 'secondsPerStep' => INF]],
  [['waypoints' => [['x' => 3]], 'speed' => NAN]],
  [['steps' => [['direction' => 'up', 'seconds' => INF]]]],
]);

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

it('records and retraces real subjects in ordinary execution sessions without acquiring cinematic rollback', function (bool $npc, bool $reduced) {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => $reduced]]);
  $subject = $npc ? $this->npc : $this->player;
  $start = clone $subject->position;
  $heading = $subject->heading;
  $binding = $npc ? ['subject' => 'npc', 'npcId' => 'guide'] : ['subject' => 'player'];
  $generation = $this->stage->generation;
  $this->scene->mapManager->passable = static fn(int $x, int $y): bool => $x !== intval($start->x) + 1 || $y !== intval($start->y);
  $commands = [
    ['type' => 'move_route', ...$binding, 'remember' => 'outbound', 'secondsPerStep' => .1,
      'waypoints' => [['x' => intval($start->x) + 2], ['y' => intval($start->y) + 1]]],
    ['type' => 'move_route', ...$binding, 'retrace' => 'outbound', 'secondsPerStep' => .1],
    ['type' => 'wait', 'seconds' => 1],
  ];
  $session = $this->scene->eventInterpreter->run($commands, $npc ? 'npc:guide' : 'map:event');
  $positions = [[$start->x, $start->y]];
  for ($tick = 0; $tick < 60 && ($session->pendingCommand['type'] ?? '') !== 'wait'; ++$tick) {
    $this->scene->eventInterpreter->update(.1);
    $position = [$subject->position->x, $subject->position->y];
    if ($positions[array_key_last($positions)] !== $position) { $positions[] = $position; }
  }
  expect($session->cinematic)->toBeNull()->and($session->failureMessage)->toBeNull()
    ->and($session->pendingCommand['type'])->toBe('wait')->and($session->movementRoute('outbound')->complete)->toBeTrue()
    ->and([$subject->position->x, $subject->position->y])->toBe([$start->x, $start->y])
    ->and($subject->heading)->toBe($heading)->and($this->stage->generation)->toBe($generation);
  if (!$reduced) {
    $middle = intdiv(count($positions), 2);
    expect(array_slice($positions, $middle + 1))->toBe(array_slice(array_reverse(array_slice($positions, 0, $middle + 1)), 1));
  }
  expect(fn() => new MovementRouteRunner($this->scene, [...$binding, 'retrace' => 'outbound'], $session))
    ->toThrow(RuntimeException::class, 'only be retraced once');
  $this->scene->eventInterpreter->update(2);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and(fn() => $session->movementRoute('outbound'))->toThrow(RuntimeException::class, 'was not recorded in this session');
  $next = $this->scene->eventInterpreter->run($commands, 'new invocation');
  expect($next->id)->not->toBe($session->id)->and($next->failureMessage)->toBeNull();
  $next->cancelLanes();
  expect(fn() => $next->movementRoute('outbound'))->toThrow(RuntimeException::class, 'was not recorded in this session');
})->with([false, true])->with([false, true]);

it('retains recorded-route identity and consumption guards in non-cinematic sessions', function (string $case) {
  $session = new EventExecutionSession([]);
  if ($case === 'missing') {
    expect(fn() => new MovementRouteRunner($this->scene, ['retrace' => 'entry'], $session))->toThrow(RuntimeException::class);
    return;
  }
  $route = new MovementRouteRunner($this->scene, ['remember' => 'entry', 'steps' => [['direction' => 'right']]], $session);
  if ($case === 'duplicate' || $case === 'incomplete' || $case === 'claim') {
    $command = match ($case) {
      'duplicate' => ['remember' => 'entry', 'steps' => [['direction' => 'right']]],
      'claim' => ['steps' => [['direction' => 'right']]],
      default => ['retrace' => 'entry'],
    };
  } else {
    $route->update(1);
    $command = ['retrace' => 'entry'];
    match ($case) {
      'map' => $this->scene->changeMapIdentity('map-b'),
      'generation' => $this->stage->clear(false),
      'displaced' => $this->player->position->x = 9,
      'subject' => $command += ['subject' => 'npc', 'npcId' => 'guide'],
      'replaced' => new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, clone $this->player),
      'consumed' => (new MovementRouteRunner($this->scene, $command, $session))->cancel(),
    };
  }
  expect(fn() => new MovementRouteRunner($this->scene, $command, $session))->toThrow(RuntimeException::class);
})->with(['missing', 'duplicate', 'incomplete', 'claim', 'map', 'generation', 'displaced', 'subject', 'replaced', 'consumed']);

it('keeps ordinary replay collision-aware and retains only successful units before cleanup', function () {
  $session = new EventExecutionSession([]);
  $outbound = new MovementRouteRunner($this->scene, ['remember' => 'entry', 'steps' => [['direction' => 'right', 'count' => 2]]], $session);
  $outbound->update(1);
  $this->scene->mapManager->blocked = true;
  expect(fn() => $outbound->update(1))->toThrow(RuntimeException::class, 'was blocked')
    ->and($this->player->position->x)->toBe(4.0)->and($session->movementRoute('entry')->complete)->toBeFalse();
  $outbound->cancel();
  $session->cancelLanes();
  expect(fn() => $session->movementRoute('entry'))->toThrow(RuntimeException::class);
  $this->scene->mapManager->blocked = false;
  $session = new EventExecutionSession([]);
  (new MovementRouteRunner($this->scene, ['remember' => 'entry', 'steps' => [['direction' => 'right']]], $session))->update(1);
  $return = new MovementRouteRunner($this->scene, ['retrace' => 'entry'], $session);
  $this->scene->mapManager->blocked = true;
  expect(fn() => $return->update(1))->toThrow(RuntimeException::class, 'was blocked')->and($this->player->position->x)->toBe(5.0);
  $return->cancel();
  $session->cancelLanes();
  expect(fn() => $session->movementRoute('entry'))->toThrow(RuntimeException::class);
});

it('still refuses recorded routes without a session or for staged actors', function () {
  expect(fn() => new MovementRouteRunner($this->scene, ['remember' => 'entry', 'steps' => [['direction' => 'right']]]))
    ->toThrow(RuntimeException::class, 'event execution session');
  $this->stage->add(subjectOwnershipCast());
  foreach (['remember' => 'entry', 'retrace' => 'entry'] as $key => $id) {
    $command = ['subject' => 'staged_actor', 'actorId' => 'pose', $key => $id];
    if ($key === 'remember') { $command['steps'] = [['direction' => 'right']]; }
    expect(fn() => new MovementRouteRunner($this->scene, $command, new EventExecutionSession([])))->toThrow(RuntimeException::class);
  }
});

it('uses ordinary recording through actual NPC talk and map trigger entry points', function (bool $npc) {
  $binding = $npc ? ['subject' => 'npc', 'npcId' => 'guide'] : ['subject' => 'player'];
  $commands = [
    ['type' => 'move_route', ...$binding, 'remember' => 'approach', 'secondsPerStep' => 0,
      'steps' => [['direction' => 'right']]],
    ['type' => 'move_route', ...$binding, 'retrace' => 'approach', 'secondsPerStep' => 0],
    ['type' => 'wait', 'seconds' => 1],
  ];
  if ($npc) {
    $this->scene->npcManager->configure([['id' => 'guide', 'name' => 'Guide', 'sprite' => 'G',
      'x' => 7, 'y' => 4, 'script' => $commands]]);
    $subject = $this->scene->npcManager->findById('guide');
    $subject->talk($this->scene);
    $subject->talk($this->scene);
    $session = $this->scene->eventInterpreter->activeSession();
    expect($session->origin['npc'])->toBe('guide');
  } else {
    $subject = $this->player;
    $trigger = new \Ichiloto\Engine\Events\Triggers\ScriptEventTrigger(new Rect(0, 0, 1, 1),
      ['script' => $commands], mapId: 'map-a', marker: 'E');
    $trigger->bind($this->scene->gameState, $this->scene->party);
    $session = $trigger->startSession($this->scene);
    expect($trigger->startSession($this->scene))->toBeNull()->and($session->origin['marker'])->toBe('E');
  }
  $start = clone $subject->position;
  for ($tick = 0; $tick < 10 && ($session->pendingCommand['type'] ?? '') !== 'wait'; ++$tick) {
    $this->scene->eventInterpreter->update(0);
  }
  expect($session->cinematic)->toBeNull()->and($session->failureMessage)->toBeNull()
    ->and($session->pendingCommand['type'])->toBe('wait')
    ->and([$subject->position->x, $subject->position->y])->toBe([$start->x, $start->y]);
  $this->scene->eventInterpreter->update(2);
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and(fn() => $session->movementRoute('approach'))->toThrow(RuntimeException::class);
})->with([false, true]);
