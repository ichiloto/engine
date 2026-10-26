<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Field\Enumerations\SkitBackdropSource;
use Ichiloto\Engine\Field\MapManager;
use Ichiloto\Engine\Field\RegionCatalog;
use Ichiloto\Engine\Field\SkitBackdrop;
use Ichiloto\Engine\Field\SkitManager;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

/** A game that owns no terminal, so letting it go restores nothing. */
final class SkitBackdropGameProbe extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class SkitBackdropSceneProbe extends GameScene
{
  public Game $probeGame;
  public function __construct() {}
  public function getGame(): Game { return $this->probeGame; }
}

final class SkitBackdropManagerProbe extends SkitManager
{
  /** @var list<DialogueContext> */
  public array $shown = [];
  public function __construct(GameScene $scene) { $this->gameScene = $scene; }
  public function playSkit(string $id, array $skit): void { $this->play($id, $skit); }
  protected function showBeat(array $beat, float $speed, DialoguePlayback $playback): void { $this->shown[] = $beat['presentation']; }
}

function skitBackdropRegions(): RegionCatalog
{
  return new RegionCatalog(['Synthetic Vale' => ['skitBackdrop' => 'Graphics/Skits/Vale/Region.png'], 'Bare Region' => []]);
}

beforeEach(function () {
  $this->root = sys_get_temp_dir() . '/skit-backdrop-' . bin2hex(random_bytes(5));
  mkdir($this->root . '/assets/Data/Actors', 0777, true);
  $this->debugState = new ReflectionClass(Debug::class)->getStaticProperties();
  Debug::configure(['log_directory' => $this->root]);
  $this->directory = getcwd();
});

afterEach(function () {
  chdir($this->directory);
  foreach ($this->debugState as $key => $value) { new ReflectionProperty(Debug::class, $key)->setValue(null, $value); }
  $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
  foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
  rmdir($this->root);
});

it('follows the place: the skit only when its content names a setting, else the map, else its region', function (array $skit, array $map, ?string $asset, SkitBackdropSource $source) {
  $backdrop = SkitBackdrop::resolve($skit, $map, skitBackdropRegions());
  expect($backdrop->asset)->toBe($asset)->and($backdrop->source)->toBe($source)
    ->and($backdrop->describeAbsence('synthetic-skit', 'vale/square'))->toBeNull();
})->with([
  'skit override wins everywhere' => [['backdrop' => 'Graphics/Skits/Override.png'],
    ['skitBackdrop' => 'Graphics/Skits/Vale/Square.png', 'region' => 'Synthetic Vale'], 'Graphics/Skits/Override.png', SkitBackdropSource::SKIT],
  'map over region' => [[], ['skitBackdrop' => 'Graphics/Skits/Vale/Square.png', 'region' => 'Synthetic Vale'],
    'Graphics/Skits/Vale/Square.png', SkitBackdropSource::MAP],
  'region when the map names none' => [[], ['region' => 'Synthetic Vale'], 'Graphics/Skits/Vale/Region.png', SkitBackdropSource::REGION],
  'an unset override defers to the place' => [['backdrop' => null], ['skitBackdrop' => 'Graphics/Skits/Vale/Square.png'],
    'Graphics/Skits/Vale/Square.png', SkitBackdropSource::MAP],
]);

it('reports an absence with the skit and the map instead of borrowing another place\'s art', function (array $map, string $reason) {
  $backdrop = SkitBackdrop::resolve([], $map, skitBackdropRegions());
  expect($backdrop->asset)->toBeNull()->and($backdrop->source)->toBe(SkitBackdropSource::NONE)
    ->and($backdrop->describeAbsence('synthetic-skit', 'vale/inn'))
    ->toContain('Skit synthetic-skit on map vale/inn shows no backdrop', $reason, 'theme backing');
})->with([
  'region without a backdrop' => [['region' => 'Bare Region'], 'region Bare Region names a backdrop'],
  'region with no record' => [['region' => 'Unlisted'], 'region Unlisted names a backdrop'],
  'no region' => [[], 'the map names no region'],
]);

it('keeps a broken binding its record\'s problem rather than passing the choice to a later record', function (array $skit, array $map, SkitBackdropSource $owner) {
  $backdrop = SkitBackdrop::resolve($skit, $map, skitBackdropRegions());
  expect($backdrop->asset)->toBeNull()->and($backdrop->source)->toBe($owner)
    ->and($backdrop->describeAbsence('synthetic-skit', 'vale/square'))->toContain('relative path under assets');
})->with([
  'unsafe skit path' => [['backdrop' => '../outside.png'], ['skitBackdrop' => 'Graphics/Skits/Vale/Square.png'], SkitBackdropSource::SKIT],
  'absolute map path' => [[], ['skitBackdrop' => '/tmp/square.png', 'region' => 'Synthetic Vale'], SkitBackdropSource::MAP],
  'empty map path' => [[], ['skitBackdrop' => '', 'region' => 'Synthetic Vale'], SkitBackdropSource::MAP],
]);

it('reads a map\'s part in the choice from its data, nothing else', function () {
  expect(SkitBackdrop::readMapBinding(['name' => 'Square', 'region' => 'Synthetic Vale', 'skitBackdrop' => 'a.png', 'bgm' => 'x']))
    ->toBe(['region' => 'Synthetic Vale', 'skitBackdrop' => 'a.png']);
});

it('loads regions keyed by the name maps write and refuses records the Engine cannot read', function () {
  expect(RegionCatalog::load($this->root . '/assets')->regions)->toBe([]);
  file_put_contents($this->root . '/assets/' . RegionCatalog::FILE, '<?php return ' . var_export(['schema' => RegionCatalog::SCHEMA,
    'regions' => ['Synthetic Vale' => ['skitBackdrop' => 'Graphics/Skits/Vale.png'], 'Quiet Moor' => []]], true) . ';');
  $regions = RegionCatalog::load($this->root . '/assets');
  expect($regions->getNames())->toBe(['Synthetic Vale', 'Quiet Moor'])
    ->and($regions->getSkitBackdrop('Synthetic Vale'))->toBe('Graphics/Skits/Vale.png')
    ->and($regions->getSkitBackdrop('Quiet Moor'))->toBeNull()
    ->and($regions->toArray())->toBe(RegionCatalog::fromArray($regions->toArray())->toArray());
  foreach ([['schema' => 'ichiloto.regions/2', 'regions' => []], ['schema' => RegionCatalog::SCHEMA],
    ['schema' => RegionCatalog::SCHEMA, 'regions' => [' Padded ' => []]],
    ['schema' => RegionCatalog::SCHEMA, 'regions' => ['Vale' => ['music' => 'x']]],
    ['schema' => RegionCatalog::SCHEMA, 'regions' => ['Vale' => ['skitBackdrop' => '../x.png']]],
    ['schema' => RegionCatalog::SCHEMA, 'regions' => ['Vale' => ['skitBackdrop' => 7]]]] as $data) {
    expect(fn() => RegionCatalog::fromArray($data))->toThrow(InvalidArgumentException::class);
  }
});

it('retires the dialogue catalogue\'s per-skit backgrounds with the upgrade that moves them', function () {
  mkdir($this->root . '/assets/Data/Presentation', 0777, true);
  file_put_contents($this->root . '/assets/' . DialoguePresentationCatalog::FILE, '<?php return ' . var_export([
    'schema' => 'ichiloto.dialogue/1', 'skits' => ['synthetic' => ['background' => 'a.png']]], true) . ';');
  expect(fn() => DialoguePresentationCatalog::load($this->root . '/assets'))
    ->toThrow(RuntimeException::class, 'Run `ichiloto upgrade`');
});

it('gives every beat of a playing skit the place\'s backdrop and logs a missing one with skit and map', function () {
  chdir($this->root);
  $scene = new SkitBackdropSceneProbe();
  $scene->probeGame = new SkitBackdropGameProbe();
  $maps = new ReflectionClass(MapManager::class)->newInstanceWithoutConstructor();
  foreach (['gameState' => new GameState(), 'party' => new Party(), 'currentMapId' => 'vale/square', 'mapManager' => $maps] as $name => $value) {
    new ReflectionProperty(GameScene::class, $name)->setValue($scene, $value);
  }
  file_put_contents($this->root . '/assets/' . RegionCatalog::FILE, '<?php return ' . var_export(skitBackdropRegions()->toArray(), true) . ';');
  $binding = new ReflectionProperty(MapManager::class, 'skitBackdropBinding');
  $skit = ['title' => 'Synthetic', 'speed' => 20, 'beats' => [['speaker' => 'Guide', 'text' => 'One.'], ['speaker' => 'Guide', 'text' => 'Two.']]];
  $manager = new SkitBackdropManagerProbe($scene);

  $binding->setValue($maps, ['region' => 'Synthetic Vale', 'skitBackdrop' => 'Graphics/Skits/Vale/Square.png']);
  $manager->playSkit('synthetic-skit', $skit);
  $binding->setValue($maps, ['region' => 'Synthetic Vale']);
  $manager->playSkit('synthetic-skit', $skit);
  new ReflectionProperty(GameScene::class, 'currentMapId')->setValue($scene, 'vale/inn');
  $binding->setValue($maps, ['region' => 'Bare Region']);
  $manager->playSkit('synthetic-skit', $skit);

  expect(array_map(static fn(DialogueContext $context): ?string => $context->backdrop, $manager->shown))->toBe([
    'Graphics/Skits/Vale/Square.png', 'Graphics/Skits/Vale/Square.png',
    'Graphics/Skits/Vale/Region.png', 'Graphics/Skits/Vale/Region.png', null, null,
  ]);
  $log = (string) file_get_contents($this->root . '/warning.log');
  expect(substr_count($log, 'shows no backdrop'))->toBe(1)
    ->and($log)->toContain('Skit synthetic-skit on map vale/inn shows no backdrop')
    ->not->toContain('on map vale/square');
});
