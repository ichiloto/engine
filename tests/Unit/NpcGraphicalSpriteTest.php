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
use Ichiloto\Engine\Scenes\SceneStateContext;
use Ichiloto\Engine\Util\Config\ConfigStore;
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

/** Real NPC, map collision, camera, scene provider, and cinematic ownership paths. World cell (7, 4) is console column 14. */
final class NpcGraphicalTestScene extends GameScene
{
  public function __construct(private readonly Game $testGame)
  {
    $this->gameState = new GameState();
    $this->party = new Party();
    $this->currentMapId = 'Village/Plaza';
    $this->camera = new Camera($this, 24, 8, worldSpace: array_fill(0, 12, str_repeat('..', 16)));
    $this->mapManager = makeBareScene(MapManager::class);
    new ReflectionProperty(MapManager::class, 'gameScene')->setValue($this->mapManager, $this);
    new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($this->mapManager,
      array_fill(0, 12, array_fill(0, 32, CollisionType::NONE->value)));
    $this->npcManager = new NpcManager($this);
    $this->cinematicStage = new CinematicStageManager($this);
    $this->player = new Player($this, 'hero', new Vector2(2, 2), new Rect(0, 0, 1, 1), ['P']);
    new ReflectionProperty(Player::class, 'isActive')->setValue($this->player, true);
    $this->fieldState = new FieldState(new SceneStateContext($this));
    $this->state = $this->fieldState;
  }

  public function getGame(): Game { return $this->testGame; }

  public function renderBackgroundTile(int $x, int $y): void
  {
    $this->camera->renderOnScreen(['..'], new Vector2($x, $y));
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
    EventManager::class, Time::class, Debug::class, PngAssetPreflight::class] as $class) {
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
    ->and(mb_substr($text[4], 14, 1))->toBe('.')->and(mb_substr($text[4], 16, 1))->toBe('L')
    ->and(Console::charAt(14, 4))->toBe('G')->and(Console::snapshot())->toEqual($terminal);
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
  expect(Console::charAt(14, 4))->toBe('G');
  $this->runtime->start('NPC invalid definition', 24, 8);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $frame = $frames[array_key_last($frames)];
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 14, 1))->toBe('G');
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
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 14, 1))->toBe('G')
    ->and(file($this->root . '/warning.log'))->toHaveCount(1)
    ->and($this->manager->npcAt(7, 4))->toBe($this->npc);
  $id = $this->npc->getGraphicalSpriteId();
  writeCharacterSheetPng($sheet, 11, 17);
  touch($sheet, 1700000002);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($this->transport->sent);
  $repaired = $frames[array_key_last($frames)];
  expect($repaired['sprites'][0]['id'])->toBe($id)->and(mb_substr(RetainedFrameState::getTextRows($repaired, 24, 8)[4], 14, 1))->toBe('.')
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
  expect($frame['sprites'])->toBe([])->and(mb_substr(RetainedFrameState::getTextRows($frame, 24, 8)[4], 14, 1))->toBe('G');
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
  // Each step advances one stride through patterns 2, 1, 0 on the east row (row 2).
  expect($route->update(0))->toBeFalse()->and($npc->position->x)->toBe(8.0);
  $this->manager->advanceGraphicalAnimation(0.08);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 8, 'y' => 12, 'width' => 4, 'height' => 6])
    ->and($this->manager->findById('other')->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
  expect($route->update(0.08))->toBeFalse()->and($npc->position->x)->toBe(9.0);
  $this->manager->advanceGraphicalAnimation(0.08);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(4);
  expect($route->update(0.08))->toBeTrue()->and($npc->position->x)->toBe(10.0);
  $this->manager->advanceGraphicalAnimation(0.08);
  expect($npc->getGraphicalSpriteDefinition()->sourceRect->x)->toBe(0);
  $this->manager->advanceGraphicalAnimation(CharacterWalkAnimation::STRIDE_SECONDS);
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
  expect(Console::charAt(14, 4))->toBe('@');
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
    ->and($this->manager->npcAt(7, 4))->toBeNull()->and(Console::charAt(14, 4))->toBe('.');
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
