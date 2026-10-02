<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicStageManager;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\NpcManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\CharacterWalkAnimation;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\UI\Modal\ModalManager;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;
use function Tests\Support\Rendering\writeTestPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

final class NpcGraphicalTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

/** Real NPC, map collision, camera, scene provider, and cinematic ownership paths. */
final class NpcGraphicalTestScene extends GameScene
{
  public function __construct(private readonly Game $testGame)
  {
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->currentMapId = 'Village/Plaza';
    $this->camera = new Camera($this, 24, 8, worldSpace: array_fill(0, 12, str_repeat('.', 32)));
    $this->mapManager = makeBareScene(MapManager::class);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($this->mapManager, $this);
    new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($this->mapManager,
      array_fill(0, 12, array_fill(0, 32, CollisionType::NONE->value)));
    $this->npcManager = new NpcManager($this);
    $this->cinematicStage = new CinematicStageManager($this);
    $this->eventInterpreter = new EventInterpreter($this);
    $this->player = new Player($this, 'hero', new Vector2(2, 2), new Rect(0, 0, 1, 1), ['P']);
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $this->fieldState = new FieldState(new SceneStateContext($this));
    $this->state = $this->fieldState;
  }

  public function getGame(): Game { return $this->testGame; }

  public function renderBackgroundTile(int $x, int $y): void
  {
    $this->camera->renderOnScreen(['.'], new Vector2($x, $y));
  }

  public function changeMapIdentity(string $mapId): void { $this->currentMapId = $mapId; }

  public function renderNpcField(): void
  {
    Console::recomposeFrame(function (): void {
      for ($y = 0; $y < 8; $y++) { Console::write(str_repeat('.', 24), 0, $y); }
      $this->npcManager->render();
      $this->cinematicStage->render();
    });
  }
}

/** The NPC fixture's standard RPG Maker sheet: 4 x 6 pixel frames, so a frame rect reads as (pattern * 4, row * 6). */
const NPC_TEST_SHEET = 'People.png';

function getNpcTestEntry(array $extra = []): array
{
  return array_replace(['id' => 'guide', 'name' => 'Guide', 'sprite' => 'G', 'x' => 7, 'y' => 4,
    'sprites' => ['north' => 'N', 'east' => 'E', 'south' => 'S', 'west' => 'W'],
    'conditions' => [['type' => 'switch', 'name' => 'departed', 'value' => false]],
    'sprites2d' => characterSheetData(NPC_TEST_SHEET)], $extra);
}

beforeEach(function () {
  $this->staticBefore = [];
  foreach ([Console::class, Cursor::class, InputManager::class, ConfigStore::class,
    EventManager::class, Time::class, Debug::class, PngAssetPreflight::class, ModalManager::class] as $class) {
    $this->staticBefore[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  putSceneAudioConfig(['ui' => ['hud' => ['location' => false]]]);
  $this->root = sys_get_temp_dir() . '/ichiloto-npc-graphics-' . bin2hex(random_bytes(5));
  mkdir($this->root);
  writeCharacterSheetPng($this->root . '/' . NPC_TEST_SHEET, 4, 6);
  Debug::configure(['log_directory' => $this->root]);
  $this->transport = new FakeRendererTransport();
  // Character sheet frames are source rects, so the renderer advertises sprite_source_rect as the native renderer does.
  $this->transport->batches[] = [RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["sprite_source_rect","tile_batches"]}')];
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']),
    $this->root, protocol: RendererProtocolVersion::V2), $this->transport);
  $game = new NpcGraphicalTestGame();
  $game->useRendererRuntime($this->runtime);
  ob_start();
  Console::syncDimensions(24, 8);
  Console::setLayerTracking(true);
  $this->scene = new NpcGraphicalTestScene($game);
  $this->manager = $this->scene->npcManager;
  $this->manager->configure([getNpcTestEntry()]);
  $this->npc = $this->manager->findById('guide');
});

afterEach(function () {
  $this->runtime->shutdown();
  ob_end_clean();
  foreach ($this->staticBefore as $class => $state) {
    foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
  }
  foreach (glob($this->root . '/*') as $path) { unlink($path); }
  rmdir($this->root);
});

it('keeps legacy constructor and authored identities without inventing script ids for id-less NPCs', function () {
  $legacy = new Npc('Legacy', '@', new Vector2(1, 1));
  expect($legacy)->toBeInstanceOf(GraphicalSpriteProviderInterface::class)
    ->and($legacy->getGraphicalSpriteDefinition())->toBeNull()->and($legacy->id)->toBeNull();
  $entries = [getNpcTestEntry(), getNpcTestEntry(['id' => '', 'x' => 8]),
    getNpcTestEntry(['id' => '', 'x' => 9])];
  $this->manager->configure($entries);
  $ids = array_map(fn($npc) => $npc->getGraphicalSpriteId(), $this->manager->npcs);
  expect(array_unique($ids))->toHaveCount(3)
    ->and($ids[0])->toBe('npc:map:Village%2FPlaza:id:guide')
    ->and($this->manager->npcs[1]->id)->toBeNull()
    ->and($this->manager->findById('guide')->name)->toBe('Guide');
  $entries[0]['name'] = 'Renamed guide';
  $entries[0]['sprite'] = 'R';
  $entries[0]['x'] = 10;
  $this->manager->configure($entries);
  expect($this->manager->findById('guide')->getGraphicalSpriteId())->toBe($ids[0]);
});

it('selects every direction through the real facing caller and returns a defensive world position', function () {
  // RPG Maker direction rows: down, left, right, up.
  foreach (['up' => ['north', 3], 'right' => ['east', 2], 'down' => ['south', 0], 'left' => ['west', 1]]
    as $step => [$direction, $row]) {
    $this->manager->faceNpc('guide', MovementRouteRunner::directionVector($step));
    $definition = $this->npc->getGraphicalSpriteDefinition();
    expect($definition->asset)->toBe(NPC_TEST_SHEET)
      ->and($definition->sourceRect->toArray())->toBe(['x' => 4, 'y' => $row * 6, 'width' => 4, 'height' => 6])
      ->and([$definition->width, $definition->height])->toBe([48, 48])
      ->and($this->npc->sprite)->toBe(strtoupper($direction[0]))
      ->and([$this->npc->position->x, $this->npc->position->y])->toBe([7.0, 4.0]);
  }
  $copy = $this->npc->getGraphicalSpriteWorldPosition();
  $copy->x = 100;
  expect($this->npc->position->x)->toBe(7.0);
});

it('blocks the occupied NPC anchor at 23 5 while allowing the adjacent cell at 22 5', function () {
  $this->manager->configure([getNpcTestEntry(['id' => 'mother', 'x' => 23, 'y' => 5])]);
  $npc = $this->manager->findById('mother');
  $player = $this->scene->player;
  $player->position->x = 22;
  $player->position->y = 5;
  $collision = null;

  expect($this->scene->mapManager->canMoveTo(22, 5))->toBeTrue()
    ->and($this->scene->mapManager->canMoveTo(23, 5, $collision))->toBeFalse()
    ->and($collision)->toBe(CollisionType::NPC)
    ->and($player->tryMove(Vector2::right(), $this->scene->camera))->toBeFalse()
    ->and([$player->position->x, $player->position->y])->toBe([22.0, 5.0])
    ->and($player->heading)->toBe(MovementHeading::EAST)
    ->and($this->manager->moveNpcById('mother', Vector2::left()))->toBeFalse()
    ->and([$npc->position->x, $npc->position->y])->toBe([23.0, 5.0]);
});

it('masks only the successfully graphical NPC layer through the real scene collector and runtime', function () {
  $legacy = getNpcTestEntry(['id' => 'legacy', 'sprite' => 'L', 'x' => 8]);
  unset($legacy['sprites2d']);
  $this->manager->configure([getNpcTestEntry(), $legacy]);
  $this->runtime->start('NPC test', 24, 8);
  $this->scene->renderNpcField();
  $terminal = Console::snapshot();
  expect(new GraphicalSpriteCollector()->collect($this->scene))->toHaveCount(1);
  $this->runtime->present($this->scene);
  $frame = RetainedFrameState::replay($this->transport->sent)[0];
  $text = RetainedFrameState::getTextRows($frame, 24, 8);
  expect($frame['sprites'])->toHaveCount(1)
    ->and($frame['sprites'][0]['id'])->toBe($this->npc->getGraphicalSpriteId())
    ->and(mb_substr($text[4], 7, 1))->toBe('.')->and(mb_substr($text[4], 8, 1))->toBe('L')
    ->and(Console::charAt(7, 4))->toBe('G')->and(Console::snapshot())->toEqual($terminal);
});

it('diagnoses malformed optional art without dropping occupancy or world-state writes', function ($graphics) {
  $this->manager->configure([getNpcTestEntry(['sprites2d' => $graphics,
    'sets' => [['type' => 'switch', 'name' => 'talked', 'value' => true]]])]);
  $npc = $this->manager->findById('guide');
  $npc->talk($this->scene);
  expect($npc->getGraphicalSpriteDefinition())->toBeNull()
    ->and($this->manager->npcAt(7, 4))->toBe($npc)
    ->and($this->scene->gameState->getSwitch('talked'))->toBeTrue()
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('guide', 'Village/Plaza', 'sprites2d');
  $this->scene->renderNpcField();
  expect(Console::charAt(7, 4))->toBe('G');
  $this->runtime->start('NPC invalid definition', 24, 8);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $frame = $frames[array_key_last($frames)];
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 7, 1))->toBe('G');
})->with([
  'empty' => [[]], 'null' => [null], 'wrong type' => ['sprite.png'],
  'authored size' => [['sheet' => 'People.png', 'width' => 48, 'height' => 48]],
  'authored anchor' => [['sheet' => 'People.png', 'anchor' => 'bottom_center']],
  'per-direction images' => [['north' => ['asset' => 'north.png'], 'east' => ['asset' => 'east.png'],
    'south' => ['asset' => 'south.png'], 'west' => ['asset' => 'west.png']]],
  'index out of range' => [['sheet' => 'People.png', 'index' => 8]],
  'unsafe path' => [['sheet' => '../bad.png']],
  'reserved layer' => [['sheet' => 'People.png', 'layer' => 1000]],
]);

it('keeps the terminal glyph on a missing or corrupt sheet and recovers after replacement', function (bool $corrupt) {
  $sheet = $this->root . '/' . NPC_TEST_SHEET;
  if ($corrupt) { file_put_contents($sheet, 'not a PNG'); }
  else { unlink($sheet); }
  $this->runtime->start('NPC fallback', 24, 8);
  $this->scene->renderNpcField();
  for ($i = 0; $i < 3; $i++) { $this->runtime->present($this->scene); }
  $frame = RetainedFrameState::replay($this->transport->sent)[0];
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 7, 1))->toBe('G')
    ->and(file($this->root . '/warning.log'))->toHaveCount(1)
    ->and($this->manager->npcAt(7, 4))->toBe($this->npc);
  $id = $this->npc->getGraphicalSpriteId();
  writeCharacterSheetPng($sheet, 11, 17);
  touch($sheet, 1700000002);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $repaired = $frames[array_key_last($frames)];
  expect($repaired['sprites'][0]['id'])->toBe($id)->and(mb_substr(RetainedFrameState::getTextRows($repaired, 24, 8)[4], 7, 1))->toBe('.')
    ->and($this->npc->id)->toBe('guide')->and($this->npc->sprite)->toBe('G');
  // Frame size follows the current image; the field footprint stays one cell.
  expect($this->npc->getGraphicalSpriteDefinition()->sourceRect->toArray())
    ->toBe(['x' => 11, 'y' => 0, 'width' => 11, 'height' => 17]);
  writeCharacterSheetPng($sheet, 15, 9);
  touch($sheet, 1700000004);
  expect($this->npc->getGraphicalSpriteDefinition()->asset)->toBe(NPC_TEST_SHEET)
    ->and($this->npc->getGraphicalSpriteDefinition()->sourceRect->toArray())
    ->toBe(['x' => 15, 'y' => 0, 'width' => 15, 'height' => 9])
    ->and($this->npc->getGraphicalSpriteDefinition()->width)->toBe(48);
})->with([false, true]);

it('guards the sheet layout against replaced dimensions without rejecting larger art', function (string $sheet) {
  $path = $this->root . '/' . $sheet;
  writeCharacterSheetPng($path, 4, 6);
  $this->manager->configure([getNpcTestEntry(['sprites2d' => characterSheetData($sheet)])]);
  $npc = $this->manager->findById('guide');
  expect($npc->getGraphicalSpriteDefinition())->not->toBeNull();
  // A replacement that no longer divides into the sheet's frame layout.
  writeTestPng($path, 50, 50);
  touch($path, 1700000000);
  expect($npc->getGraphicalSpriteDefinition())->toBeNull()
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('divides into');
  $this->runtime->start('NPC invalid crop', 24, 8);
  $this->scene->renderNpcField();
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $frame = $frames[array_key_last($frames)];
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 7, 1))->toBe('G');
  writeCharacterSheetPng($path, 16, 24);
  touch($path, 1700000002);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())
    ->toBe(['x' => 16, 'y' => 0, 'width' => 16, 'height' => 24])
    ->and($npc->getGraphicalSpriteDefinition()->width)->toBe(48);
})->with(['standard sheet' => ['Villagers.png'], 'single-character sheet' => ['$Guide.png']]);

it('runs NPC event routes with independent step timing facing collision and idle restoration', function () {
  $this->manager->configure([getNpcTestEntry(), getNpcTestEntry(['id' => 'other', 'x' => 13])]);
  $npc = $this->manager->findById('guide');
  $route = new MovementRouteRunner($this->scene, ['subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'right', 'count' => 3]], 'secondsPerStep' => 0.08]);
  // On the east row (row 2), each 48-pixel step carries the cycle 1.6 patterns on (one per 30
  // pixels): halfway through each step it shows patterns 2, 0, then 2.
  expect($route->update(0))->toBeFalse()->and($npc->position->x)->toBe(8.0);
  $this->manager->advanceGraphicalAnimation(0.04);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 8, 'y' => 12, 'width' => 4, 'height' => 6])
    ->and($this->manager->findById('other')->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
  expect($route->update(0.08))->toBeFalse()->and($npc->position->x)->toBe(9.0);
  $this->manager->advanceGraphicalAnimation(0.04);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  expect($route->update(0.08))->toBeTrue()->and($npc->position->x)->toBe(10.0);
  $this->manager->advanceGraphicalAnimation(0.04);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  $this->manager->advanceGraphicalAnimation(0.04);
  $this->manager->advanceGraphicalAnimation(CharacterWalkAnimation::STOP_SECONDS);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4)
    ->and($npc->heading)->toBe(MovementHeading::EAST)->and($npc->sprite)->toBe('E');
  $this->manager->moveNpcById('guide', Vector2::right());
  $this->manager->advanceGraphicalAnimation(0.08);
  $this->manager->faceNpc('guide', Vector2::up());
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 4, 'y' => 18, 'width' => 4, 'height' => 6]);
  $this->manager->moveNpcById('guide', Vector2::right());
  $this->manager->advanceGraphicalAnimation(0.08);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
  expect($this->manager->moveNpcById('guide', Vector2::right()))->toBeFalse()
    ->and($npc->position->x)->toBe(12.0)->and($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
});

it('keeps reduced-motion route outcomes and facing while suppressing animated frames', function () {
  putSceneAudioConfig(['accessibility' => ['reducedMotion' => true]]);
  $this->manager->configure([getNpcTestEntry()]);
  $npc = $this->manager->findById('guide');
  $route = new MovementRouteRunner($this->scene, ['subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'right', 'count' => 3]]]);
  expect($route->update(0))->toBeTrue();
  $this->manager->advanceGraphicalAnimation(0.08);
  // Walking changes position and facing; the frame stays on the standing pattern.
  expect($npc->position->x)->toBe(10.0)->and($npc->heading)->toBe(MovementHeading::EAST)
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 4, 'y' => 12, 'width' => 4, 'height' => 6]);
  $route = new MovementRouteRunner($this->scene, ['subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'up', 'faceOnly' => true]]]);
  expect($route->update(0))->toBeTrue();
  expect($npc->heading)->toBe(MovementHeading::NORTH)
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 4, 'y' => 18, 'width' => 4, 'height' => 6]);
});

it('animates successful wander steps without altering their schedule or bounds', function () {
  $this->manager->configure([getNpcTestEntry(['movement' => 'wander'])]);
  $npc = $this->manager->findById('guide');
  $npc->nextWanderTime = 1;
  new ReflectionProperty(Time::class, 'time')->setValue(null, 2.0);
  $this->manager->update();
  $this->manager->advanceGraphicalAnimation(0.08);
  expect(abs($npc->position->x - 7) + abs($npc->position->y - 4))->toBe(1.0)
    ->and($npc->nextWanderTime)->toBeGreaterThan(2.0)
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(8);
});

it('filters hidden and staged-suppressed subjects consistently and restores real NPC art and transform', function () {
  $this->manager->configure([getNpcTestEntry()]);
  $npc = $this->manager->findById('guide');
  $id = $npc->getGraphicalSpriteId();
  $stage = $this->scene->cinematicStage;
  $stage->add(['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]);
  expect($this->manager->getGraphicalSpriteProviders())->toBe([])
    ->and($this->manager->npcAt(7, 4))->toBe($npc);
  $this->scene->renderNpcField();
  expect(Console::charAt(7, 4))->toBe('@');
  $stage->hide('pose');
  $this->manager->moveNpcById('guide', Vector2::right());
  $this->manager->advanceGraphicalAnimation(0.08);
  $this->scene->renderNpcField();
  expect($this->manager->getGraphicalSpriteProviders())->toBe([])->and(Console::charAt(8, 4))->toBe('.');
  $stage->clear();
  expect($this->manager->getGraphicalSpriteProviders())->toBe([$npc])
    ->and($npc->position->x)->toBe(7.0)->and($npc->getGraphicalSpriteId())->toBe($id)
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
  $this->scene->gameState->setSwitch('departed', true);
  $this->manager->advanceGraphicalAnimation(0.08);
  $this->scene->renderNpcField();
  expect($this->manager->getGraphicalSpriteProviders())->toBe([])
    ->and($this->manager->npcAt(7, 4))->toBeNull()->and(Console::charAt(7, 4))->toBe('.');
  $this->scene->gameState->setSwitch('departed', false);
  expect($this->manager->getGraphicalSpriteProviders())->toBe([$npc]);
});

it('prepares destinations transactionally and drops old providers and staging across a map transition', function () {
  $this->manager->configure([getNpcTestEntry()]);
  $old = $this->manager->findById('guide');
  $destination = $this->manager->prepareNpcs([getNpcTestEntry()], 'Village/Inn');
  expect($this->manager->getGraphicalSpriteProviders())->toBe([$old]);
  expect(fn() => $this->manager->prepareNpcs([getNpcTestEntry(), getNpcTestEntry()], 'broken'))
    ->toThrow(RuntimeException::class, 'Duplicate NPC id');
  expect($this->manager->findById('guide'))->toBe($old);
  $this->manager->moveNpcById('guide', Vector2::right());
  $this->manager->advanceGraphicalAnimation(0.08);
  $this->scene->cinematicStage->add(['id' => 'pose', 'sprite' => '@',
    'subject' => ['kind' => 'npc', 'id' => 'guide']]);
  $this->scene->cinematicStage->clear();
  $this->scene->changeMapIdentity('Village/Inn');
  $this->manager->applyPreparedNpcs($destination);
  expect($this->manager->getGraphicalSpriteProviders())->toBe($destination)
    ->and($destination[0]->id)->toBe($old->id)
    ->and($destination[0]->getGraphicalSpriteId())->not->toBe($old->getGraphicalSpriteId())
    ->and($destination[0]->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4)
    ->and($old->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
  $this->manager->configure([]);
  expect(new GraphicalSpriteCollector()->collect($this->scene))->toBe([]);
});

it('slides NPC steps at their route pace or walking time and sends slides only to renderers that negotiated them', function () {
  $npc = $this->npc;
  $route = new MovementRouteRunner($this->scene, ['subject' => 'npc', 'npcId' => 'guide',
    'steps' => [['direction' => 'right', 'count' => 2]], 'secondsPerStep' => 0.3]);
  expect($route->update(0))->toBeFalse()
    ->and($npc->getGraphicalSpriteMotion()?->seconds)->toBe(0.3);
  // Facing is not a step: nothing slides.
  $this->manager->faceNpc('guide', Vector2::up());
  expect($npc->getGraphicalSpriteMotion())->toBeNull();
  // Outside a route an NPC step walks at field speed: 16 frames across or down.
  expect($this->manager->moveNpcById('guide', Vector2::right()))->toBeTrue()
    ->and($npc->getGraphicalSpriteMotion()?->seconds)->toBe(16 / 60)
    ->and($this->scene->getStepSeconds(Vector2::down()))->toBe(16 / 60);

  // This renderer advertised no field_motion, so its sprites are placed by whole cells.
  $this->runtime->start('NPC slides', 24, 8);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect($frames[array_key_last($frames)]['sprites'][0])->not->toHaveKey('motion');

  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["sprite_source_rect","tile_batches","field_motion"]}')];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']),
    $this->root, protocol: RendererProtocolVersion::V2), $transport);
  try {
    $runtime->start('NPC slides', 24, 8);
    $runtime->present($this->scene);
    $sprite = RetainedFrameState::replay($transport->sent)[0]['sprites'][0];
    expect($sprite['motion'])->toBe(['duration' => 16 / 60]);
    putSceneAudioConfig(['accessibility' => ['reducedMotion' => true], 'ui' => ['hud' => ['location' => false]]]);
    expect($npc->getGraphicalSpriteMotion())->toBeNull();
  } finally {
    $runtime->shutdown();
  }
});

it('lifts a character NPC for renderers that negotiated sprite_lift but never an object NPC', function () {
  writeCharacterSheetPng($this->root . '/!Chest.png', 4, 6);
  $this->manager->configure([getNpcTestEntry(), getNpcTestEntry(['id' => 'chest', 'x' => 10,
    'sprites2d' => characterSheetData('!Chest.png')])]);
  $lifts = static fn(array $frame): array => array_column(array_map(static fn(array $sprite): array
    => ['asset' => $sprite['asset'], 'lift' => $sprite['lift'] ?? null], $frame['sprites']), 'lift', 'asset');

  // This renderer advertised no sprite_lift: every sprite is placed exactly as before.
  $this->runtime->start('NPC lift', 24, 8);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  expect($lifts($frames[array_key_last($frames)]))->toBe([NPC_TEST_SHEET => null, '!Chest.png' => null]);

  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson(
    '{"protocol":2,"type":"ready","capabilities":["sprite_source_rect","tile_batches","sprite_lift"]}')];
  $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']),
    $this->root, protocol: RendererProtocolVersion::V2), $transport);
  try {
    $runtime->start('NPC lift', 24, 8);
    $runtime->present($this->scene);
    $frame = RetainedFrameState::replay($transport->sent)[0];
    // The lift draws the character higher; its cell, and so its terminal glyph and draw order, stay put.
    expect($lifts($frame))->toBe([NPC_TEST_SHEET => 6, '!Chest.png' => null])
      ->and(array_column($frame['sprites'], 'y', 'asset'))->toBe(array_column($frames[array_key_last($frames)]['sprites'], 'y', 'asset'));
  } finally {
    $runtime->shutdown();
  }
});

/** Stands the player on a tile and faces it one way, as field movement leaves it. */
function placeNpcTestPlayer(Player $player, int $x, int $y, Vector2 $facing): void
{
  $player->position->x = $x;
  $player->position->y = $y;
  $player->updatePlayerSprite($facing);
}

/**
 * Replaces the blocking text box: each page records what the field shows
 * while it is open, then closes at once.
 */
final class NpcTalkProbeModalManager extends ModalManager
{
  /** @var list<array{heading: MovementHeading, glyph: string, row: int|null}> */
  public array $pages = [];

  public function __construct(private readonly Closure $speaker)
  {
  }

  public function showText(
    string $message,
    string $title = '',
    string $help = '',
    ?WindowPosition $position = null,
    float $charactersPerSecond = 1,
    ?DialoguePlayback $playback = null,
    ?\Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext $presentation = null,
  ): void
  {
    $npc = ($this->speaker)();
    $this->pages[] = ['heading' => $npc->heading, 'glyph' => Console::charAt(7, 4),
      'row' => $npc->getGraphicalSpriteDefinition()?->sourceRect->y];
  }
}

/** Installs the probe for the guide NPC the test configures. */
function installNpcTalkProbe(object $test): NpcTalkProbeModalManager
{
  $probe = new NpcTalkProbeModalManager(fn(): Npc => $test->manager->findById('guide'));
  new ReflectionProperty(ModalManager::class, 'instance')->setValue(null, $probe);
  new ReflectionProperty(Console::class, 'game')->setValue(null, $test->scene->getGame());

  return $probe;
}

/** The sheet row (source y) of a heading in the 4 x 6 fixture: RPG Maker's down, left, right, up. */
function npcTestRow(MovementHeading $heading): int
{
  return 6 * match ($heading) {
    MovementHeading::SOUTH => 0, MovementHeading::WEST => 1,
    MovementHeading::EAST => 2, default => 3,
  };
}

it('turns a talked-to NPC to face the player while it speaks and back when it finishes', function (array $tile, string $facing, MovementHeading $heading) {
  $this->manager->configure([getNpcTestEntry(['dialogue' => [['text' => 'One.'], ['text' => 'Two.']],
    'sets' => [['type' => 'switch', 'name' => 'talked', 'value' => true]]])]);
  $probe = installNpcTalkProbe($this);
  $npc = $this->manager->findById('guide');
  $player = $this->scene->player;
  // The NPC starts looking the way the player looks, so away from the player.
  $this->manager->faceNpc('guide', MovementRouteRunner::directionVector($facing));
  $before = $npc->heading;
  placeNpcTestPlayer($player, $tile[0], $tile[1], MovementRouteRunner::directionVector($facing));
  $player->interact();
  $glyph = $heading->name[0];
  expect($probe->pages)->toBe([
    ['heading' => $heading, 'glyph' => $glyph, 'row' => npcTestRow($heading)],
    ['heading' => $heading, 'glyph' => $glyph, 'row' => npcTestRow($heading)],
  ])
    ->and($npc->heading)->toBe($before)
    ->and($npc->sprite)->toBe($before->name[0])
    ->and(Console::charAt(7, 4))->toBe($before->name[0])
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())
    ->toBe(['x' => 4, 'y' => npcTestRow($before), 'width' => 4, 'height' => 6])
    ->and($npc->getGraphicalSpriteMotion())->toBeNull()
    ->and([$npc->position->x, $npc->position->y])->toBe([7.0, 4.0])
    ->and($this->scene->gameState->getSwitch('talked'))->toBeTrue();
})->with([
  'player faces up' => [[7, 5], 'up', MovementHeading::SOUTH],
  'player faces down' => [[7, 3], 'down', MovementHeading::NORTH],
  'player faces right' => [[6, 4], 'right', MovementHeading::WEST],
  'player faces left' => [[8, 4], 'left', MovementHeading::EAST],
]);

it('keeps a direction-fixed NPC heading while it speaks and after', function () {
  $this->manager->configure([getNpcTestEntry(['directionFix' => true, 'dialogue' => [['text' => 'Next.']],
    'sets' => [['type' => 'switch', 'name' => 'talked', 'value' => true]]])]);
  $probe = installNpcTalkProbe($this);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  expect($npc->directionFix)->toBeTrue()
    ->and($probe->pages)->toBe([['heading' => MovementHeading::WEST, 'glyph' => 'W', 'row' => 6]])
    ->and($npc->heading)->toBe(MovementHeading::WEST)->and($npc->sprite)->toBe('W')
    ->and($this->scene->gameState->getSwitch('talked'))->toBeTrue();
  // Direction fix governs the talk turn only; an authored route still turns the NPC.
  $this->manager->faceNpc('guide', Vector2::up());
  expect($npc->heading)->toBe(MovementHeading::NORTH);
});

it('turns an NPC whose directionFix is not the boolean true', function (mixed $flag) {
  $this->manager->configure([getNpcTestEntry(['directionFix' => $flag, 'dialogue' => [['text' => 'Hi.']]])]);
  $probe = installNpcTalkProbe($this);
  $npc = $this->manager->findById('guide');
  placeNpcTestPlayer($this->scene->player, 6, 4, Vector2::right());
  $this->scene->player->interact();
  expect($npc->directionFix)->toBeFalse()->and($probe->pages[0]['heading'])->toBe(MovementHeading::WEST)
    ->and($npc->heading)->toBe(MovementHeading::SOUTH);
})->with(['false' => [false], 'string' => ['true'], 'integer' => [1], 'null' => [null]]);

it('keeps the terminal glyph of an NPC without directional sprites while its heading turns and returns', function () {
  $entry = getNpcTestEntry(['dialogue' => [['text' => 'Hi.']]]);
  unset($entry['sprites'], $entry['sprites2d']);
  $this->manager->configure([$entry]);
  $probe = installNpcTalkProbe($this);
  $npc = $this->manager->findById('guide');
  placeNpcTestPlayer($this->scene->player, 8, 4, Vector2::left());
  $this->scene->player->interact();
  expect($probe->pages)->toBe([['heading' => MovementHeading::EAST, 'glyph' => 'G', 'row' => null]])
    ->and($npc->heading)->toBe(MovementHeading::SOUTH)->and($npc->sprite)->toBe('G')
    ->and(Console::charAt(7, 4))->toBe('G');
});

it('turns back after its script finishes, not while the script runs', function (array $talk) {
  $this->manager->configure([getNpcTestEntry($talk + [
    'sets' => [['type' => 'switch', 'name' => 'talked', 'value' => true]]])]);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  expect($npc->conversationIsActive)->toBeTrue()
    ->and($npc->heading)->toBe(MovementHeading::SOUTH)->and(Console::charAt(7, 4))->toBe('S')
    ->and($this->scene->gameState->getSwitch('talked'))->toBeFalse();
  // A second talk while the script runs neither turns it again nor loses the heading to restore.
  $this->scene->player->interact();
  $this->scene->eventInterpreter->update(0.1);
  expect($npc->conversationIsActive)->toBeFalse()
    ->and($npc->heading)->toBe(MovementHeading::WEST)->and($npc->sprite)->toBe('W')
    ->and(Console::charAt(7, 4))->toBe('W')
    ->and($npc->getGraphicalSpriteDefinition()->sourceRect->y)->toBe(6)
    ->and($this->scene->gameState->getSwitch('talked'))->toBeTrue();
})->with([
  'npc script' => [['script' => [['type' => 'wait', 'seconds' => 0.1]]]],
  'variant script' => [['dialogue' => [['lines' => [], 'script' => [['type' => 'wait', 'seconds' => 0.1]]]]]],
]);

it('turns back when its conversation script fails', function () {
  $this->manager->configure([getNpcTestEntry(['script' => [['type' => 'wait', 'seconds' => 0.1]],
    'sets' => [['type' => 'switch', 'name' => 'talked', 'value' => true]]])]);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  expect($npc->heading)->toBe(MovementHeading::SOUTH);
  $this->scene->eventInterpreter->failActiveSession('Interrupted for the test.');
  expect($npc->conversationIsActive)->toBeFalse()
    ->and($npc->heading)->toBe(MovementHeading::WEST)->and(Console::charAt(7, 4))->toBe('W')
    ->and($this->scene->gameState->getSwitch('talked'))->toBeFalse();
});

it('leaves the result of a script that turns or moves the NPC during the talk', function (array $step, array $position, MovementHeading $heading) {
  $this->manager->configure([getNpcTestEntry(['script' => [
    ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'guide', 'secondsPerStep' => 0, 'steps' => [$step]],
    ['type' => 'wait', 'seconds' => 0.1],
  ]])]);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  for ($tick = 0; $tick < 4 && $npc->conversationIsActive; $tick++) {
    $this->scene->eventInterpreter->update(0.1);
  }
  expect($npc->conversationIsActive)->toBeFalse()
    ->and($npc->heading)->toBe($heading)->and($npc->sprite)->toBe($heading->name[0])
    ->and([$npc->position->x, $npc->position->y])->toBe($position);
})->with([
  // Even a turn to the talk heading itself is the script's result to keep.
  'turn toward the player' => [['direction' => 'down', 'faceOnly' => true], [7.0, 4.0], MovementHeading::SOUTH],
  'turn away' => [['direction' => 'up', 'faceOnly' => true], [7.0, 4.0], MovementHeading::NORTH],
  'step' => [['direction' => 'right'], [8.0, 4.0], MovementHeading::EAST],
]);

it('does not snap back an NPC a cinematic staged during the talk, or one that left the map', function () {
  $this->manager->configure([getNpcTestEntry(['script' => [['type' => 'wait', 'seconds' => 0.1]]])]);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  $this->scene->cinematicStage->add(['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]);
  $this->scene->eventInterpreter->update(0.1);
  expect($npc->heading)->toBe(MovementHeading::SOUTH);
  // Releasing the staging restores the transform the cinematic took over, not the pre-talk heading.
  $this->scene->cinematicStage->clear();
  expect($npc->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->manager->restoreNpcHeadingAfterTalk($npc))->toBeFalse();

  $this->manager->faceNpc('guide', Vector2::left());
  $this->scene->player->interact();
  $this->manager->applyPreparedNpcs($this->manager->prepareNpcs([getNpcTestEntry()], 'Village/Plaza'));
  $this->scene->eventInterpreter->update(0.1);
  expect($npc->heading)->toBe(MovementHeading::SOUTH)
    ->and($this->manager->findById('guide'))->not->toBe($npc);
});

it('restores a talked-to wanderer and lets it keep its wander schedule', function () {
  $this->manager->configure([getNpcTestEntry(['movement' => 'wander', 'dialogue' => [['text' => 'Meow.']]])]);
  $probe = installNpcTalkProbe($this);
  $npc = $this->manager->findById('guide');
  $this->manager->faceNpc('guide', Vector2::left());
  $npc->nextWanderTime = 5.0;
  new ReflectionProperty(Time::class, 'time')->setValue(null, 1.0);
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  $this->scene->player->interact();
  $this->manager->update();
  expect($probe->pages[0]['heading'])->toBe(MovementHeading::SOUTH)
    ->and($npc->heading)->toBe(MovementHeading::WEST)
    ->and($npc->nextWanderTime)->toBe(5.0)
    ->and([$npc->position->x, $npc->position->y])->toBe([7.0, 4.0]);
  // Steps are random and the player blocks the south tile, so tick until one lands.
  $time = 5.0;
  for ($attempt = 0; $attempt < 200 && $npc->position->x === 7.0 && $npc->position->y === 4.0; $attempt++) {
    $time += 10.0;
    $npc->nextWanderTime = $time - 1.0;
    new ReflectionProperty(Time::class, 'time')->setValue(null, $time);
    $this->manager->update();
  }
  $step = new Vector2($npc->position->x - 7.0, $npc->position->y - 4.0);
  $expected = match (true) {
    $step->y < 0 => MovementHeading::NORTH,
    $step->x < 0 => MovementHeading::WEST,
    default => MovementHeading::EAST,
  };
  expect(abs($step->x) + abs($step->y))->toBe(1.0)->and($npc->heading)->toBe($expected);
});

it('does not turn an NPC a cinematic has staged', function () {
  $this->manager->configure([getNpcTestEntry()]);
  $npc = $this->manager->findById('guide');
  $this->scene->cinematicStage->add(['id' => 'pose', 'sprite' => '@', 'subject' => ['kind' => 'npc', 'id' => 'guide']]);
  placeNpcTestPlayer($this->scene->player, 7, 5, Vector2::up());
  expect($this->manager->turnNpcToward($npc, $this->scene->player->position))->toBeFalse()
    ->and($npc->heading)->toBe(MovementHeading::SOUTH);
  $this->manager->faceNpc('guide', Vector2::left());
  $this->scene->player->interact();
  expect($npc->heading)->toBe(MovementHeading::WEST);
});

/** Sets the collision of field cells in the test scene. */
function setNpcTestCollision(GameScene $scene, array $cells, CollisionType $type): void
{
  $property = new ReflectionProperty(MapManager::class, 'collisionMap');
  $map = $property->getValue($scene->mapManager);
  foreach ($cells as [$x, $y]) { $map[$y][$x] = $type->value; }
  $property->setValue($scene->mapManager, $map);
}

it('talks across any depth of counter to the NPC behind it, and never through a wall', function () {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 24, 'height' => 8]));
  $this->manager->configure([getNpcTestEntry(['dialogue' => [['text' => 'Welcome.']]])]);
  $probe = installNpcTalkProbe($this);
  $player = $this->scene->player;
  // The guide stands at (7, 4) behind a counter two cells deep.
  setNpcTestCollision($this->scene, [[7, 5], [7, 6]], CollisionType::COUNTER);
  placeNpcTestPlayer($player, 7, 7, MovementRouteRunner::directionVector('up'));

  $player->refreshTalkTarget();
  expect($player->talkTarget)->toBe($this->manager->findById('guide'))->and($player->canAct)->toBeTrue();
  $player->interact();
  expect($probe->pages)->toHaveCount(1);

  // A counter cannot be walked onto.
  expect($this->scene->mapManager->canMoveTo(7, 6))->toBeFalse();

  // A wall in the line ends the reach.
  setNpcTestCollision($this->scene, [[7, 6]], CollisionType::SOLID);
  $player->refreshTalkTarget();
  expect($player->talkTarget)->toBeNull()->and($player->canAct)->toBeFalse();
  $player->interact();
  expect($probe->pages)->toHaveCount(1);
});

it('shows the action prompt only for an NPC that has something to say', function () {
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 24, 'height' => 8]));
  $this->manager->configure([getNpcTestEntry(['dialogue' => []])]);
  $player = $this->scene->player;
  placeNpcTestPlayer($player, 7, 5, MovementRouteRunner::directionVector('up'));

  $player->refreshTalkTarget();
  expect($player->findFacingNpc())->toBe($this->manager->findById('guide'))
    ->and($player->talkTarget)->toBeNull()
    ->and($player->canAct)->toBeFalse();

  $this->manager->configure([getNpcTestEntry(['dialogue' => [['text' => 'Hello.']]])]);
  $player->refreshTalkTarget();
  expect($player->canAct)->toBeTrue();

  // Turning away clears it.
  $player->updatePlayerSprite(MovementRouteRunner::directionVector('down'));
  $player->refreshTalkTarget();
  expect($player->canAct)->toBeFalse();
});
