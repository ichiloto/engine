<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameObject;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\Cursor;
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\RendererInputSource;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Sprites\DirectionalGraphicalSpriteSet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\graphicalSpriteData;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Supplies scene identity without starting a Game or claiming terminal lifecycle ownership. */
final class SpritePresentationTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

beforeEach(function () {
  $this->consoleState = new ReflectionClass(Console::class)->getStaticProperties();
  $this->cursorState = new ReflectionClass(Cursor::class)->getStaticProperties();
  $this->configState = new ReflectionProperty(ConfigStore::class, 'store')->getValue();
  $this->eventState = new ReflectionProperty(EventManager::class, 'instance')->getValue();
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  ConfigStore::put(ProjectConfig::class, new SceneAudioConfigStub());
  foreach (['frameDepth' => 0, 'isRecomposing' => false, 'terminalHandedBack' => false,
    'output' => null, 'terminalOutputStream' => null] as $name => $value) {
    new ReflectionProperty(Console::class, $name)->setValue(null, $value);
  }
  Console::syncDimensions(20, 10);
  ob_start();

  // Reuse the lightweight scene/map fixture approach; Player and Camera run their real constructors.
  $this->scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
  $this->scene->method('getGame')->willReturn(new SpritePresentationTestGame());
  $this->camera = new Camera($this->scene, 20, 10, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->terminalSprites = ['north' => ['^^'], 'east' => ['>>'], 'south' => ['vv'], 'west' => ['<<']];
  $this->graphicalSprites = DirectionalGraphicalSpriteSet::fromArray(graphicalSpriteData());
  $this->player = new Player($this->scene, 'Test hero', new Vector2(7, 4), new Rect(0, 0, 1, 1),
    ['vv'], MovementHeading::SOUTH, $this->terminalSprites, $this->graphicalSprites);
  $this->projector = new GraphicalSpriteProjector();
});

afterEach(function () {
  ob_end_clean();
  new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, $this->configState);
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, $this->eventState);
  foreach ([Console::class => $this->consoleState, Cursor::class => $this->cursorState] as $class => $state) {
    foreach ($state as $name => $value) {
      new ReflectionProperty($class, $name)->setValue(null, $value);
    }
  }
});

it('keeps existing constructor calls terminal-only without adding the capability to GameObject', function () {
  $legacy = new Player($this->scene, 'Legacy hero', new Vector2(2, 3), new Rect(0, 0, 1, 1), ['v']);
  expect($legacy)->toBeInstanceOf(GraphicalSpriteProviderInterface::class)
    ->and($legacy->getGraphicalSpriteDefinition())->toBeNull()
    ->and($this->projector->project($legacy, $this->camera))->toBeNull()
    ->and($legacy->getGraphicalSpriteId())->toBe('player')
    ->and(is_a(GameObject::class, GraphicalSpriteProviderInterface::class, true))->toBeFalse();
  $legacy->render();
  expect(Console::charAt(2, 3))->toBe('v');
});

it('preserves terminal configuration and resolves graphical art from the existing heading', function ($heading, $direction) {
  $plain = new Player($this->scene, 'Terminal hero', new Vector2(7, 4), new Rect(0, 0, 1, 1),
    ['vv'], $heading, $this->terminalSprites);
  $graphical = new Player($this->scene, 'Graphical hero', new Vector2(7, 4), new Rect(0, 0, 1, 1),
    ['vv'], $heading, $this->terminalSprites, $this->graphicalSprites);
  expect($graphical->getGraphicalSpriteDefinition())->toBe($this->graphicalSprites->$direction)
    ->and($graphical->heading)->toBe($plain->heading)
    ->and($graphical->sprite)->toBe($plain->sprite)->toBe($this->terminalSprites[$direction])
    ->and($graphical->getDirectionalSprites())->toBe($plain->getDirectionalSprites())->toBe($this->terminalSprites);
  $plain->render();
  $terminal = Console::snapshot();
  $graphical->render();
  expect(Console::snapshot())->toEqual($terminal);
})->with([
  [MovementHeading::NORTH, 'north'], [MovementHeading::EAST, 'east'],
  [MovementHeading::SOUTH, 'south'], [MovementHeading::WEST, 'west'], [MovementHeading::NONE, 'south'],
]);

it('uses south for NONE and changes direction without moving or maintaining graphical-facing state', function () {
  new ReflectionProperty(Player::class, 'heading')->setValue($this->player, MovementHeading::NONE);
  expect($this->player->getGraphicalSpriteDefinition())->toBe($this->graphicalSprites->south);
  foreach ([[0, -1, 'north'], [1, 0, 'east'], [0, 1, 'south'], [-1, 0, 'west']] as [$x, $y, $direction]) {
    $this->player->updatePlayerSprite(new Vector2($x, $y));
    expect($this->player->getGraphicalSpriteDefinition())->toBe($this->graphicalSprites->$direction)
      ->and($this->player->getGraphicalSpriteId())->toBe('player')
      ->and([$this->player->position->x, $this->player->position->y])->toBe([7.0, 4.0]);
  }
});

it('returns a defensive logical position independent of terminal widths overhang and Camera state', function ($terminal) {
  $this->player->sprite = $terminal;
  $this->camera->moveTo(3, 2);
  $position = $this->player->getGraphicalSpriteWorldPosition();
  $position->x = 100;
  $this->player->position->x = 11;
  $sprite = $this->projector->project($this->player, $this->camera);
  $current = $this->player->getGraphicalSpriteWorldPosition();
  expect([$current->x, $current->y])->toBe([11.0, 4.0])
    ->and($current)->not->toBe($this->player->position)
    ->and([$sprite->x, $sprite->y])->toBe([8, 2])
    ->and($this->player->getGraphicalSpriteDefinition())->toBe($this->graphicalSprites->south)
    ->and($this->player->getDirectionalSprites())->toBe($this->terminalSprites)
    ->and($this->player->sprite)->toBe($terminal);
})->with([[['@']], [["\u{1F6B6}"]], [['<---wide--->', 'second row']]]);

it('projects without changing Player Camera Console cursor output or transport state', function () {
  $this->player->render();
  $player = serialize($this->player);
  $camera = serialize($this->camera);
  $console = new ReflectionClass(Console::class)->getStaticProperties();
  $cursor = new ReflectionClass(Cursor::class)->getStaticProperties();
  $output = ob_get_contents();
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $sprite = $this->projector->project($this->player, $this->camera);
  expect($sprite->id)->toBe('player')
    ->and(serialize($this->player))->toBe($player)->and(serialize($this->camera))->toBe($camera)
    ->and(new ReflectionClass(Console::class)->getStaticProperties())->toBe($console)
    ->and(new ReflectionClass(Cursor::class)->getStaticProperties())->toBe($cursor)
    ->and(ob_get_contents())->toBe($output)
    ->and($transport->sent)->toBe([])->and($transport->polls)->toBe(0)->and($transport->shutdowns)->toBe(0);
});

it('presents a real Player through the shared client with immutable frames and duplicate suppression', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $input = new RendererInputSource($client);
  $presentation = new RendererPresentation($client, new RendererGridConfig(20, 10));
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":1,"type":"key","key":"up"}')];
  $this->player->render();
  $snapshot = Console::snapshot();
  expect(Console::charAt(7, 4))->toBe('v');
  $south = $this->projector->project($this->player, $this->camera);
  expect($presentation->present($snapshot, [$south]))->toBeTrue();
  $this->player->updatePlayerSprite(Vector2::up());
  $north = $this->projector->project($this->player, $this->camera);
  expect($presentation->present($snapshot, [$north]))->toBeTrue()
    ->and(array_diff_assoc($north->toArray(), $south->toArray()))->toBe(['asset' => $this->graphicalSprites->north->asset]);

  $this->player->position->x = 11;
  $this->player->position->y = 8;
  $this->camera->moveTo(3, 2);
  $moved = $this->projector->project($this->player, $this->camera);
  expect([$moved->x, $moved->y])->toBe([8, 6])
    ->and($presentation->present($snapshot, [$moved]))->toBeTrue()
    ->and($presentation->present($snapshot, [$this->projector->project($this->player, $this->camera)]))->toBeFalse();
  expect($transport->sent)->toHaveCount(3)->and($transport->polls)->toBe(0)
    ->and($transport->shutdowns)->toBe(0)->and($input->poll())->toBe(KeyCode::UP);
  foreach ([$south, $north, $moved] as $index => $sprite) {
    expect($transport->sent[$index]->type)->toBe(RendererMessageType::FRAME)
      ->and($transport->sent[$index]->payload)->toBe([
        'frame' => $index + 1, 'text' => $snapshot->rows, 'sprites' => [$sprite->toArray()],
      ]);
  }
  expect(Console::snapshot())->toEqual($snapshot)->and($this->player->sprite)->toBe(['^^']);
  $this->player->render();
  expect(Console::charAt(8, 6))->toBe('^');
});

it('observes a real blocked move as changed facing and unchanged graphical position', function () {
  $map = (new ReflectionClass(MapManager::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($map, $this->scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($map, [3 => [7 => CollisionType::SOLID->value]]);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  $before = $this->projector->project($this->player, $this->camera);
  expect($before->asset)->toBe($this->graphicalSprites->south->asset)
    ->and($map->getCollision(7, 3))->toBe(CollisionType::SOLID)
    ->and($this->player->tryMove(Vector2::up(), $this->camera))->toBeFalse();
  $after = $this->projector->project($this->player, $this->camera);
  expect($this->player->heading)->toBe(MovementHeading::NORTH)
    ->and([$this->player->position->x, $this->player->position->y])->toBe([7.0, 4.0])
    ->and($this->player->getGraphicalSpriteDefinition())->toBe($this->graphicalSprites->north)
    ->and(array_diff_assoc($after->toArray(), $before->toArray()))->toBe(['asset' => $this->graphicalSprites->north->asset])
    ->and($this->player->sprite)->toBe(['^^'])->and(Console::charAt(7, 4))->toBe('^');
});
