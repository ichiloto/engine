<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Events\Triggers\TransferPlayerTrigger;
use Ichiloto\Engine\Field\{MapGridSource, MapSourceReader, RegionMap};

function makeRegionConnectionTransfer(string $destination, array $conditions = []): array
{
  return ['class' => TransferPlayerTrigger::class, 'data' => ['destinationMap' => $destination], 'conditions' => $conditions];
}

/** Synthetic event/data sources only: the connection scan must not need artwork or terrain. */
function writeRegionConnectionMaps(array $maps): string
{
  $root = createTestDirectory('ichiloto-known-connections-');
  foreach ($maps as $id => $data) {
    $directory = $root . '/' . $id;
    mkdir($directory, 0700, true);
    $data = array_replace(['name' => $id, 'region' => 'Synthetic region', 'events' => []], $data);
    $rows = array_fill(0, 10, str_repeat(' ', 20));
    $index = 0;
    foreach ($data['events'] as $marker => &$event) {
      $event['marker'] = strval($marker);
      $rows[1][$index++ * 2] = strval($marker);
    }
    unset($event);
    file_put_contents($directory . '/' . basename($id) . '.data.php', '<?php return ' . var_export($data, true) . ';');
    file_put_contents($directory . '/' . basename($id) . '.event.php', MapGridSource::buildSource(implode("\n", $rows), 'EVENT'));
  }
  RegionMap::loadFrom($root);
  return $root;
}

function makeRegionConnectionLandmark(string $id, string $marker, string $name, bool $secret = false): array
{
  return ['id' => $id, 'kind' => 'entrance', 'anchor' => ['event' => $marker],
    'name' => ['message' => 'test.connections.' . $id, 'default' => $name], 'secret' => $secret];
}

afterEach(function (): void { RegionMap::reset(); });

it('shows current and visited regional places and their immediate ordinary destinations without names or state writes', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['name' => 'Hub', 'events' => ['A' => makeRegionConnectionTransfer('r/shop', [['type' => 'switch', 'name' => 'open']]),
      'B' => makeRegionConnectionTransfer('world/road')]],
    'r/shop' => ['name' => 'Unvisited actual shop name', 'events' => ['A' => makeRegionConnectionTransfer('r/far')]],
    'r/visited' => ['name' => 'Visited detached room'],
    'r/far' => ['name' => 'Far room'],
    'r/inbound' => ['name' => 'Unvisited inbound source', 'events' => ['A' => makeRegionConnectionTransfer('r/hub')]],
    'world/road' => ['name' => 'Unvisited actual road name', 'region' => 'Outside'],
  ]);
  $state = new GameState(); $state->markMapVisited('r/visited');
  $writes = []; $state->onChange = static function (...$change) use (&$writes): void { $writes[] = $change; };
  $before = $state->toArray();
  $known = RegionMap::getKnownConnections($state, 'r/hub');
  expect(array_keys($known['places']))->toBe(['r/hub', 'r/visited', 'r/shop', 'world/road'])
    ->and($known['places']['r/shop']['name'])->toBeNull()->and($known['places']['world/road']['name'])->toBeNull()
    ->and($known['places']['r/hub']['visited'])->toBeTrue()->and($known['places']['r/visited']['name'])->toBe('Visited detached room')
    ->and($known['links'])->toBe([['from' => 'r/hub', 'to' => 'r/shop'], ['from' => 'r/hub', 'to' => 'world/road']])
    ->and($state->toArray())->toBe($before)->and($writes)->toBe([]);
  $positions = array_map(static fn(array $place): string => implode(',', $place['position']), $known['places']);
  expect(array_unique($positions))->toHaveCount(count($positions));
});

it('uses the owning learned route label until the destination has actually been visited', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/workshop')],
      'landmarks' => [makeRegionConnectionLandmark('workshop-door', 'A', 'Public workshop sign')]],
    'r/workshop' => ['name' => 'Actual room title'],
  ]);
  $state = new GameState();
  expect(RegionMap::getKnownConnections($state, 'r/hub')['places']['r/workshop']['name'])->toBeNull();
  $state->revealLandmark('r/hub', 'workshop-door');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['places']['r/workshop']['name'])->toBe('Public workshop sign');
  $state->markMapVisited('r/workshop');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['places']['r/workshop']['name'])->toBe('Actual room title');
});

it('never reveals a secret route from destination visitation or an unlatched story condition', function () {
  $secret = makeRegionConnectionLandmark('hidden-door', 'A', 'Revealed passage', true);
  $secret['revealConditions'] = [['type' => 'event', 'name' => 'heard_of_passage']];
  writeRegionConnectionMaps([
    'r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/vault')], 'landmarks' => [$secret]],
    'r/vault' => ['name' => 'Visited vault'],
  ]);
  $state = new GameState(); $state->markMapVisited('r/vault'); $state->recordStoryEvent('heard_of_passage');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['links'])->toBe([]);
  $state->revealLandmark('another/map', 'hidden-door');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['links'])->toBe([]);
  $state->revealLandmark('r/hub', 'hidden-door');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['links'])->toBe([['from' => 'r/hub', 'to' => 'r/vault']]);
});

it('does not leak an inbound secret source or expand through an unvisited ordinary neighbour', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/next')]],
    'r/next' => ['events' => ['A' => makeRegionConnectionTransfer('r/far')]],
    'r/far' => [],
    'r/hidden' => ['events' => ['A' => makeRegionConnectionTransfer('r/hub')],
      'landmarks' => [makeRegionConnectionLandmark('hidden-exit', 'A', 'Hidden source', true)]],
  ]);
  $state = new GameState(); $state->revealLandmark('r/hidden', 'hidden-exit');
  $known = RegionMap::getKnownConnections($state, 'r/hub');
  expect($known['places'])->not->toHaveKey('r/hidden')->not->toHaveKey('r/far')
    ->and($known['links'])->toBe([['from' => 'r/hub', 'to' => 'r/next']]);
});

it('keeps parallel route ownership and reciprocal directions distinct while drawing each directed pair once', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/next'), 'B' => makeRegionConnectionTransfer('r/next')],
      'landmarks' => [makeRegionConnectionLandmark('hidden-alternate', 'B', 'Alternate passage', true)]],
    'r/next' => ['events' => ['A' => makeRegionConnectionTransfer('r/hub')]],
  ]);
  $state = new GameState();
  $routes = RegionMap::areas()['r/hub']->routes;
  expect($routes)->toHaveCount(2)->and(array_map(static fn($route): bool => $route->isVisible($state), $routes))->toBe([true, false]);
  $state->revealLandmark('r/hub', 'hidden-alternate');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['links'])->toHaveCount(1)
    ->and(RegionMap::getKnownConnections($state, 'r/hub')['places']['r/next']['name'])->toBe('Alternate passage');
  $state->markMapVisited('r/next');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['links'])->toBe([
    ['from' => 'r/hub', 'to' => 'r/next'], ['from' => 'r/next', 'to' => 'r/hub'],
  ]);
});

it('scans legacy transfers through the same landmark secrecy and naming rules', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['triggers' => [
      ['destinationMap' => 'r/ordinary', 'trigger_area' => ['x' => 3, 'y' => 2], 'spawn_point' => ['x' => 99, 'y' => 99]],
      ['id' => 'concealed', 'destinationMap' => 'r/secret', 'trigger_area' => ['x' => 8, 'y' => 4]],
    ], 'landmarks' => [['id' => 'concealed-door', 'kind' => 'exit', 'anchor' => ['trigger' => 'concealed'],
      'secret' => true, 'name' => ['message' => 'test.legacy.name', 'default' => 'Known legacy passage']]]],
    'r/ordinary' => [], 'r/secret' => [],
  ]);
  $state = new GameState();
  expect(RegionMap::areas()['r/hub']->destinations())->toBe(['r/ordinary', 'r/secret'])
    ->and(RegionMap::getKnownConnections($state, 'r/hub')['places'])->not->toHaveKey('r/secret');
  $state->revealLandmark('r/hub', 'concealed-door');
  expect(RegionMap::getKnownConnections($state, 'r/hub')['places']['r/secret']['name'])->toBe('Known legacy passage');
});

it('preserves regional station layout and includes visited external return edges without expanding that region', function () {
  writeRegionConnectionMaps([
    'r/hub' => ['station' => ['x' => 2, 'y' => 1], 'events' => ['A' => makeRegionConnectionTransfer('r/side'), 'B' => makeRegionConnectionTransfer('outside/road')]],
    'r/side' => ['station' => ['x' => 7, 'y' => 3]],
    'outside/road' => ['region' => 'Outside', 'events' => ['A' => makeRegionConnectionTransfer('r/hub'), 'B' => makeRegionConnectionTransfer('outside/far')]],
    'outside/far' => ['region' => 'Outside'],
  ]);
  $state = new GameState();
  foreach (['r/hub', 'r/side', 'outside/road'] as $id) { $state->markMapVisited($id); }
  $a = RegionMap::getKnownConnections($state, 'r/hub'); $b = RegionMap::getKnownConnections($state, 'r/side');
  expect($a['places'])->toBe($b['places'])->and($a['places'])->not->toHaveKey('outside/far')
    ->and($a['links'])->toContain(['from' => 'outside/road', 'to' => 'r/hub']);
  foreach (RegionMap::place('r/hub') as $id => $position) { expect($a['places'][$id]['position'])->toBe($position); }
});

it('keeps the current place present if its source is unavailable', function () {
  RegionMap::loadFrom(createTestDirectory('ichiloto-empty-connections-'));
  expect(RegionMap::getKnownConnections(new GameState(), 'missing/current'))->toBe([
    'places' => ['missing/current' => ['position' => [0, 0], 'name' => null, 'visited' => true]], 'links' => [],
  ]);
});

it('reads event ownership safely in isolation and preserves ragged source geometry without loading terrain', function () {
  $root = writeRegionConnectionMaps(['r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/next')]], 'r/next' => []]);
  $paths = MapSourceReader::resolvePaths($root, 'r/hub');
  $data = ['name' => 'Hub', 'region' => 'R', 'events' => ['A' => makeRegionConnectionTransfer('r/next')]];
  file_put_contents($paths['data'], '<?php $id="corrupted"; $areas=[]; $filename="elsewhere"; return ' . var_export($data, true) . ';');
  file_put_contents($paths['event'], MapGridSource::buildSource("   \n  A   \n ", 'EVENT'));
  $source = MapSourceReader::readEventSource($paths);
  expect(array_map('count', $source['eventLayer']))->toBe([3, 6, 1])
    ->and($source['data']['events'][0]['area']['cells'])->toBe([[2, 1]]);
  RegionMap::loadFrom($root);
  expect(RegionMap::areas())->toHaveKey('r/hub')->not->toHaveKey('corrupted');
  $marker = $root . '/executed';
  file_put_contents($paths['event'], '<?php file_put_contents(' . var_export($marker, true) . ', "bad"); return "A";');
  RegionMap::loadFrom($root);
  expect(is_file($marker))->toBeFalse()->and(RegionMap::areas())->not->toHaveKey('r/hub');
});

it('fails closed on invalid landmark declarations rather than publishing a secret as an automatic passage', function () {
  $root = writeRegionConnectionMaps(['r/hub' => ['events' => ['A' => makeRegionConnectionTransfer('r/hidden')]], 'r/hidden' => []]);
  $paths = MapSourceReader::resolvePaths($root, 'r/hub');
  $data = require $paths['data'];
  $data['landmarks'] = [makeRegionConnectionLandmark('concealed', 'A', 'Do not leak', true)];
  $data['landmarks'][0]['approachDistance'] = 3;
  file_put_contents($paths['data'], '<?php return ' . var_export($data, true) . ';');
  RegionMap::loadFrom($root);
  expect(RegionMap::areas())->not->toHaveKey('r/hub')
    ->and(RegionMap::getKnownConnections(new GameState(), 'r/hub')['links'])->toBe([]);
});
