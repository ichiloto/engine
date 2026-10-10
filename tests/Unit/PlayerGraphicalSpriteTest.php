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
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProjector;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteProviderInterface;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Tests\Support\Input\FakeRendererTransport;
use function Tests\Support\Rendering\characterSheetData;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

/** Supplies scene identity without starting a Game or claiming terminal lifecycle ownership. */
final class SpritePresentationTestGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

/**
 * The standing frame (pattern 1) of the fixture's 48 x 48 pixel frames, by
 * RPG Maker direction row: down, left, right, up.
 *
 * @return array{x: int, y: int, width: int, height: int}
 */
function getPlayerStandingFrame(string $direction): array
{
  $row = ['south' => 0, 'west' => 1, 'east' => 2, 'north' => 3][$direction];
  return ['x' => 48, 'y' => $row * 48, 'width' => 48, 'height' => 48];
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
  Console::setTerminalOutputEnabled(false);
  ob_start();

  // Reuse the lightweight scene/map fixture approach; Player and Camera run their real constructors.
  $this->scene = $this->getMockBuilder(GameScene::class)->disableOriginalConstructor()->onlyMethods(['getGame'])->getMock();
  $this->scene->method('getGame')->willReturn(new SpritePresentationTestGame());
  $this->camera = new Camera($this->scene, 20, 10, worldSpace: array_fill(0, 30, str_repeat('.', 40)));
  new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene, $this->camera);
  $this->terminalSprites = ['north' => ['^^'], 'east' => ['>>'], 'south' => ['vv'], 'west' => ['<<']];
  $this->assetRoot = sys_get_temp_dir() . '/ichiloto-player-sprite-' . bin2hex(random_bytes(4));
  $this->sheet = characterSheetData()['sheet'];
  writeCharacterSheetPng($this->assetRoot . '/' . $this->sheet, 48, 48);
  $this->graphicalSprites = CharacterSheet::fromArray(characterSheetData());
  $this->player = new Player($this->scene, 'Test hero', new Vector2(7, 4), new Rect(0, 0, 1, 1),
    ['vv'], MovementHeading::SOUTH, $this->terminalSprites, $this->graphicalSprites, $this->assetRoot);
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
  $paths = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->assetRoot,
    FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($paths as $path) { $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname()); }
  rmdir($this->assetRoot);
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
    ['vv'], $heading, $this->terminalSprites, $this->graphicalSprites, $this->assetRoot);
  $definition = $graphical->getGraphicalSpriteDefinition();
  expect($definition->asset)->toBe($this->sheet)
    ->and($definition->sourceRect->toArray())->toBe(getPlayerStandingFrame($direction))
    ->and([$definition->width, $definition->height, $definition->layer])->toBe([48, 48, 100])
    ->and($definition->lift)->toBe(6)
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
  expect($this->player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerStandingFrame('south'));
  foreach ([[0, -1, 'north'], [1, 0, 'east'], [0, 1, 'south'], [-1, 0, 'west']] as [$x, $y, $direction]) {
    $this->player->updatePlayerSprite(new Vector2($x, $y));
    expect($this->player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerStandingFrame($direction))
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
    ->and($this->player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerStandingFrame('south'))
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
  // Character frames are source rects; negotiate sprite_source_rect as the native renderer does.
  $client->start(new RendererSessionConfig('Player sprites', $this->assetRoot, new RendererGridConfig(20, 10),
    RendererProtocolVersion::V2));
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["sprite_source_rect","tile_batches"]}')];
  $client->pollEvents();
  expect($client->supports(RendererSessionConfig::SPRITE_SOURCE_RECT))->toBeTrue();
  $polls = $transport->polls;
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"key","key":"up"}')];
  $this->player->render();
  $snapshot = Console::snapshot();
  expect(Console::charAt(7, 4))->toBe('v');
  $south = $this->projector->project($this->player, $this->camera);
  expect($presentation->present($snapshot, [$south]))->toBeTrue();
  $this->player->updatePlayerSprite(Vector2::up());
  $north = $this->projector->project($this->player, $this->camera);
  expect($presentation->present($snapshot, [$north]))->toBeTrue()
    ->and($north->toArray())->toBe([...$south->toArray(), 'sourceRect' => getPlayerStandingFrame('north')]);

  $this->player->position->x = 11;
  $this->player->position->y = 8;
  $this->camera->moveTo(3, 2);
  $moved = $this->projector->project($this->player, $this->camera);
  expect([$moved->x, $moved->y])->toBe([8, 6])
    ->and($presentation->present($snapshot, [$moved]))->toBeTrue()
    ->and($presentation->present($snapshot, [$this->projector->project($this->player, $this->camera)]))->toBeFalse();
  expect($transport->sent)->toHaveCount(3)->and($transport->polls)->toBe($polls)
    ->and($transport->shutdowns)->toBe(0)->and($input->poll())->toBe(KeyCode::UP);
  $frames = Tests\Support\Rendering\RetainedFrameState::replay($transport->sent);
  foreach ([$south, $north, $moved] as $index => $sprite) {
    expect($transport->sent[$index]->type)->toBe(RendererMessageType::FRAME)
      ->and($frames[$index]['frame'])->toBe($index + 1)
      ->and(array_column($frames[$index]['textLayers'][0]['runs'], 'text'))->toBe($snapshot->rows)
      ->and($frames[$index]['sprites'])->toBe([$sprite->toArray()]);
    if ($index > 0) {
      expect(array_column($transport->sent[$index]->payload['operations'], 'kind'))->toBe(['sprite']);
    }
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
  expect($before->sourceRect->toArray())->toBe(getPlayerStandingFrame('south'))
    ->and($map->getCollision(7, 3))->toBe(CollisionType::SOLID)
    ->and($this->player->tryMove(Vector2::up(), $this->camera))->toBeFalse();
  $after = $this->projector->project($this->player, $this->camera);
  expect($this->player->heading)->toBe(MovementHeading::NORTH)
    ->and([$this->player->position->x, $this->player->position->y])->toBe([7.0, 4.0])
    ->and($this->player->getGraphicalSpriteDefinition()->sourceRect->toArray())->toBe(getPlayerStandingFrame('north'))
    ->and($after->toArray())->toBe([...$before->toArray(), 'sourceRect' => getPlayerStandingFrame('north')])
    ->and($this->player->sprite)->toBe(['^^'])->and(Console::charAt(7, 4))->toBe('^');
});

it('arrives only once the step has slid into its cell, and at once without a slide', function () {
  $map = (new ReflectionClass(MapManager::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($map, $this->scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($map, [3 => [7 => CollisionType::SAVE_POINT->value]]);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  $arrivals = new class implements Ichiloto\Engine\Events\Interfaces\ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, Ichiloto\Engine\Events\Interfaces\EventInterface $event): void { $this->events[] = $event; }
  };
  $modals = $this->getMockBuilder(Ichiloto\Engine\UI\Modal\ModalManager::class)->disableOriginalConstructor()->onlyMethods(['alert'])->getMock();
  $notices = [];
  $modals->method('alert')->willReturnCallback(function (string $message, string $title) use (&$notices): void { $notices[] = $title; });
  $modalState = new ReflectionProperty(Ichiloto\Engine\UI\Modal\ModalManager::class, 'instance')->getValue();
  new ReflectionProperty(Ichiloto\Engine\UI\Modal\ModalManager::class, 'instance')->setValue(null, $modals);
  new ReflectionProperty(Console::class, 'game')->setValue(null, new SpritePresentationTestGame());
  try {
    // A graphical step onto the save point: the notice and the observers wait for the slide.
    $this->player->addObserver($arrivals);
    expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
      ->and([$this->player->position->x, $this->player->position->y])->toBe([7.0, 3.0])
      ->and($arrivals->events)->toBe([])->and($notices)->toBe([]);
    $this->player->advanceGraphicalAnimation(0.01);
    $this->player->completeArrival();
    expect($arrivals->events)->toBe([])->and($notices)->toBe([]);
    $this->player->advanceGraphicalAnimation(1.0);
    $this->player->completeArrival();
    expect($arrivals->events)->toHaveCount(1)->and($notices)->toBe(['Save Point']);
    $this->player->completeArrival();
    expect($arrivals->events)->toHaveCount(1);

    // A terminal player has no slide, so it arrives as it steps, as it always has.
    $terminal = new Player($this->scene, 'Terminal hero', new Vector2(7, 4), new Rect(0, 0, 1, 1), ['vv'],
      MovementHeading::SOUTH, $this->terminalSprites);
    $terminal->addObserver($arrivals);
    expect($terminal->tryMove(Vector2::up(), $this->camera))->toBeTrue()
      ->and($arrivals->events)->toHaveCount(2)->and($notices)->toBe(['Save Point', 'Save Point']);
  } finally {
    new ReflectionProperty(Ichiloto\Engine\UI\Modal\ModalManager::class, 'instance')->setValue(null, $modalState);
  }
});

it('completes a pending arrival before the next step so none is skipped', function () {
  $map = (new ReflectionClass(MapManager::class))->newInstanceWithoutConstructor();
  new ReflectionProperty(MapManager::class, 'gameScene')->setValue($map, $this->scene);
  new ReflectionProperty(MapManager::class, 'collisionMap')->setValue($map, [2 => [7 => CollisionType::NONE->value], 3 => [7 => CollisionType::NONE->value]]);
  new ReflectionProperty(GameScene::class, 'mapManager')->setValue($this->scene, $map);
  $arrivals = new class implements Ichiloto\Engine\Events\Interfaces\ObserverInterface {
    public array $events = [];
    public function onNotify(object $entity, Ichiloto\Engine\Events\Interfaces\EventInterface $event): void { $this->events[] = $event; }
  };
  $this->player->addObserver($arrivals);
  expect($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($this->player->tryMove(Vector2::up(), $this->camera))->toBeTrue()
    ->and($arrivals->events)->toHaveCount(1)
    ->and($arrivals->events[0]->destination->y)->toBe(3.0);
});
