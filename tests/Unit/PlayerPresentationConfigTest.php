<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

final class PlayerPresentationTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

/**
 * A renderer transport that advertises sprite_source_rect, as the native
 * renderer does; character frames are always source rects.
 */
function getPlayerPresentationTransport(): FakeRendererTransport
{
  $transport = new FakeRendererTransport();
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect","tile_batches"]}')];
  return $transport;
}

/**
 * A 48 x 48 frame of the fixture sheet's first character, by RPG Maker
 * direction row (down, left, right, up) and walking pattern (1 stands).
 *
 * @return array{x: int, y: int, width: int, height: int}
 */
function getPlayerSheetFrame(string $direction, int $pattern = 1): array
{
  $row = ['south' => 0, 'west' => 1, 'east' => 2, 'north' => 3][$direction];
  return ['x' => $pattern * 48, 'y' => $row * 48, 'width' => 48, 'height' => 48];
}

beforeEach(function () {
  $this->runtime = null;
  $this->oldDirectory = getcwd();
  $this->root = sys_get_temp_dir() . '/ichiloto-s6-' . uniqid();
  mkdir($this->root . '/assets/Data/Entities', 0777, true);
  chdir($this->root);
  $this->states = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class, Debug::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 20, 'height' => 10]));
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::setTerminalOutputEnabled(false);
  Console::syncDimensions(20, 10);
  Debug::configure(['log_directory' => $this->root]);
  // Player art resolves against the project asset root (here the working directory's assets).
  $this->sheet = characterSheetData()['sheet'];
  writeCharacterSheetPng($this->root . '/assets/' . $this->sheet, 48, 48);
  $this->data = ['sprites' => ['north' => '^', 'east' => '>', 'south' => 'v', 'west' => '<'], 'sprites2d' => characterSheetData()];
  $this->writePlayerData = function (array $data): void {
    file_put_contents($this->root . '/assets/Data/Entities/player.php', '<?php return ' . var_export($data, true) . ';');
  };
  ($this->writePlayerData)($this->data);
  $this->scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
  $this->scene->method('getGame')->willReturn(new PlayerPresentationTestGame());
  // Ten cells across: the player's cell (7, 4) begins at console column 14.
  $this->camera = new Camera($this->scene, 20, 10, worldSpace: array_fill(0, 30, str_repeat('..', 20)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->config = new GameConfig('fixture', new Party(), new Vector2(7, 4), new Rect(0, 0, 1, 1), MovementHeading::SOUTH);
  $this->createPlayer = fn(GameConfig $config) => new ReflectionMethod(GameScene::class, 'createPlayer')->invoke($this->scene, $config);
  ob_start();
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  chdir($this->oldDirectory);
  $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($paths as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($this->root);
  foreach ($this->states as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('shares project loading with new-game terminal art and treats absent graphical art as optional', function () {
  unset($this->data['sprites2d']);
  ($this->writePlayerData)($this->data);
  $config = PlayerPresentationConfig::load();
  $loader = (new ReflectionClass(GameLoader::class))->newInstanceWithoutConstructor();
  $terminal = new ReflectionMethod(GameLoader::class, 'loadPlayerSprites')->invoke($loader);
  expect($config->graphical)->toBeNull()->and($config->terminal->toArray())->toBe($terminal->toArray())
    ->and(($this->createPlayer)($this->config)->getGraphicalSpriteDefinition())->toBeNull();
});

it('loads current project art for new and restored Players without serializing graphical data', function () {
  $new = ($this->createPlayer)($this->config);
  $save = serialize($this->config);
  expect($new->getGraphicalSpriteDefinition()->asset)->toBe($this->sheet)
    ->and($save)->not->toContain('sprites2d', 'GraphicalSprite', 'CharacterSheet', $this->sheet);
  writeCharacterSheetPng($this->root . '/assets/Graphics/Characters/Current.png', 48, 48);
  $this->data['sprites2d'] = characterSheetData('Graphics/Characters/Current.png', 1);
  ($this->writePlayerData)($this->data);
  $restored = ($this->createPlayer)(unserialize($save));
  // The second character's standing frame starts one three-frame block to the right.
  expect($restored->getGraphicalSpriteDefinition()->asset)->toBe('Graphics/Characters/Current.png')
    ->and($restored->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 192, 'y' => 0, 'width' => 48, 'height' => 48])
    ->and($restored->heading)->toBe($new->heading)->and($restored->sprite)->toBe($new->sprite)
    ->and([$restored->position->x, $restored->position->y])->toBe([7.0, 4.0])
    ->and(serialize($this->config))->toBe($save);
});

it('loads sheet presentation for new and restored players without modifying terminal art or saves', function () {
  $save = serialize($this->config);
  $player = ($this->createPlayer)($this->config);
  expect($player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerSheetFrame('south'))
    ->and($player->getDirectionalSprites())->toBe(['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => ['<']])
    ->and($save)->not->toContain('CharacterSheet', 'sourceRect', 'Heroes');
  // Replacement art with smaller frames and a different character on the sheet.
  writeCharacterSheetPng($this->root . '/assets/' . $this->sheet, 32, 32);
  touch($this->root . '/assets/' . $this->sheet, 1700000002);
  $this->data['sprites2d']['index'] = 5;
  ($this->writePlayerData)($this->data);
  $restored = ($this->createPlayer)(unserialize($save));
  expect($restored->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(['x' => 128, 'y' => 128, 'width' => 32, 'height' => 32])
    ->and([$restored->getGraphicalSpriteDefinition()->width, $restored->getGraphicalSpriteDefinition()->height])->toBe([48, 48])
    ->and(serialize($this->config))->toBe($save);
});

it('animates only successful player steps and rests on blocked movement or facing changes', function () {
  $set = PlayerPresentationConfig::load();
  $player = $this->getMockBuilder(Player::class)->setConstructorArgs([
    $this->scene, 'Sheet hero', new Vector2(7, 4), new Rect(0, 0, 1, 1), ['v'],
    MovementHeading::SOUTH, $set->terminal->toArray(), $set->graphical, $this->root . '/assets',
  ])->onlyMethods(['erasePlayer', 'render', 'renderEventCues', 'renderLocationHUDWindow', 'handleTriggers', 'notify'])->getMock();
  $map = $this->getMockBuilder(MapManager::class)->disableOriginalConstructor()
    ->onlyMethods(['canMoveTo', 'scrollMap'])->getMock();
  $map->method('canMoveTo')->willReturnOnConsecutiveCalls(true, false);
  $map->method('scrollMap')->willReturn(false);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  expect($player->tryMove(Vector2::right(), $this->camera))->toBeTrue();
  $player->advanceGraphicalAnimation(0.08);
  $walking = $player->getGraphicalSpriteDefinition();
  expect($walking->asset)->toBe($this->sheet)->and($walking->sourceRect->toArray())->toBe(getPlayerSheetFrame('east', 2))
    ->and($player->position->x)->toBe(8.0)->and($player->sprite)->toBe(['>']);
  expect($player->tryMove(Vector2::up(), $this->camera))->toBeFalse();
  $player->advanceGraphicalAnimation(0.08);
  $idle = $player->getGraphicalSpriteDefinition();
  expect($idle->sourceRect->toArray())->toBe(getPlayerSheetFrame('north'))
    ->and($player->position->x)->toBe(8.0)->and($player->position->y)->toBe(4.0);
  $player->face(Vector2::left(), $this->camera);
  expect($player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerSheetFrame('west'));
});

it('keeps the terminal sprite and reports malformed optional project graphical configuration', function ($value) {
  $this->data['sprites2d'] = $value;
  ($this->writePlayerData)($this->data);
  $config = PlayerPresentationConfig::load();
  expect($config->graphical)->toBeNull()
    ->and($config->terminal->toArray())->toBe(['north' => ['^'], 'east' => ['>'], 'south' => ['v'], 'west' => ['<']])
    ->and(($this->createPlayer)($this->config)->getGraphicalSpriteDefinition())->toBeNull()
    ->and(file_get_contents($this->root . '/warning.log'))->toContain('Player sprites2d is invalid');
})->with([
  'null' => [null], 'false' => [false], 'wrong type' => ['sprite.png'], 'empty' => [[]],
  'per-direction images' => [['north' => ['asset' => 'north.png']]],
  'authored size' => [['sheet' => 'Graphics/Characters/Heroes.png', 'width' => 48, 'height' => 48]],
  'authored frames' => [['sheet' => 'Graphics/Characters/Heroes.png', 'frameWidth' => 48]],
  'index out of range' => [['sheet' => 'Graphics/Characters/Heroes.png', 'index' => 8]],
  'unsafe path' => [['sheet' => '../Heroes.png']],
]);

it('collects only the active field Player and never overlays unrelated scenes or menus', function () {
  $player = ($this->createPlayer)($this->config);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
  $collector = new GraphicalSpriteCollector();
  expect($collector->collect(makeCameraTestScene()))->toBe([])->and($collector->collect(null))->toBe([])
    ->and($collector->collect($this->scene))->toHaveCount(1)
    ->and($collector->collect($this->scene)[0]->id)->toBe('player');
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, makeBareScene(MainMenuState::class));
  expect($collector->collect($this->scene))->toBe([]);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, false);
  expect($collector->collect($this->scene))->toBe([]);
});

it('defaults to v2 world sprite and opaque above-sprite prompt composition', function () {
  $player = ($this->createPlayer)($this->config);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  $player->availableAction = $this->createStub(Ichiloto\Engine\Entities\Interfaces\ActionInterface::class);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
  $transport = getPlayerPresentationTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root), $transport);
  $this->runtime->start('Field v2', 20, 10);
  Console::write('..', 14, 4);
  $player->render();
  Console::withLayer('modal', fn() => Console::write('   ', 14, 4), 1020);
  $this->runtime->present($this->scene);
  expect($transport->session->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->payload)->not->toHaveKey('text');
  $frame = RetainedFrameState::replay($transport->sent)[0];
  expect(array_column($frame['textLayers'], 'id'))->toBe(['world', 'field-prompt', 'modal'])
    ->and(array_column($frame['textLayers'], 'layer'))->toBe([0, 1010, 1020])
    ->and($frame['sprites'])->toHaveCount(1)
    ->and(mb_substr(RetainedFrameState::getTextRows(['textLayers' => [$frame['textLayers'][0]]], 20, 10)[4], 14, 1))->toBe('.')
    ->and($frame['textLayers'][2]['runs'][0]['text'])->toBe('   ')
    ->and(mb_substr(RetainedFrameState::getTextRows($frame, 20, 10)[4], 14, 3))->toBe('   ');
});

it('composes real field Player movement facing masking and duplicate detection in the runtime', function () {
  $player = ($this->createPlayer)($this->config);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
  $map = makeBareScene(MapManager::class);
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($map, $this->scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($map, [3 => [7 => CollisionType::SOLID->value]]);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  $transport = getPlayerPresentationTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root, protocol: RendererProtocolVersion::V2), $transport);
  $this->runtime->start('Field', 20, 10);
  Console::write('..', 14, 4);
  $player->render();
  expect($this->runtime->present($this->scene))->toBeTrue()->and($this->runtime->present($this->scene))->toBeFalse()
    ->and(Console::charAt(14, 4))->toBe('v');
  $frames = RetainedFrameState::replay($transport->sent);
  expect(mb_substr(RetainedFrameState::getTextRows($frames[0], 20, 10)[4], 14, 1))->toBe('.');
  expect($player->tryMove(Vector2::up(), $this->camera))->toBeFalse();
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($transport->sent);
  $south = $frames[0]['sprites'][0];
  $north = $frames[1]['sprites'][0];
  expect($north)->toBe([...$south, 'sourceRect' => getPlayerSheetFrame('north')]);
  $player->position->x = 11;
  $this->camera->moveTo(3, 2);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($transport->sent);
  expect([$frames[2]['sprites'][0]['x'], $frames[2]['sprites'][0]['y']])->toBe([8, 2]);
  $player->position->x = -100;
  $player->render();
  $before = Console::snapshot();
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($transport->sent);
  expect(Console::snapshot())->toEqual($before)
    ->and(RetainedFrameState::getTextRows($frames[3], 20, 10))->toBe($before->rows)
    ->and($frames[3]['sprites'][0]['x'])->toBe(-103);
});

it('keeps the graphical Player during an ordinary dialogue event without admitting menu overlays', function () {
  $player = ($this->createPlayer)($this->config);
  new ReflectionProperty(Player::class, 'isActive')->setValue($player, true);
  new ReflectionProperty(GameScene::class, 'player')->setValue($this->scene, $player);
  $field = makeBareScene(FieldState::class);
  new ReflectionProperty(GameScene::class, 'fieldState')->setValue($this->scene, $field);
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, $field);
  $presentation = $this->createStub(EventPresentationInterface::class);
  $interpreter = new EventInterpreter($this->scene, $presentation);
  new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($this->scene, $interpreter);
  $interpreter->start([['type' => 'text', 'name' => 'Mother', 'text' => 'Welcome home.']]);
  expect($this->scene->hasUnstableEventSession())->toBeTrue();
  $transport = getPlayerPresentationTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root, protocol: RendererProtocolVersion::V2), $transport);
  $this->runtime->start('Dialogue', 20, 10);
  Console::write('..', 14, 4);
  $player->render();
  Console::write('Mother: Welcome home', 0, 8);
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($transport->sent);
  $text = RetainedFrameState::getTextRows($frames[0], 20, 10);
  expect($frames[0]['sprites'])->toHaveCount(1)
    ->and($frames[0]['sprites'][0]['asset'])->toBe($this->sheet)
    ->and($frames[0]['sprites'][0]['sourceRect'])->toBe(getPlayerSheetFrame('south'))
    ->and(mb_substr($text[4], 14, 1))->toBe('.')
    ->and($text[8])->toStartWith('Mother:')
    ->and(Console::charAt(14, 4))->toBe('v');
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, makeBareScene(MainMenuState::class));
  $this->runtime->present($this->scene);
  $frames = RetainedFrameState::replay($transport->sent);
  expect($frames[1]['sprites'])->toBe([]);
});

it('flattens retained scalar cells by priority without treating opaque UI spaces as transparent', function () {
  $frame = ['textLayers' => [
    ['id' => 'modal', 'layer' => 1000, 'runs' => [['row' => 0, 'column' => 2, 'text' => ' ']]],
    ['id' => 'world', 'layer' => 0, 'runs' => [['row' => 0, 'column' => 0, 'text' => "\u{00e9}\u{00a3}C"]]],
    ['id' => 'actor', 'layer' => 10, 'runs' => [['row' => 0, 'column' => 3, 'text' => "\u{03a9}"]]],
  ]];
  $rows = RetainedFrameState::getTextRows($frame, 5, 2);
  expect($rows)->toBe(["\u{00e9}\u{00a3} \u{03a9} ", '     '])
    ->and(mb_substr($rows[0], 2, 1))->toBe(' ')
    ->and(array_column($frame['textLayers'], 'id'))->toBe(['modal', 'world', 'actor']);
});
