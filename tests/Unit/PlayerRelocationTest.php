<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Timers;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicController;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Actions\FieldActionContext;
use Ichiloto\Engine\Entities\Actions\SleepAction;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interpreter\Commands\InnCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\MovementEvent;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\SleepEventTrigger;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\CharacterStep;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\UIManager;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class RelocationTestGame extends Game
{
  public function __construct() { $this->audioManager = new RecordingAudioManager($this); }
  public function __destruct() {}
}

final class RelocationTestPlayer extends Player
{
  public int $arrivals = 0;
  public int $notifications = 0;
  public function renderEventCues(): void
  {
    Console::withLayer('synthetic-cue', fn() => $this->scene->camera->renderOnScreen(['C'], new Vector2(41, 23)));
  }
  protected function handleTriggers(MovementEvent $event): void { $this->arrivals++; }
  public function notify(object $entity, EventInterface $event): void { $this->notifications++; }
}

final class RelocationTestMap extends MapManager
{
  public int $renders = 0;
  public int $steps = 0;
  public function __construct(Game $game, GameScene $scene) { parent::__construct($game, $scene); }
  public function render(?int $x = null, ?int $y = null): void { $this->renders++; parent::render($x, $y); }
  public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool
  { $this->steps++; return true; }
  public function scrollMap(Player $player, Vector2 $direction): bool { return false; }
}

final class RelocationTestPresentation implements EventPresentationInterface
{
  public function beginText(string $text, string $name = ''): void {}
  public function beginChoice(string $prompt, array $options, string $title = ''): void {}
  public function update(): void {}
  public function render(): void { Console::withLayer('synthetic-dialogue', fn() => Console::write('D', 23, 0)); }
  public function isComplete(): bool { return false; }
  public function choiceResult(): ?int { return null; }
  public function reset(): void {}
}

final class RelocationTestModals extends ModalManager
{
  public function __construct() {}
  public function select(string $message, array $options, string $title = '', int $default = 0,
    ?Vector2 $position = null, int $width = DEFAULT_SELECT_DIALOG_WIDTH, int $height = DEFAULT_SELECT_DIALOG_HEIGHT): int
  { return 0; }
}

final class RelocationTestScene extends GameScene
{
  public int $compositions = 0;
  public int $autoSaves = 0;
  public function __construct(Game $game, string $root)
  {
    $this->sceneManager = makeBareScene(SceneManager::class);
    new ReflectionProperty(SceneManager::class, 'game')->setValue($this->sceneManager, $game);
    $this->sceneManager->currentScene = $this;
    $this->currentMapId = 'synthetic-relocation';
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->party->credit(50);
    $this->party->addMember(new Character('Synthetic guest', 0,
      new Stats(currentHp: 5, currentMp: 1, totalHp: 100, totalMp: 20)));
    $this->camera = new Camera($this, 24, 8, worldSpace: array_fill(0, 40, str_repeat('.', 70)));
    $this->mapManager = new RelocationTestMap($game, $this);
    $this->uiManager = new class extends UIManager {
      public function __construct() {}
      public function render(): void { Console::withLayer('synthetic-hud', fn() => Console::write('H', 0, 0)); }
    };
    $this->cinematicStage = new CinematicStageManager($this);
    $this->cinematicPresentation = new CinematicPresentationManager($this);
    $this->cinematicController = new CinematicController($this);
    $this->eventInterpreter = new EventInterpreter($this, new RelocationTestPresentation());
    $this->player = new RelocationTestPlayer($this, 'Synthetic player', new Vector2(3, 4), new Rect(0, 0, 1, 1), ['v'],
      MovementHeading::SOUTH, graphicalSprites: CharacterSheet::fromArray(characterSheetData()), assetRoot: $root);
    $this->npcManager = new NpcManager($this);
    $this->npcManager->configure([['id' => 'neighbor', 'name' => 'Synthetic neighbor', 'sprite' => 'N', 'x' => 43, 'y' => 22]]);
    $this->camera->attach($this->player);
    $this->fieldState = new class(new SceneStateContext($this)) extends FieldState {
      public function renderTheField(bool $forceFullRepaint = false): void
      {
        $this->getGameScene()->compositions++;
        parent::renderTheField($forceFullRepaint);
      }
    };
    $this->state = $this->fieldState;
  }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void {}
  public function autoSave(): void { $this->autoSaves++; }
  public function renderBackgroundTile(int $x, int $y): void { $this->camera->renderOnScreen(['.'], new Vector2($x, $y)); }
  public function removeFieldForTest(): void { $this->fieldState = null; $this->state = null; }
}

beforeEach(function () {
  $this->savedStatics = [];
  foreach ([Console::class, Timers::class, ConfigStore::class, AudioManager::class, ModalManager::class,
    EventManager::class, Debug::class] as $class) {
    $this->savedStatics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Timers::clear();
  Timers::setFrameTick(null);
  Console::setTerminalOutputEnabled(false);
  Console::enterAlternateScreen();
  Console::setLayerTracking(true);
  Console::syncDimensions(24, 8);
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]], 'inn' => ['sleep_time' => 1],
    'audio' => ['music' => false, 'sfx' => false, 'voice' => false, 'master_volume' => 0]]);
  ConfigStore::put(PlaySettings::class, new SceneAudioConfigStub(['screen' => ['width' => 24, 'height' => 8]]));
  $this->root = createTestDirectory('ichiloto-relocation-');
  Debug::configure(['log_directory' => $this->root . '/logs']);
  writeCharacterSheetPng($this->root . '/' . characterSheetData()['sheet'], 48, 48);
  $this->game = new RelocationTestGame();
  $this->audio = $this->game->audioManager;
  new ReflectionProperty(Console::class, 'game')->setValue(null, $this->game);
  new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, $this->audio);
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, new RelocationTestModals());
  $this->scene = new RelocationTestScene($this->game, $this->root);
});

afterEach(function () {
  $this->scene->cinematicStage->clear(false);
  $this->scene->cinematicPresentation->clear();
  foreach ($this->savedStatics as $class => $properties) {
    foreach ($properties as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
});

it('immediately relocates through move_player and the canonical field compositor without a walking outcome', function (bool $graphical) {
  $this->scene->setPresentationContext(new ScenePresentationContext(new RendererGridConfig(24, 8, 8, 16),
    static fn(string $capability): bool => false, graphical: $graphical, assetRoot: $this->root));
  $this->scene->cinematicStage->add(['id' => 'decoration', 'sprite' => 'S', 'x' => 44, 'y' => 24]);
  Console::write('stale', 0, 7);
  $session = $this->scene->eventInterpreter->run([['type' => 'move_player', 'x' => 42, 'y' => 22],
    ['type' => 'wait', 'seconds' => 10]]);
  $player = $this->scene->player;
  expect($this->scene->camera->screen->contains($player->position))->toBeTrue()
    ->and($this->scene->compositions)->toBe(1)->and($this->scene->mapManager->renders)->toBe(1)
    ->and([$player->position->x, $player->position->y])->toEqual([42, 22]);
  $rows = implode('', Console::getBuffer());
  foreach (['v', 'N', 'C', 'S', 'H', 'D'] as $glyph) { expect($rows)->toContain($glyph); }
  expect($rows)->not->toContain('stale')->and($session->status)->toBe(EventExecutionStatus::YIELDED)
    ->and([$player->arrivals, $player->notifications, $this->scene->mapManager->steps, $this->scene->autoSaves])->toBe([0, 0, 0, 0]);
  $sprite = new GraphicalSpriteProjector()->project($player, $this->scene->camera);
  $screen = $this->scene->camera->getScreenSpacePosition($player->position);
  expect([$sprite->x, $sprite->y])->toEqual([$screen->x, $screen->y])->and($sprite->motion)->toBeNull();
})->with(['terminal composition' => [false], 'graphical projection' => [true]]);

it('preserves a deliberately detached camera through immediate script relocation', function () {
  $this->scene->camera->detach();
  $this->scene->camera->moveTo(2, 3);
  $before = $this->scene->camera->captureState();
  $this->scene->eventInterpreter->run([['type' => 'move_player', 'x' => 42, 'y' => 22]]);
  expect($this->scene->camera->captureState())->toEqual($before)->and($this->scene->compositions)->toBe(1);
});

it('discards an obsolete walking slide and arrival when immediately placed on that slide endpoint', function (bool $bound) {
  $player = $this->scene->player;
  $animation = new ReflectionProperty(Player::class, 'walkAnimation')->getValue($player);
  $from = Vector2::sum($player->position, Vector2::left());
  if ($bound) {
    $actor = $this->scene->cinematicStage->add(['id' => 'body', 'subject' => ['kind' => 'player'], 'sprite' => '@',
      'sprites2d' => characterSheetData()]);
  }
  $step = new CharacterStep($from, $player->position, .25);
  $animation->step($step);
  $this->scene->cinematicStage->subjectMoved($player, $step);
  $arrivals = 0;
  new ReflectionProperty(Player::class, 'pendingArrival')->setValue($player, function () use (&$arrivals) { $arrivals++; });
  expect($bound ? $actor->getGraphicalSpriteMotion() : $player->getGraphicalSpriteMotion())->not->toBeNull();
  $this->scene->eventInterpreter->run([['type' => 'move_player', 'x' => (int)$player->position->x,
    'y' => (int)$player->position->y]]);
  expect($animation->getPattern())->toBe(CharacterWalkAnimation::PATTERNS[0])
    ->and($bound ? $actor->getGraphicalSpriteMotion() : $player->getGraphicalSpriteMotion())->toBeNull();
  $player->advanceGraphicalAnimation(1);
  $player->completeArrival();
  expect($animation->getPattern())->toBe(CharacterWalkAnimation::PATTERNS[0])->and($arrivals)->toBe(0)
    ->and($bound ? $actor->getGraphicalSpriteMotion() : $player->getGraphicalSpriteMotion())->toBeNull();
})->with(['player sheet' => [false], 'leased visual' => [true]]);

it('preserves authored finalizer transforms through completion and skip without changing detached camera ownership', function (bool $skip, bool $detached) {
  if ($detached) { $this->scene->camera->detach(); $this->scene->camera->moveTo(2, 3); }
  $before = $this->scene->camera->captureState();
  $definition = CinematicDefinition::fromArrays(['id' => 'synthetic-finalizer', 'name' => 'Synthetic finalizer',
    'cast' => [['id' => 'body', 'subject' => ['kind' => 'player'], 'sprite' => '@']],
    'skip' => ['policy' => 'authored'], 'finalizer' => [['type' => 'move_player', 'x' => 42, 'y' => 22]]],
    [['type' => 'wait', 'seconds' => 1]]);
  $session = $this->scene->startCinematic($definition);
  if ($skip) { expect($this->scene->skipCinematic())->toBeTrue(); }
  else { $this->scene->eventInterpreter->update(2); }
  expect($session->status)->toBe(EventExecutionStatus::COMPLETED)
    ->and([$this->scene->player->position->x, $this->scene->player->position->y])->toEqual([42, 22])
    ->and($this->scene->cinematicStage->all())->toBeEmpty()->and($this->scene->autoSaves)->toBe(0);
  if ($detached) { expect($this->scene->camera->captureState())->toEqual($before); }
  else { expect($this->scene->camera->screen->contains($this->scene->player->position))->toBeTrue(); }
})->with([[false, false], [true, false], [false, true], [true, true]]);

it('hands both inn wake routes to the same immediate placement without steps saves or audio backends', function (string $entry, bool $detached) {
  if ($detached) { $this->scene->camera->detach(); $this->scene->camera->moveTo(2, 3); }
  $before = $this->scene->camera->captureState();
  $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'cost' => 10,
    'spawnPoint' => ['x' => 42, 'y' => 22], 'spawnSprite' => ['south'], 'resultVariable' => 'outcome'];
  if ($entry === 'inn-command') {
    (new InnCommand())->execute(new ScriptCommandContext($this->scene), $data);
    expect($this->scene->gameState->getVariable('outcome'))->toBe('stayed');
  } else {
    $trigger = EventTriggerFactory::create(['class' => SleepEventTrigger::class, 'area' => ['cells' => [[3, 4]]], 'data' => $data]);
    $this->scene->player->availableAction = new SleepAction($trigger);
    $this->scene->player->availableAction->execute(new FieldActionContext($this->scene->player, $this->scene, $this->scene->player->position));
  }
  $player = $this->scene->player;
  expect([$player->position->x, $player->position->y])->toEqual([42, 22])->and($player->heading)->toBe(MovementHeading::SOUTH)
    ->and($player->availableAction)->toBeNull()->and($this->scene->party->accountBalance)->toBe(40)
    ->and($this->scene->party->members->toArray()[0]->stats->currentHp)->toBe(100)
    ->and($this->scene->compositions)->toBe(1)->and(implode('', Console::getBuffer()))->toContain('H')
    ->and([$player->arrivals, $player->notifications, $this->scene->mapManager->steps, $this->scene->autoSaves])->toBe([0, 0, 0, 0])
    ->and(new ReflectionProperty(AudioManager::class, 'backends')->getValue($this->audio))->toBeEmpty();
  if ($detached) { expect($this->scene->camera->captureState())->toEqual($before); }
  else { expect($this->scene->camera->screen->contains($player->position))->toBeTrue(); }
})->with([['inn-command', false], ['sleep-action', false], ['inn-command', true], ['sleep-action', true]]);

it('uses the shared lightweight field fallback when no FieldState compositor is installed', function () {
  $this->scene->removeFieldForTest();
  $this->scene->cinematicStage->add(['id' => 'decoration', 'sprite' => 'S', 'x' => 44, 'y' => 24]);
  $this->scene->eventInterpreter->run([['type' => 'move_player', 'x' => 42, 'y' => 22]]);
  expect($this->scene->camera->screen->contains($this->scene->player->position))->toBeTrue()
    ->and($this->scene->compositions)->toBe(0)->and($this->scene->mapManager->renders)->toBe(1);
  foreach (['v', 'N', 'C', 'S'] as $glyph) { expect(implode('', Console::getBuffer()))->toContain($glyph); }
});
