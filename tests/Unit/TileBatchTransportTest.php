<?php

use Ichiloto\Engine\IO\Console\ConsolePresentationSnapshot;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Tests\Support\Rendering\RetainedFrameState;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTileBatch;
use Ichiloto\Engine\Rendering\Presentation\RendererPresentation;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Presentation\StyledPresentationFrame;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\Enumerations\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererStartupException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Tests\Support\Input\FakeRendererTransport;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

function tileBatch(string $id = 'terrain', int $column = 0): PresentationTileBatch
{
  return new PresentationTileBatch($id, 'field.png', -100, [new SpriteSourceRect(0,0,16,32)],
    [['column'=>$column, 'row'=>0, 'source'=>0]]);
}

it('negotiates terrain at startup and fails closed without acknowledgement', function ($scenario) {
  $session = new RendererSessionConfig('Tiles', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['sprite_source_rect', 'tile_batches']);
  $transport = new ProcessRendererTransport(new RendererProcessConfig([
    PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', $scenario,
  ]));
  $client = new RendererClient($transport);
  try {
    if ($scenario === 'normal') {
      expect(fn() => $client->start($session))->toThrow(RendererStartupException::class, 'did not acknowledge');
    } else {
      $client->start($session);
      $client->pump();
      expect($client->supports('tile_batches'))->toBeTrue()->and($client->supports('sprite_source_rect'))->toBeTrue();
    }
  } finally { $transport->shutdown(); }
})->with(['normal', 'capabilities']);

it('rejects tile capabilities in v1 and explicitly removes stateless presenter tile batches', function () {
  expect(fn() => new RendererSessionConfig('Tiles', sys_get_temp_dir(), requiredCapabilities: ['tile_batches']))
    ->toThrow(InvalidArgumentException::class);
  $transport = new FakeRendererTransport();
  $presenter = new RendererPresentation(new RendererClient($transport), new RendererGridConfig(2,1));
  expect(fn() => $presenter->present(new ConsolePresentationSnapshot(2,1,[]), [], [tileBatch()]))
    ->toThrow(RendererProtocolException::class, 'retained world')->and($transport->sent)->toBe([]);
});

it('retains terrain identity removes omitted worlds and resets after rejected terrain delivery', function () {
  $transport = new FakeRendererTransport();
  $client = new RendererClient($transport);
  $client->start(new RendererSessionConfig('Tiles', sys_get_temp_dir(), protocol: RendererProtocolVersion::V2,
    requiredCapabilities: ['tile_batches']));
  $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"ready","capabilities":["tile_batches"]}')];
  $client->pump();
  $presenter = new RendererPresentation($client, new RendererGridConfig(2,1));
  $snapshot = new ConsolePresentationSnapshot(2,1,[]);
  $createWorld = static function (string $text): PresentationWorld {
    return PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 0, false, '00-terrain.txt', $text)]));
  };
  $original = $createWorld('....');
  $changed = $createWorld('..xx');
  expect($presenter->present($snapshot, world: $original))->toBeTrue()
    ->and($presenter->present($snapshot, world: $original))->toBeFalse();
  $transport->sendFailure = new RendererTransportException('backpressure');
  expect(fn() => $presenter->present($snapshot, world: $changed))->toThrow(RendererTransportException::class);
  expect(fn() => $presenter->present($snapshot, world: $original))->toThrow(RendererTransportException::class);
  $transport->sendFailure = null;
  expect($presenter->present($snapshot, world: $changed))->toBeTrue()
    ->and($presenter->present($snapshot))->toBeTrue()
    ->and($presenter->present($snapshot))->toBeFalse()
    ->and(array_column(array_map(fn($m) => $m->payload, $transport->sent), 'frame'))->toBe([1,2,3])
    ->and($transport->sent[1]->payload['reset'])->toBeTrue()
    ->and($transport->sent[2]->payload['operations'])->toBe([['op' => 'remove', 'kind' => 'world', 'id' => 'map']]);
  $frames = RetainedFrameState::replay($transport->sent);
  expect($frames[0]['worlds']['map']['columns'])->toBe(2)->and($frames[2])->not->toHaveKey('worlds')
    ->and($frames[0]['worlds']['map']['glyphRows']['map:terrain'][0][1]['glyph'])->toBe('..')
    ->and($frames[1]['worlds']['map']['glyphRows']['map:terrain'][0][1]['glyph'])->toBe('xx');
  $rows = array_values(array_filter($transport->sent[1]->payload['operations'], fn($op) => $op['op'] === 'worldRows'));
  expect(array_column($rows[0]['rows'][0]['cells'], 'glyph'))->toBe(['..', 'xx']);
});

it('replays world row replacement by owner and clears empty rows and replaced world state', function () {
  $state = new RetainedFrameState();
  $put = ['op' => 'put', 'kind' => 'world', 'id' => 'map', 'value' => ['columns' => 2, 'rows' => 1,
    'layers' => [['id' => 'back', 'layer' => -100, 'kind' => 'gameplay'], ['id' => 'front', 'layer' => -99, 'kind' => 'gameplay']]]];
  $cell = static fn(string $owner) => ['glyph' => '.', 'foreground' => null, 'background' => null,
    'displayWidth' => 1, 'ownerLayerId' => $owner];
  $message = static fn(int $generation, array $operations, bool $reset = false) => new RendererMessage(
    RendererMessageType::FRAME, ['frame' => $generation, 'baseGeneration' => $generation - 1,
      'generation' => $generation, 'reset' => $reset, 'present' => true, 'operations' => $operations], RendererProtocolVersion::V2);
  $state->applyMessage($message(1, [$put,
    ['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 0, 'cells' => [$cell('back'), $cell('front')]]]],
  ], true));
  expect($state->getFrame()['worlds']['map']['glyphRows']['front'][0])->toBe([1 => $cell('front')]);
  $state->applyMessage($message(2, [
    ['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 0, 'cells' => [$cell('back'), $cell('back')]]]],
  ]));
  expect($state->getFrame()['worlds']['map']['glyphRows'])->toBe(['back' => [0 => [$cell('back'), $cell('back')]]]);
  $state->applyMessage($message(3, [$put]));
  expect($state->getFrame()['worlds']['map']['glyphRows'])->toBe([]);
  $state->applyMessage($message(4, [['op' => 'remove', 'kind' => 'world', 'id' => 'map']]));
  expect($state->getFrame())->not->toHaveKey('worlds');
  $state->applyMessage($message(5, [$put]));
  $state->applyMessage($message(6, [], true));
  expect($state->getFrame())->not->toHaveKey('worlds');
});

it('keeps immutable catalogs and cell values detached from external references', function () {
  $source = new SpriteSourceRect(0,0,1,1);
  $column = 0;
  $cell = ['column'=>&$column, 'row'=>0, 'source'=>0];
  $batch = new PresentationTileBatch('t', 't.png', -100, [&$source], [&$cell]);
  $source = new SpriteSourceRect(1,0,1,1);
  $column = 1;
  $cell['row'] = 2;
  expect($batch->sources[0]->x)->toBe(0)->and($batch->cells)->toBe([['column'=>0,'row'=>0,'source'=>0]]);
  $frame = new StyledPresentationFrame(1, tileBatches:[&$batch]);
  $batch = tileBatch('replacement');
  expect($frame->tileBatches[0]->id)->toBe('t');
});

it('enforces independent exact aggregate source batch and cell budgets', function () {
  $sources = array_fill(0, 256, new SpriteSourceRect(0,0,1,1));
  $batch = new PresentationTileBatch('t', 't.png', 0, $sources, []);
  $batches = [];
  for ($i=0; $i<16; $i++) { $batches[] = new PresentationTileBatch((string)$i, 't.png', 0, $sources, []); }
  expect(new StyledPresentationFrame(1, tileBatches:$batches)->tileBatches)->toHaveCount(16);
  expect(fn() => new StyledPresentationFrame(1, tileBatches:[...$batches, tileBatch('other')]))->toThrow(InvalidArgumentException::class);
  $batches = array_map(fn($i) => tileBatch((string)$i), range(0,63));
  expect(new StyledPresentationFrame(1, tileBatches:$batches)->tileBatches)->toHaveCount(64);
  expect(fn() => new StyledPresentationFrame(1, tileBatches:[...$batches, tileBatch('other')]))->toThrow(InvalidArgumentException::class);
  $cells = array_map(fn($i) => ['column'=>$i%512,'row'=>intdiv($i,512),'source'=>0], range(0,32767));
  $full = new PresentationTileBatch('full', 't.png', -100, [$sources[0]], $cells);
  expect(new StyledPresentationFrame(1, tileBatches:[$full])->tileBatches[0]->cells)->toHaveCount(32768);
  expect(fn() => new StyledPresentationFrame(1, tileBatches:[$full,tileBatch()]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new PresentationTileBatch('t','t.png',0,[...$sources,$sources[0]],[]))->toThrow(InvalidArgumentException::class);
  expect(fn() => new StyledPresentationFrame(1, tileBatches:[$batch,$batch]))->toThrow(InvalidArgumentException::class);
});

it('renders a complete tile viewport independently of the existing actor budget', function () {
  $cells = array_map(fn($i) => ['column'=>$i%135,'row'=>intdiv($i,135),'source'=>0], range(0,4859));
  $batch = new PresentationTileBatch('terrain','field.png',-100,[new SpriteSourceRect(0,0,16,32)],$cells);
  $batch->assertWithin(new RendererGridConfig(135,36,10,20));
  $sprites = array_map(fn($i) => new PresentationSprite((string)$i,'p.png',0,0,1,1),range(0,1023));
  $frame = new StyledPresentationFrame(1, sprites:$sprites, tileBatches:[$batch]);
  expect($frame->sprites)->toHaveCount(1024)->and($frame->tileBatches[0]->cells)->toHaveCount(4860);
  expect(fn() => new StyledPresentationFrame(1, sprites:[...$sprites,new PresentationSprite('extra','p.png',0,0,1,1)]))
    ->toThrow(InvalidArgumentException::class);
});

it('matches the exact accepted Renderer wire fixtures without adding an Engine inbound parser', function ($filename) {
  $fixture = json_decode(file_get_contents(__DIR__ . '/../Fixtures/Renderer/tile-batches/' . $filename), true, flags:JSON_THROW_ON_ERROR);
  $batches = [];
  foreach ($fixture['tileBatches'] ?? [] as $batch) {
    $sources = array_map(fn($rect) => new SpriteSourceRect($rect['x'],$rect['y'],$rect['width'],$rect['height']), $batch['sources']);
    $typed = new PresentationTileBatch($batch['id'],$batch['asset'],$batch['layer'],$sources,$batch['cells']);
    $typed->assertWithin(new RendererGridConfig(135,36,10,20));
    expect($typed->toArray())->toBe($batch);
    $batches[] = $typed;
  }
  $message = new StyledPresentationFrame($fixture['frame'], tileBatches:$batches)->toRendererMessage();
  expect($message->payload['tileBatches'] ?? [])->toBe($fixture['tileBatches'] ?? []);
})->with(['valid-tiles-text-player-ui.json','valid-full-viewport.json','valid-empty-cells.json',
  'valid-overlapping-batches.json','clear-empty.json','clear-omitted.json']);

it('rejects malformed compact destinations catalogs and identity', function () {
  $source = new SpriteSourceRect(0,0,1,1);
  foreach ([[], [['column'=>0,'row'=>0,'source'=>1]], [['column'=>0,'row'=>0,'source'=>0,'extra'=>1]],
    [['column'=>0.0,'row'=>0,'source'=>0]], [['column'=>-1,'row'=>0,'source'=>0]],
    [['column'=>0,'row'=>0,'source'=>'0']]] as $cells) {
    if ($cells === []) { continue; }
    expect(fn() => new PresentationTileBatch('t','t.png',0,[$source],$cells))->toThrow(InvalidArgumentException::class);
  }
  $cell = ['column'=>0,'row'=>0,'source'=>0];
  expect(fn() => new PresentationTileBatch('t','t.png',0,[$source],[$cell,$cell]))->toThrow(InvalidArgumentException::class);
  foreach (['',str_repeat('t',257),"\xff"] as $id) {
    expect(fn() => new PresentationTileBatch($id,'t.png',0,[$source],[]))->toThrow(InvalidArgumentException::class);
  }
});
