<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Field\PlayerPresentationConfig;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\InputManager;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteCollector;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameLoader;
use Ichiloto\Engine\Scenes\Game\States\FieldState;
use Ichiloto\Engine\Scenes\Game\States\MainMenuState;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\graphicalSpriteData;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';
require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';

final class PlayerPresentationTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

beforeEach(function () {
  $this->runtime = null;
  $this->oldDirectory = getcwd();
  $this->root = sys_get_temp_dir() . '/ichiloto-s6-' . uniqid();
  mkdir($this->root . '/assets/Data/Entities', 0777, true);
  chdir($this->root);
  $this->states = [];
  foreach ([Console::class, InputManager::class, ConfigStore::class, EventManager::class] as $class) {
    $this->states[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 20, 'height' => 10]));
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(20, 10);
  $this->data = ['sprites' => ['north' => '^', 'east' => '>', 'south' => 'v', 'west' => '<'], 'sprites2d' => graphicalSpriteData()];
  $this->writePlayerData = function (array $data): void {
    file_put_contents($this->root . '/assets/Data/Entities/player.php', '<?php return ' . var_export($data, true) . ';');
  };
  ($this->writePlayerData)($this->data);
  $this->scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
  $this->scene->method('getGame')->willReturn(new PlayerPresentationTestGame());
  $this->camera = new Camera($this->scene, 20, 10, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->config = new GameConfig('fixture', new Party(), new Vector2(7, 4), new Rect(0, 0, 1, 1), MovementHeading::SOUTH);
  $this->createPlayer = fn(GameConfig $config) => new ReflectionMethod(GameScene::class, 'createPlayer')->invoke($this->scene, $config);
  ob_start();
});

afterEach(function () {
  $this->runtime?->shutdown();
  ob_end_clean();
  chdir($this->oldDirectory);
  unlink($this->root . '/assets/Data/Entities/player.php');
  foreach (['/assets/Data/Entities', '/assets/Data', '/assets', ''] as $directory) {
    rmdir($this->root . $directory);
  }
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
  expect($new->getGraphicalSpriteDefinition()->asset)->toEndWith('South.png')
    ->and($save)->not->toContain('sprites2d', 'GraphicalSprite', 'South.png');
  $this->data['sprites2d']['south']['asset'] = 'Current-project-art.png';
  ($this->writePlayerData)($this->data);
  $restored = ($this->createPlayer)(unserialize($save));
  expect($restored->getGraphicalSpriteDefinition()->asset)->toBe('Current-project-art.png')
    ->and($restored->heading)->toBe($new->heading)->and($restored->sprite)->toBe($new->sprite)
    ->and([$restored->position->x, $restored->position->y])->toBe([7.0, 4.0])
    ->and(serialize($this->config))->toBe($save);
});

it('fails clearly on malformed optional project graphical configuration', function ($value) {
  $this->data['sprites2d'] = $value;
  ($this->writePlayerData)($this->data);
  expect(fn() => PlayerPresentationConfig::load())->toThrow(InvalidArgumentException::class);
})->with([null, false, 'sprite.png', [[]], [['north' => []]]]);

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
  $transport = new FakeRendererTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root), $transport);
  $this->runtime->start('Field v2', 20, 10);
  Console::write('.', 7, 4);
  $player->render();
  Console::withLayer('modal', fn() => Console::write('   ', 7, 4), 1020);
  $this->runtime->present($this->scene);
  expect($transport->session->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->protocol)->toBe(RendererProtocolVersion::V2)
    ->and($transport->sent[0]->payload)->not->toHaveKey('text');
  $frame = $transport->sent[0]->payload;
  expect(array_column($frame['textLayers'], 'id'))->toBe(['world', 'field-prompt', 'modal'])
    ->and(array_column($frame['textLayers'], 'layer'))->toBe([0, 1010, 1020])
    ->and($frame['sprites'])->toHaveCount(1)
    ->and($frame['textLayers'][0]['runs'][4]['text'][7])->toBe('.')
    ->and($frame['textLayers'][2]['runs'][0]['text'])->toBe('   ');
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
  $transport = new FakeRendererTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root, protocol: RendererProtocolVersion::V1), $transport);
  $this->runtime->start('Field', 20, 10);
  Console::write('.', 7, 4);
  $player->render();
  expect($this->runtime->present($this->scene))->toBeTrue()->and($this->runtime->present($this->scene))->toBeFalse()
    ->and(Console::charAt(7, 4))->toBe('v')->and($transport->sent[0]->payload['text'][4][7])->toBe('.');
  expect($player->tryMove(Vector2::up(), $this->camera))->toBeFalse();
  $this->runtime->present($this->scene);
  $south = $transport->sent[0]->payload['sprites'][0];
  $north = $transport->sent[1]->payload['sprites'][0];
  expect(array_diff_assoc($north, $south))->toBe(['asset' => $this->data['sprites2d']['north']['asset']]);
  $player->position->x = 11;
  $this->camera->moveTo(3, 2);
  $this->runtime->present($this->scene);
  expect([$transport->sent[2]->payload['sprites'][0]['x'], $transport->sent[2]->payload['sprites'][0]['y']])->toBe([8, 2]);
  $player->position->x = -100;
  $player->render();
  $before = Console::snapshot();
  $this->runtime->present($this->scene);
  expect(Console::snapshot())->toEqual($before)
    ->and($transport->sent[3]->payload['text'])->toBe($before->rows)
    ->and($transport->sent[3]->payload['sprites'][0]['x'])->toBe(-103);
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
  $transport = new FakeRendererTransport();
  $this->runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['fixture']), $this->root, protocol: RendererProtocolVersion::V1), $transport);
  $this->runtime->start('Dialogue', 20, 10);
  Console::write('.', 7, 4);
  $player->render();
  Console::write('Mother: Welcome home', 0, 8);
  $this->runtime->present($this->scene);
  expect($transport->sent[0]->payload['sprites'])->toHaveCount(1)
    ->and($transport->sent[0]->payload['sprites'][0]['asset'])->toEndWith('South.png')
    ->and($transport->sent[0]->payload['text'][4][7])->toBe('.')
    ->and($transport->sent[0]->payload['text'][8])->toStartWith('Mother:')
    ->and(Console::charAt(7, 4))->toBe('v');
  new ReflectionProperty(GameScene::class, 'state')->setValue($this->scene, makeBareScene(MainMenuState::class));
  $this->runtime->present($this->scene);
  expect($transport->sent[1]->payload['sprites'])->toBe([]);
});
