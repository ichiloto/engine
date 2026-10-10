<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicSubjectResolver;
use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\ObserverInterface;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityPipeline;
use Ichiloto\Engine\IO\SaveManager;
use Ichiloto\Engine\IO\Saves\SaveSlot;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

final class LeaderFieldAppearanceTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class LeaderFieldAppearanceScene extends GameScene
{
  public function __construct(private Game $testGame) {}
  public function getGame(): Game { return $this->testGame; }
}

final class LeaderFieldAppearanceMap extends MapManager
{
  public function __construct(GameScene $scene) { $this->gameScene = $scene; }
  public function canMoveTo(int $x, int $y, ?CollisionType &$collisionType = null): bool
  {
    $collisionType = CollisionType::NONE;
    return true;
  }
  public function scrollMap(Player $player, Vector2 $moveDirection): bool { return false; }
  public function erase(?int $x = null, ?int $y = null): void {}
}

function createLeaderFieldActor(string $id, mixed $fieldRole): ActorDefinition
{
  $data = (require __DIR__ . '/../Fixtures/Actors/FoundationHero.php')['data'];
  $data['id'] = $id;
  $data['name'] = 'Same display name';
  $data['images'] = ['field' => ['authored terminal actor'], 'field2d' => $fieldRole];
  return ActorDefinition::fromArray($data, 'synthetic actor ' . $id);
}

beforeEach(function () {
  $this->states = [];
  foreach ([ConfigStore::class, Debug::class, EventManager::class, Console::class, Cursor::class, SaveManager::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  $this->oldDirectory = getcwd();
  $this->root = createTestDirectory('ichiloto-leader-field-');
  mkdir($this->root . '/assets/Data/Entities', 0700, true);
  chdir($this->root);
  Debug::configure(['log_directory' => $this->root . '/logs']);
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(20, 10);
  ob_start();

  $this->firstRole = characterSheetData('Graphics/Unrelated.png', 0, 100);
  $this->secondRole = characterSheetData('Graphics/$Another.png', 0, 100);
  $this->fixedRole = characterSheetData('Graphics/FixedPlayer.png', 1, 100);
  writeCharacterSheetPng($this->root . '/assets/' . $this->firstRole['sheet'], 16, 20);
  writeCharacterSheetPng($this->root . '/assets/' . $this->secondRole['sheet'], 12, 18);
  writeCharacterSheetPng($this->root . '/assets/' . $this->fixedRole['sheet'], 9, 13);
  $this->store = new ActorStore(definitions: [
    createLeaderFieldActor('actor.first', $this->firstRole),
    createLeaderFieldActor('actor.second', $this->secondRole),
  ]);
  ConfigStore::put(ActorStore::class, $this->store);
  $this->setFirstFieldRole = function (mixed $role): void {
    $this->store = new ActorStore(definitions: [createLeaderFieldActor('actor.first', $role),
      createLeaderFieldActor('actor.second', $this->secondRole)]);
    ConfigStore::put(ActorStore::class, $this->store);
  };
  $this->party = new Party();
  foreach ($this->store->getActorIds() as $id) {
    $this->party->addMember($this->store->require($id, 'synthetic party')->createCharacter());
  }
  $this->sprites = ['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => ['<']];
  $this->playerData = [
    'sprites' => $this->sprites,
    'graphicalSubject' => 'party-leader',
    'sprites2d' => $this->fixedRole,
  ];
  $this->writePlayerData = function (): void {
    file_put_contents($this->root . '/assets/Data/Entities/player.php', '<?php return ' . var_export($this->playerData, true) . ';');
  };
  ($this->writePlayerData)();
  $this->game = new LeaderFieldAppearanceTestGame();
  $this->scene = new LeaderFieldAppearanceScene($this->game);
  new ReflectionProperty(GameScene::class, 'party')->setValue($this->scene, $this->party);
  new ReflectionProperty(GameScene::class, 'gameState')->setValue($this->scene, new GameState());
  $this->camera = new Camera($this->scene, 20, 10, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->config = new GameConfig('synthetic-map', $this->party, new Vector2(7, 4), new Rect(0, 0, 1, 1),
    MovementHeading::SOUTH, playerSprite: ['v'], playerSprites: $this->sprites);
  $this->createPlayer = fn(GameConfig $config): Player => new ReflectionMethod(GameScene::class, 'createPlayer')->invoke($this->scene, $config);
  $this->player = ($this->createPlayer)($this->config);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
  $this->camera->attach($this->player);
  $map = new LeaderFieldAppearanceMap($this->scene);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  new ReflectionProperty(GameScene::class, 'npcManager')->setValue($this->scene, new NpcManager($this->scene));
  new ReflectionProperty(GameScene::class, 'cinematicStage')->setValue($this->scene, new CinematicStageManager($this->scene));
});

afterEach(function () {
  ob_end_clean();
  chdir($this->oldDirectory);
  foreach ($this->states as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('follows live member order with stable player control camera geometry and terminal roles', function () {
  $identity = spl_object_id($this->player);
  $position = $this->player->position;
  $shape = $this->player->getShape();
  $cameraState = $this->camera->captureState();
  $save = serialize($this->config);
  foreach ([[0, -1], [1, 0], [0, 1], [-1, 0]] as [$x, $y]) {
    $this->player->updatePlayerSprite(new Vector2($x, $y));
    $heading = $this->player->heading;
    $terminal = $this->player->sprite;
    $row = \Ichiloto\Engine\Rendering\Sprites\CharacterSheet::getDirectionRow($heading);
    expect($this->player->getGraphicalSpriteDefinition()->asset)->toBe($this->firstRole['sheet']);
    $this->party->swapMembers(0, 1);
    $frame = $this->player->getGraphicalSpriteDefinition();
    expect($frame->asset)->toBe($this->secondRole['sheet'])
      ->and($frame->sourceRect->toArray())->toBe(['x' => 12, 'y' => $row * 18, 'width' => 12, 'height' => 18])
      ->and(spl_object_id($this->scene->player))->toBe($identity)
      ->and($this->player->position)->toBe($position)->and($this->player->getShape())->toBe($shape)
      ->and($this->player->heading)->toBe($heading)->and($this->player->sprite)->toBe($terminal)
      ->and($this->player->getDirectionalSprites())->toBe($this->sprites)
      ->and($this->player->getGraphicalSpriteId())->toBe('player')
      ->and($this->camera->captureState())->toEqual($cameraState);
    $this->party->swapMembers(0, 1);
  }
  expect(serialize($this->config))->toBe($save)
    ->and($save)->not->toContain('field2d', 'CharacterSheet', 'Unrelated.png', 'Another.png');
});

it('keeps legacy fixed-player art static through live party and actor catalog changes', function (?string $selector) {
  if ($selector === null) {
    unset($this->playerData['graphicalSubject']);
  } else {
    $this->playerData['graphicalSubject'] = $selector;
  }
  ($this->writePlayerData)();
  $player = ($this->createPlayer)($this->config);
  $saved = serialize($this->config);
  foreach ([Vector2::up(), Vector2::right(), Vector2::down(), Vector2::left()] as $direction) {
    $player->updatePlayerSprite($direction);
    $before = $player->getGraphicalSpriteDefinition();
    $this->party->swapMembers(0, 1);
    expect($player->getGraphicalSpriteDefinition())->toEqual($before)
      ->and($before->asset)->toBe($this->fixedRole['sheet']);
    $this->party->swapMembers(0, 1);
  }
  ($this->setFirstFieldRole)(null);
  ConfigStore::remove(ActorStore::class);
  $this->party->members->removeAll(static fn(): bool => true);
  expect($player->getGraphicalSpriteDefinition()->asset)->toBe($this->fixedRole['sheet'])
    ->and(is_file($this->root . '/logs/warning.log'))->toBeFalse();
  // The party edit is gameplay state, but presentation ownership never enters saves.
  expect($saved)->not->toContain('graphicalSubject', 'sprites2d', 'CharacterSheet', $this->fixedRole['sheet']);
})->with(['absent selector' => [null], 'explicit fixed-player' => ['fixed-player']]);

it('refuses an invalid selector even when both candidate owners have valid artwork', function () {
  $this->playerData['graphicalSubject'] = 'unknown-owner';
  ($this->writePlayerData)();
  $player = ($this->createPlayer)($this->config);
  $this->party->swapMembers(0, 1);
  expect($player->getGraphicalSpriteDefinition())->toBeNull()
    ->and($player->getGraphicalCharacterSheet())->toBeNull()
    ->and($player->getDirectionalSprites())->toBe($this->sprites)
    ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('Player graphicalSubject is invalid');
});

it('preserves an in-flight step its animation and its exactly-once arrival across a leader swap', function () {
  $observer = new class implements ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, EventInterface $event): void { $this->events[] = $event; }
  };
  $this->player->addObserver($observer);
  expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeTrue();
  $motion = $this->player->getGraphicalSpriteMotion();
  $this->player->advanceGraphicalAnimation(.01);
  $pattern = $this->player->getGraphicalSpriteDefinition()->sourceRect->x / 16;
  $this->party->swapMembers(0, 1);
  $this->player->completeArrival();
  expect($observer->events)->toBe([])
    ->and($this->player->getGraphicalSpriteMotion())->toEqual($motion)
    ->and($this->player->getGraphicalSpriteDefinition()->sourceRect->x / 12)->toBe($pattern)
    ->and($this->player->heading)->toBe(MovementHeading::EAST)
    ->and($this->player->position->x)->toBe(8.0);
  $this->player->advanceGraphicalAnimation(1);
  $this->player->completeArrival();
  $this->player->completeArrival();
  expect($observer->events)->toHaveCount(1)->and($observer->events[0]->destination->x)->toBe(8.0);
});

it('keeps terminal arrivals immediate in both graphical ownership modes', function (string $selector) {
  if ($selector === 'fixed-player') {
    $this->playerData['graphicalSubject'] = $selector;
    ($this->writePlayerData)();
    $this->player = ($this->createPlayer)($this->config);
    new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
  }
  Console::setTerminalOutputEnabled(true);
  $observer = new class implements ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, EventInterface $event): void { $this->events[] = $event; }
  };
  $this->player->addObserver($observer);
  $this->party->swapMembers(0, 1);
  expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeTrue()
    ->and($observer->events)->toHaveCount(1)->and($this->player->sprite)->toBe(['>']);
  $this->player->completeArrival();
  expect($observer->events)->toHaveCount(1);
})->with(['party-leader', 'fixed-player']);

it('keeps reduced-motion arrivals immediate and leader changes on the standing frame', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  $observer = new class implements ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, EventInterface $event): void { $this->events[] = $event; }
  };
  $this->player->addObserver($observer);
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue();
  $this->party->swapMembers(0, 1);
  expect($observer->events)->toHaveCount(1)
    ->and($this->player->getGraphicalSpriteMotion())->toBeNull()
    ->and($this->player->getGraphicalSpriteDefinition()->sourceRect->toArray())
    ->toBe(['x' => 12, 'y' => 54, 'width' => 12, 'height' => 18]);
});

it('starts an artless step without a slide while preserving a prior pending arrival across the artwork change', function () {
  $observer = new class implements ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, EventInterface $event): void { $this->events[] = $event; }
  };
  $this->player->addObserver($observer);
  expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeTrue();
  ($this->setFirstFieldRole)(null);
  $this->player->completeArrival();
  expect($observer->events)->toBe([]);
  expect($this->player->tryMove(Vector2::right(), $this->camera))->toBeTrue()
    ->and($observer->events)->toHaveCount(2)
    ->and(array_map(static fn($event): float => $event->destination->x, $observer->events))->toBe([8.0, 9.0])
    ->and($this->player->getGraphicalSpriteDefinition())->toBeNull()
    ->and($this->player->getGraphicalSpriteMotion())->toBeNull();
  $this->player->completeArrival();
  expect($observer->events)->toHaveCount(2);
});

it('loads new and saved games through GameLoader with configured ownership and current art without saving artwork', function (?string $selector, ?string $loadedSelector) {
  if ($selector === null) {
    unset($this->playerData['graphicalSubject']);
  } else {
    $this->playerData['graphicalSubject'] = $selector;
  }
  ($this->writePlayerData)();
  $followsLeader = $selector === 'party-leader';
  file_put_contents($this->root . '/assets/Data/system.php', '<?php return ' . var_export([
    'title' => 'Synthetic', 'currency' => ['amount' => 0],
    'startingParty' => ['actor.second', 'actor.first'], 'startingInventory' => [],
    'startingPositions' => ['player' => ['destinationMap' => 'synthetic-map',
      'spawnPoint' => ['x' => 7, 'y' => 4], 'spawnSprite' => 'West']],
  ], true) . ';');
  $loader = makeBareScene(GameLoader::class);
  new ReflectionProperty(GameLoader::class, 'game')->setValue($loader, $this->game);
  new ReflectionProperty(GameLoader::class, 'actorStore')->setValue($loader, $this->store);
  file_put_contents($this->root . '/assets/Data/items.php', '<?php return [];');
  new ReflectionProperty(GameLoader::class, 'itemStore')->setValue($loader, new ItemStore());
  $new = $loader->loadNewGame();
  expect(($this->createPlayer)($new)->getGraphicalSpriteDefinition()->asset)
    ->toBe($followsLeader ? $this->secondRole['sheet'] : $this->fixedRole['sheet']);
  $new->party->swapMembers(0, 1);
  $path = $this->root . '/file-01.iedata';
  $pipeline = new SaveCompatibilityPipeline(SaveCompatibilityManifest::fromArray('synthetic/project', ['contentVersion' => 0]));
  $slot = new SaveSlot(1, $path, false, 'Synthetic', 'Same display name', 1, 0, 1);
  $saved = serialize($pipeline->createEnvelope($slot, $new));
  file_put_contents($path, 'IED1' . gzencode($saved));
  $manager = makeBareScene(SaveManager::class);
  new ReflectionProperty(SaveManager::class, 'compatibilityPipeline')->setValue($manager, $pipeline);
  new ReflectionProperty(SaveManager::class, 'instance')->setValue(null, $manager);
  $replacement = characterSheetData('Graphics/Replaced.png', 5, 100);
  writeCharacterSheetPng($this->root . '/assets/' . $replacement['sheet'], 10, 14);
  ($this->setFirstFieldRole)($replacement);
  $fixedReplacement = characterSheetData('Graphics/FixedReplaced.png', 2, 100);
  writeCharacterSheetPng($this->root . '/assets/' . $fixedReplacement['sheet'], 6, 9);
  $this->playerData['sprites2d'] = $fixedReplacement;
  if ($loadedSelector === null) {
    unset($this->playerData['graphicalSubject']);
  } else {
    $this->playerData['graphicalSubject'] = $loadedSelector;
  }
  ($this->writePlayerData)();
  $loaded = $loader->loadSavedGame($path);
  $player = ($this->createPlayer)($loaded);
  $followsLeader = $loadedSelector === 'party-leader';
  expect($loaded->party->leader->actorId)->toBe('actor.first')
    ->and($player->getGraphicalSpriteDefinition()->asset)->toBe($followsLeader ? $replacement['sheet'] : $fixedReplacement['sheet'])
    ->and($player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe($followsLeader
      ? ['x' => 40, 'y' => 70, 'width' => 10, 'height' => 14]
      : ['x' => 42, 'y' => 9, 'width' => 6, 'height' => 9])
    ->and($player->heading)->toBe(MovementHeading::WEST)->and($player->sprite)->toBe(['<'])
    ->and($saved)->not->toContain('graphicalSubject', 'field2d', 'CharacterSheet', 'Replaced.png', 'Another.png', 'FixedPlayer.png')
    ->and(file_get_contents($path))->toBe('IED1' . gzencode($saved));
})->with([
  'party-leader' => ['party-leader', 'party-leader'], 'fixed-player' => ['fixed-player', 'fixed-player'],
  'legacy absent selector' => [null, null], 'project opts in after saving' => ['fixed-player', 'party-leader'],
  'project selects fixed art after saving' => ['party-leader', 'fixed-player'],
]);

it('re-reads replacement image dimensions without changing actor identity or requiring duplicate metadata', function () {
  $actor = $this->party->leader;
  $saved = serialize($actor);
  expect($this->player->getGraphicalSpriteDefinition()->sourceRect->width)->toBe(16);
  writeCharacterSheetPng($this->root . '/assets/' . $this->firstRole['sheet'], 8, 11);
  touch($this->root . '/assets/' . $this->firstRole['sheet'], 1700000002);
  $frame = $this->player->getGraphicalSpriteDefinition();
  expect($frame->sourceRect->toArray())->toBe(['x' => 8, 'y' => 0, 'width' => 8, 'height' => 11])
    ->and([$frame->width, $frame->height])->toBe([48, 48])
    ->and($actor->actorId)->toBe('actor.first')->and(serialize($actor))->toBe($saved);
});

it('diagnoses missing and malformed actor roles without falling back to another identity or changing gameplay', function (mixed $role) {
  ($this->setFirstFieldRole)($role);
  $saved = serialize($this->config);
  expect($this->player->getGraphicalSpriteDefinition())->toBeNull();
  $warning = file_get_contents($this->root . '/logs/warning.log');
  expect($warning)->toContain('actor.first', 'images.field2d', 'terminal sprite');
  $this->player->getGraphicalSpriteDefinition();
  expect(file_get_contents($this->root . '/logs/warning.log'))->toBe($warning)
    ->and(serialize($this->config))->toBe($saved)->and($this->player->sprite)->toBe(['v']);
  $this->party->swapMembers(0, 1);
  expect($this->player->getGraphicalSpriteDefinition()->asset)->toBe($this->secondRole['sheet']);
})->with([
  'missing role' => [null], 'empty role' => [[]], 'scalar' => ['not-a-sheet'],
  'unsafe path' => [['sheet' => '../escape.png']], 'wrong index' => [['sheet' => 'Graphics/$Single.png', 'index' => 1]],
  'UI layer' => [['sheet' => 'Graphics/Any.png', 'layer' => 1000]],
]);

it('diagnoses missing files and recovers when replaceable artwork becomes available', function () {
  $role = characterSheetData('Graphics/NotHereYet.png', 2, 100);
  ($this->setFirstFieldRole)($role);
  expect($this->player->getGraphicalSpriteDefinition())->toBeNull()
    ->and(file_get_contents($this->root . '/logs/warning.log'))->toContain('NotHereYet.png', 'terminal sprite');
  writeCharacterSheetPng($this->root . '/assets/' . $role['sheet'], 6, 9);
  expect($this->player->getGraphicalSpriteDefinition()->asset)->toBe($role['sheet']);
});

it('diagnoses an empty party or missing actor definition and recovers without replacing Player', function (string $case) {
  $player = $this->player;
  if ($case === 'empty') {
    $this->party->members->removeAll(static fn(): bool => true);
  } elseif ($case === 'missing store') {
    ConfigStore::remove(ActorStore::class);
  } else {
    ConfigStore::put(ActorStore::class, new ActorStore(definitions: []));
  }
  expect($player->getGraphicalSpriteDefinition())->toBeNull();
  $warning = file_get_contents($this->root . '/logs/warning.log');
  $player->getGraphicalSpriteDefinition();
  expect(file_get_contents($this->root . '/logs/warning.log'))->toBe($warning);
  ConfigStore::put(ActorStore::class, $this->store);
  if ($case === 'empty') {
    $this->party->addMember($this->store->require('actor.second', 'late party population')->createCharacter());
  }
  expect($this->scene->player)->toBe($player)
    ->and($player->getGraphicalSpriteDefinition()->asset)->toBe($case === 'empty' ? $this->secondRole['sheet'] : $this->firstRole['sheet']);
})->with(['empty', 'missing store', 'missing definition']);

it('inherits configured player staging and retains explicit scripted actor identity through cleanup', function (string $selector) {
  if ($selector === 'fixed-player') {
    $this->playerData['graphicalSubject'] = $selector;
    ($this->writePlayerData)();
    $this->player = ($this->createPlayer)($this->config);
    new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
    $this->camera->attach($this->player);
  }
  $initialRole = $selector === 'party-leader' ? $this->firstRole : $this->fixedRole;
  $selectedRole = $selector === 'party-leader' ? $this->secondRole : $this->fixedRole;
  $stage = $this->scene->cinematicStage;
  $inherited = $stage->add(['id' => 'player-view', 'subject' => ['kind' => 'player']]);
  $named = $stage->add(['id' => 'scripted-first', 'sprite' => 'S', 'x' => 2, 'y' => 3, 'sprites2d' => $this->firstRole]);
  expect($inherited->getGraphicalSpriteDefinition()->asset)->toBe($initialRole['sheet'])
    ->and($this->player->getGraphicalSpriteDefinition())->toBeNull();
  $this->player->tryMove(Vector2::right(), $this->camera);
  $this->party->swapMembers(0, 1);
  expect($inherited->getGraphicalSpriteDefinition()->asset)->toBe($selectedRole['sheet'])
    ->and($inherited->facing)->toBe(MovementHeading::EAST)
    ->and($inherited->getGraphicalSpriteDefinition()->sourceRect->x)->toBe($selector === 'party-leader' ? 24 : 45)
    ->and($named->getGraphicalSpriteDefinition()->asset)->toBe($this->firstRole['sheet']);
  $explicit = $stage->add(['id' => 'player-view', 'replace' => true, 'subject' => ['kind' => 'player'], 'sprites2d' => $this->firstRole]);
  expect($explicit->getGraphicalSpriteDefinition()->asset)->toBe($this->firstRole['sheet']);
  $stage->clear();
  expect($this->player->position->x)->toBe(7.0)->and($this->player->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->player->getGraphicalSpriteDefinition()->asset)->toBe($selectedRole['sheet'])
    ->and($inherited->getGraphicalSpriteDefinition())->toBeNull()
    ->and($explicit->getGraphicalSpriteDefinition())->toBeNull()->and($named->getGraphicalSpriteDefinition())->toBeNull()
    ->and($stage->all())->toBe([])->and($this->camera->captureState()->followsPlayer)->toBeTrue();
})->with(['party-leader', 'fixed-player']);

it('resolves party subjects by stable actor id while named NPC subjects do not follow party order', function (string $selector) {
  if ($selector === 'fixed-player') {
    $this->playerData['graphicalSubject'] = $selector;
    ($this->writePlayerData)();
    $this->player = ($this->createPlayer)($this->config);
    new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $this->player);
  }
  $this->scene->npcManager->configure([['id' => 'actor.first', 'name' => 'Scripted actor', 'sprite' => 'N', 'x' => 2, 'y' => 3,
    'sprites2d' => $this->firstRole]]);
  $resolver = new CinematicSubjectResolver($this->scene);
  new ReflectionProperty(\Ichiloto\Engine\Entities\Character::class, 'name')->setValue($this->party->leader, 'actor.second');
  expect(fn() => $resolver->position(['kind' => 'party_actor', 'id' => 'actor.second']))
    ->toThrow(RuntimeException::class, 'has no field representation');
  expect($resolver->position(['kind' => 'party_actor', 'id' => 'actor.first']))->toEqual($this->player->position);
  $this->party->swapMembers(0, 1);
  expect($resolver->position(['kind' => 'party_actor', 'id' => 'actor.second']))->toEqual($this->player->position)
    ->and($resolver->position(['kind' => 'party_actor', 'id' => 'actor.first']))->toEqual(new Vector2(2, 3));
  $named = $this->scene->cinematicStage->add(['id' => 'named-view', 'subject' => ['kind' => 'npc', 'id' => 'actor.first']]);
  $this->party->swapMembers(0, 1);
  expect($named->getGraphicalSpriteDefinition()->asset)->toBe($this->firstRole['sheet'])
    ->and($named->position)->toEqual(new Vector2(2, 3));
})->with(['party-leader', 'fixed-player']);
