<?php

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasTextLayer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';
require_once __DIR__ . '/../Fixtures/Rendering/ScreenTransitions.php';

function finishRetainedTestUpload(RetainedPresentation $sender, FakeRendererTransport $transport, Closure $present): void
{
    for ($tick = 0; $tick < 2000; $tick++) {
        if ($transport->sent !== []) {
            $last = end($transport->sent)->payload;
            $sender->acknowledge($last['generation'], $last['present']);
        }
        if (!$sender->hasPendingUpload()) { return; }
        $present();
    }
    test()->fail('Retained upload did not finish within its bounded test ticks.');
}

function retainedTestWorld(int $width = 10, int $height = 4, int $layers = 1): PresentationWorld
{
    $list = [new MapLayer('floor', 0, false, 'floor', implode("\n", array_fill(0, $height, str_repeat('.', $width))))];
    for ($n = 1; $n < $layers; $n++) {
        $list[] = new MapLayer('detail' . $n, $n, false, 'detail', implode("\n", array_fill(0, $height, str_repeat(' ', $width))));
    }
    return PresentationWorld::getFromLayers(new MapLayerSet($list));
}

function retainedTestViewport(int $x = 0, int $y = 0, int $width = 200, int $height = 50): PresentationViewport
{
    return new PresentationViewport(1, 0, 0, new CanvasRectangle(0, 0, $width * 10, $height * 20),
        worldId: 'map', worldOriginX: $x, worldOriginY: $y);
}

it('uploads a world once and emits only a viewport for every scrolling direction', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld();
    $text = new ConsolePresentationChanges(200, 50, true, order: []);
    expect($sender->present($text, [], retainedTestViewport(), $world))->toBeTrue();
    $transport->acknowledgeLatestFrame($sender);
    $transport->sent = [];
    $text = new ConsolePresentationChanges(200, 50, false);
    foreach ([[1, 0], [1, 1], [0, 1], [0, 0], [-3, -2]] as [$x, $y]) {
        expect($sender->present($text, [], retainedTestViewport($x, $y), $world))->toBeTrue();
        $payload = end($transport->sent)->payload;
        $transport->acknowledgeLatestFrame($sender);
        expect($payload['operations'])->toBe([])->and($payload['reset'])->toBeFalse()
            ->and($payload['viewport']['worldOrigin'])->toBe(['column' => $x, 'row' => $y]);
    }
    expect($sender->present($text, [], retainedTestViewport(-3, -2), $world))->toBeFalse()
        ->and($transport->sent)->toHaveCount(5);
});

it('keeps scroll operations and payload independent of world size window size and layer count', function () {
    $payloads = [];
    // Keep numeric field digit counts equal; no cell or layer data may scale the packet.
    foreach ([[10, 4, 1, 130, 50], [221, 81, 3, 200, 50], [400, 100, 12, 200, 50]] as [$w, $h, $count, $cw, $ch]) {
        $transport = new FakeRendererTransport();
        $sender = new RetainedPresentation(new RendererClient($transport));
        $world = retainedTestWorld($w, $h, $count);
        $sender->present(new ConsolePresentationChanges($cw, $ch, true, order: []), [], retainedTestViewport(0, 0, $cw, $ch), $world);
        finishRetainedTestUpload($sender, $transport, fn() => $sender->present(new ConsolePresentationChanges($cw, $ch, false), [], retainedTestViewport(0, 0, $cw, $ch), $world));
        $transport->sent = [];
        $sender->present(new ConsolePresentationChanges($cw, $ch, false), [], retainedTestViewport(1, 0, $cw, $ch), $world);
        expect($transport->sent)->toHaveCount(1);
        $payload = $transport->sent[0]->payload;
        expect($payload['operations'])->toBe([]);
        unset($payload['generation'], $payload['baseGeneration']);
        $payloads[] = strlen(json_encode($payload, JSON_THROW_ON_ERROR));
        unset($world, $sender, $transport);
    }
    expect(array_unique($payloads))->toHaveCount(1);
});

it('sends changed text rows only and explicitly removes vanished layers and sprites', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $layer = fn(string $text) => ['id' => 'dialogue', 'layer' => 1000,
        'rows' => [['row' => 1, 'runs' => [new PresentationTextRun(1, 0, $text)]]]];
    $sprite = new PresentationSprite('actor', 'actor.png', 0, 0, 10, 20);
    $sender->present(new ConsolePresentationChanges(20, 4, true, [$layer('Hi')], order: ['dialogue']), [$sprite], null);
    $transport->acknowledgeLatestFrame($sender);
    $transport->sent = [];
    $sender->present(new ConsolePresentationChanges(20, 4, false, [$layer('Hello')]), [$sprite], null);
    $transport->acknowledgeLatestFrame($sender);
    expect($transport->sent[0]->payload['operations'])->toBe([['op' => 'textRows', 'id' => 'dialogue',
        'rows' => [['row' => 1, 'runs' => [new PresentationTextRun(1, 0, 'Hello')->toArray()]]]]]);
    $sender->present(new ConsolePresentationChanges(20, 4, false, removedIds: ['dialogue'], order: []), [], null);
    expect($transport->sent[1]->payload['operations'])->toBe([
        ['op' => 'remove', 'kind' => 'text', 'id' => 'dialogue'], ['op' => 'remove', 'kind' => 'sprite', 'id' => 'actor']]);
});

it('retains canvas elements through edits and removes field state across battle or menu entry', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld();
    $sender->present(new ConsolePresentationChanges(20, 4, true, order: []), [], retainedTestViewport(), $world);
    $transport->acknowledgeLatestFrame($sender);
    $image = fn(float $opacity) => new CanvasImage('portrait', 'actor.png', new CanvasRectangle(0, 0, 10, 20), opacity: $opacity);
    $sender->presentCanvas(new PresentationCanvas(100, 100, [$image(1)]));
    $entry = end($transport->sent)->payload;
    $transport->acknowledgeLatestFrame($sender);
    expect($entry['operations'][0])->toBe(['op' => 'remove', 'kind' => 'world', 'id' => 'map'])
        ->and($entry['viewport'])->toBeNull();
    $transport->sent = [];
    $sender->presentCanvas(new PresentationCanvas(100, 100, [$image(.5)]));
    $transport->acknowledgeLatestFrame($sender);
    expect($transport->sent[0]->payload['operations'])->toHaveCount(1)
        ->and($transport->sent[0]->payload['operations'][0]['kind'])->toBe('canvas_image');
    expect($sender->presentCanvas(new PresentationCanvas(100, 100, [$image(.5)])))->toBeFalse();
    $sender->present(new ConsolePresentationChanges(20, 4, true, order: []), [], retainedTestViewport(), $world);
    $operations = end($transport->sent)->payload['operations'];
    expect(array_column($operations, 'op'))->toContain('remove', 'worldRows')
        ->and($operations[0])->toBe(['op' => 'remove', 'kind' => 'canvas', 'id' => 'canvas']);
});

it('retains a field world camera sprites and notifications while dialogue overlays change and close', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld();
    $viewport = retainedTestViewport();
    $sprite = new PresentationSprite('actor', 'actor.png', 0, 0, 10, 20);
    $notice = ['id' => 'notice', 'layer' => 2000,
        'rows' => [['row' => 0, 'runs' => [new PresentationTextRun(0, 0, 'Notice')]]]];
    $sender->present(new ConsolePresentationChanges(20, 4, true, [$notice], order: ['notice']), [$sprite], $viewport, $world);
    $transport->acknowledgeLatestFrame($sender);
    $transport->sent = [];
    $canvas = new PresentationCanvas(200, 80, [new CanvasImage('dialogue-frame', 'frame.png', new CanvasRectangle(0, 40, 200, 40))]);
    $sender->present(new ConsolePresentationChanges(20, 4, false), [$sprite], $viewport, $world, $canvas);
    $operations = end($transport->sent)->payload['operations'];
    expect(array_column($operations, 'kind'))->toBe(['canvas', 'canvas_image'])
        ->and($operations[0]['value']['mode'])->toBe('overlay');
    $transport->acknowledgeLatestFrame($sender);
    $transport->sent = [];
    $sender->present(new ConsolePresentationChanges(20, 4, false), [$sprite], $viewport, $world);
    expect(end($transport->sent)->payload['operations'])->toBe([
        ['op' => 'remove', 'kind' => 'canvas', 'id' => 'canvas'],
        ['op' => 'remove', 'kind' => 'canvas_image', 'id' => 'dialogue-frame'],
    ]);
});

it('batches dense scene text with a live notice without accumulating overlays or losing retained content', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $grid = new RendererGridConfig(12, 1, 8, 8);
    $layers = array_map(fn($i) => new CanvasTextLayer('scene-' . $i, 30,
        ($i % 8) * 100, intdiv($i, 8) * 16, $grid,
        [new PresentationTextRun(0, 0, 'Record ' . $i, PresentationColor::rgb(255, 255, 255))]), range(0, 63));
    $scene = $sender->prepareCanvas(new PresentationCanvas(800, 200, textLayers: $layers));
    $notice = new PresentationCanvas(800, 200, textLayers: [
        new CanvasTextLayer('notice-title', 30, 0, 160, $grid,
            [new PresentationTextRun(0, 0, 'Achievement', PresentationColor::rgb(255, 255, 0))]),
        new CanvasTextLayer('notice-body', 30, 0, 176, $grid,
            [new PresentationTextRun(0, 0, 'Unlocked', PresentationColor::rgb(255, 255, 255))]),
    ]);
    expect($sender->presentFrame($scene, $notice))->toBeTrue();
    $frame = RetainedFrameState::getLatestFrame($transport->sent);
    $text = $frame['canvas']['textLayers'];
    expect(count($text))->toBeLessThanOrEqual(64);
    $visible = [];
    foreach ($text as $layer) {
        foreach ($layer['runs'] as $run) {
            $visible[$run['text']] = [$layer['origin']['x'] + $run['column'] * $layer['grid']['cellWidth'],
                $layer['origin']['y'] + $run['row'] * $layer['grid']['cellHeight']];
        }
    }
    foreach (range(0, 63) as $i) {
        expect($visible['Record ' . $i])->toEqual([($i % 8) * 100, intdiv($i, 8) * 16]);
    }
    expect($visible['Achievement'])->toEqual([0, 160])->and($visible['Unlocked'])->toEqual([0, 176])
        ->and($sender->captureFrame()->canvas->textLayers)->toHaveCount(64)
        ->and($sender->presentFrame($scene, $notice))->toBeFalse();
    $sender->presentFrame($scene);
    expect(RetainedFrameState::getLatestFrame($transport->sent)['canvas']['textLayers'])->toHaveCount(64);
});

it('resends retained state once after rejection resize and a new renderer session', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld();
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    $sender->present($text, [], retainedTestViewport(), $world);
    foreach ([false, false, true] as $newSession) {
        $previous = end($transport->sent)->payload['generation'];
        $sender->invalidate($newSession);
        $sender->present(new ConsolePresentationChanges(20, 4, false), [], retainedTestViewport(), $world);
        $packet = end($transport->sent)->payload;
        expect($packet['reset'])->toBeTrue()->and($packet['generation'])->toBe($newSession ? 1 : $previous + 1)
            ->and($packet['operations'])->toBe($world->operations);
        expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], retainedTestViewport(), $world))->toBeFalse();
    }
});

it('interrupts a staged upload on resynchronization and retries an atomic reset', function () {
    $transport = new FakeRendererTransport();
    $client = new RendererClient($transport);
    $sender = null;
    $interrupt = true;
    $sender = new RetainedPresentation($client, function () use (&$sender, &$interrupt): void {
        if ($interrupt) { $interrupt = false; $sender->invalidate(); }
    });
    $world = retainedTestWorld(221, 81);
    $text = new ConsolePresentationChanges(200, 50, true, order: []);
    $sender->present($text, [], retainedTestViewport(), $world);
    expect($transport->sent)->toHaveCount(1)->and($transport->sent[0]->payload['present'])->toBeFalse();
    $transport->sent = [];
    $sender->present(new ConsolePresentationChanges(200, 50, false), [], retainedTestViewport(), $world);
    finishRetainedTestUpload($sender, $transport, fn() => $sender->present(new ConsolePresentationChanges(200, 50, false), [], retainedTestViewport(), $world));
    expect($transport->sent[0]->payload['reset'])->toBeTrue()->and(end($transport->sent)->payload['present'])->toBeTrue();
    foreach ($transport->sent as $index => $message) {
        expect($message->payload['frame'])->toBe(2)->and($message->payload['generation'])->toBe($index + 2)
            ->and(strlen($message->encode()))->toBeLessThan(RetainedPresentation::MAX_MESSAGE_BYTES);
    }
});

it('recovers a dropped final packet without requiring another changed frame and ignores delayed acknowledgements', function () {
    $transport = new FakeRendererTransport();
    $time = 0.0;
    $sender = new RetainedPresentation(new RendererClient($transport), clock: function () use (&$time): float { return $time; });
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    $sender->present($text, [], null);
    $text = new ConsolePresentationChanges(20, 4, false);
    $time = RetainedPresentation::ACK_TIMEOUT_SECONDS / 2;
    expect($sender->present($text, [], null))->toBeFalse();
    $time = RetainedPresentation::ACK_TIMEOUT_SECONDS;
    expect($sender->present($text, [], null))->toBeTrue()
        ->and(end($transport->sent)->payload['reset'])->toBeTrue()
        ->and(end($transport->sent)->payload['generation'])->toBe(2);
    $sender->acknowledge(2, true);
    $sender->acknowledge(1, true);
    $time += RetainedPresentation::ACK_TIMEOUT_SECONDS * 2;
    expect($sender->present($text, [], null))->toBeFalse()->and($transport->sent)->toHaveCount(2);
});

it('bounds unacknowledged cold packets and coalesces changing text sprites and viewport without starving completion', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld(256, 256);
    $text = fn(string $value) => new ConsolePresentationChanges(200, 50, false, [
        ['id' => 'dialogue', 'layer' => 1000, 'rows' => [['row' => 1, 'runs' => [new PresentationTextRun(1, 0, $value)]]]],
    ], order: ['dialogue']);
    $sprite = fn(int $x) => [new PresentationSprite('actor', 'actor.png', $x, 0, 10, 20)];
    $sender->present($text('initial'), $sprite(0), retainedTestViewport(), $world);
    expect($sender->hasPendingUpload())->toBeTrue()->and($transport->sent)->toHaveCount(RetainedPresentation::MAX_IN_FLIGHT);
    foreach (range(1, 60) as $tick) {
        expect($sender->present($text('latest ' . $tick), $sprite($tick), retainedTestViewport($tick), $world))->toBeFalse();
    }
    expect($transport->sent)->toHaveCount(RetainedPresentation::MAX_IN_FLIGHT);
    finishRetainedTestUpload($sender, $transport, fn() => $sender->present($text('latest 60'), $sprite(60), retainedTestViewport(60), $world));
    $frames = RetainedFrameState::replay($transport->sent);
    expect($frames)->toHaveCount(2)->and($frames[0]['sprites'][0]['x'])->toBe(0)
        ->and($frames[1]['sprites'][0]['x'])->toBe(60)
        ->and($frames[1]['textLayers'][0]['runs'][0]['text'])->toBe('latest 60')
        ->and($frames[1]['viewport']['worldOrigin']['column'])->toBe(60)
        ->and($frames[1]['worlds']['map']['glyphRows']['map:floor'][255][255]['glyph'])->toBe('.')
        ->and($sender->present($text('latest 60'), $sprite(60), retainedTestViewport(60), $world))->toBeFalse();
});

it('coalesces a surface replacement during an upload and removes the old world at atomic commit', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $sender->present(new ConsolePresentationChanges(200, 50, true, order: []), [], retainedTestViewport(), retainedTestWorld(256, 128));
    $canvas = new PresentationCanvas(320, 180, [new CanvasImage('panel', 'panel.png', new CanvasRectangle(0, 0, 64, 64))]);
    $sender->presentCanvas($canvas);
    finishRetainedTestUpload($sender, $transport, fn() => $sender->presentCanvas($canvas));
    $frames = RetainedFrameState::replay($transport->sent);
    expect($frames)->toHaveCount(2)->and($frames[0])->toHaveKey('worlds')
        ->and($frames[1]['worlds'] ?? [])->toBe([])->and($frames[1]['viewport'] ?? null)->toBeNull()
        ->and($frames[1]['canvas']['images'][0]['asset'])->toBe('panel.png')
        ->and($sender->presentCanvas($canvas))->toBeFalse();
});

it('defers temporary capacity pressure without losing desired changes or advancing generation', function () {
    $transport = new FakeRendererTransport(); $transport->acceptWrites = false;
    $sender = new RetainedPresentation(new RendererClient($transport));
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    expect($sender->present($text, [], null))->toBeFalse()->and($sender->hasPendingUpload())->toBeTrue()->and($transport->sent)->toBe([]);
    $transport->acceptWrites = true;
    expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], null))->toBeTrue()
        ->and($transport->sent[0]->payload['generation'])->toBe(1)->and($sender->hasPendingUpload())->toBeFalse();
});

it('counts partial pipe writes as progress but resets a stalled upload without growing the queue', function () {
    $transport = new FakeRendererTransport(); $time = 0.0;
    $transport->onSend = static function (FakeRendererTransport $peer): void { $peer->pendingWriteBytes = 100; };
    $sender = new RetainedPresentation(new RendererClient($transport), clock: function () use (&$time): float { return $time; });
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    expect($sender->present($text, [], null))->toBeTrue();
    foreach ([70, 30, 0] as $remaining) {
        $time += 1.5; $transport->pendingWriteBytes = $remaining;
        expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], null))->toBeFalse();
    }
    expect($transport->sent)->toHaveCount(1);
    $time += 2;
    expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], null))->toBeTrue()
        ->and(end($transport->sent)->payload['reset'])->toBeTrue();
    $world = retainedTestWorld(256, 128);
    $transport->pendingWriteBytes = RetainedPresentation::MAX_PENDING_WRITE_BYTES;
    foreach (range(1, 5) as $_) {
        $time += 2;
        expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], null, $world))->toBeFalse();
    }
    expect($transport->sent)->toHaveCount(2)->and($sender->hasPendingUpload())->toBeTrue();
});

it('rebases one reset above an ahead receiver and ignores acknowledgements from before that reset', function () {
    $transport = new FakeRendererTransport(); $time = 0.0;
    $sender = new RetainedPresentation(new RendererClient($transport), clock: function () use (&$time): float { return $time; });
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    $sender->present($text, [], null);
    $sender->invalidate(expectedGeneration: 100);
    expect($sender->present($text, [], null))->toBeTrue()->and(end($transport->sent)->payload['generation'])->toBe(101);
    $sender->acknowledge(1, true);
    $time = 2.0;
    expect($sender->present(new ConsolePresentationChanges(20, 4, false), [], null))->toBeTrue()
        ->and(end($transport->sent)->payload['reset'])->toBeTrue();
});

it('restarts partially sent work after an actual send failure without losing newer desired state', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld(256, 128);
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    $sender->present($text, [], null, $world);
    $sender->acknowledge(end($transport->sent)->payload['generation'], false);
    $latest = [new PresentationSprite('latest', 'latest.png', 9, 1, 10, 20)];
    $transport->sendFailure = new \Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException('broken pipe');
    expect(fn() => $sender->present($text, $latest, null, $world))->toThrow(\Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException::class, 'broken pipe');
    $transport->sendFailure = null;
    finishRetainedTestUpload($sender, $transport, fn() => $sender->present($text, $latest, null, $world));
    $frames = RetainedFrameState::replay($transport->sent);
    expect($frames)->toHaveCount(1)->and($frames[0]['sprites'][0]['id'])->toBe('latest')
        ->and($frames[0]['worlds']['map']['glyphRows']['map:floor'][127][255]['glyph'])->toBe('.')
        ->and($sender->present($text, $latest, null, $world))->toBeFalse();
});

it('abandons an old staged transaction on session restart and starts generation one with current desired state', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld(256, 128);
    $text = new ConsolePresentationChanges(20, 4, true, order: []);
    $sender->present($text, [], null, $world);
    expect($sender->hasPendingUpload())->toBeTrue();
    $sender->invalidate(newSession: true);
    $transport->sent = [];
    finishRetainedTestUpload($sender, $transport, fn() => $sender->present($text, [], null, $world));
    expect($transport->sent[0]->payload['generation'])->toBe(1)->and($transport->sent[0]->payload['reset'])->toBeTrue()
        ->and(RetainedFrameState::replay($transport->sent))->toHaveCount(1);
});

it('captures accumulated rows world camera and sprites without sending or advancing delivery', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $world = retainedTestWorld();
    $text = fn(int $row, string $value, bool $reset = false) => new ConsolePresentationChanges(20, 4, $reset, [
        ['id' => 'field-hud', 'layer' => 1000, 'rows' => [['row' => $row, 'runs' => [new PresentationTextRun($row, 0, $value)]]]],
    ], order: ['field-hud']);
    $sprite = new PresentationSprite('hero', 'hero.png', 2, 1, 10, 20);
    $sender->present($text(0, 'Location', true), [$sprite], retainedTestViewport(), $world);
    $transport->acknowledgeLatestFrame($sender);
    $sender->present($text(1, 'Gold'), [$sprite], retainedTestViewport(), $world);
    $transport->acknowledgeLatestFrame($sender);
    $sent = count($transport->sent);
    $frozen = $sender->captureFrame();
    expect(count($transport->sent))->toBe($sent)->and($frozen->world)->toBe($world)
        ->and(array_keys($frozen->textRows['field-hud']))->toBe([0, 1]);
    $sender->present($text(0, 'Changed'), [], null);
    $transport->acknowledgeLatestFrame($sender);
    $overlay = new \Ichiloto\Engine\Rendering\ScreenTransitionTreatment(getScreenTransitionFixture())
        ->compose(\Ichiloto\Engine\Rendering\ScreenTransitionPhase::HOLD, 1, 200, 80);
    $sender->presentFrame($frozen, $overlay);
    $frame = RetainedFrameState::getLatestFrame($transport->sent);
    expect(array_column($frame['textLayers'][0]['runs'], 'text'))->toBe(['Location', 'Gold'])
        ->and($frame['sprites'][0]['id'])->toBe('hero')->and($frame['viewport']['worldId'])->toBe('map')
        ->and($frame['worlds']['map']['glyphRows'])->not->toBeEmpty()
        ->and($frame['canvas']['composites'][0]['operations'][0]['masks'])->toBe([]);
    $transport->acknowledgeLatestFrame($sender);
    expect($sender->presentFrame($frozen, $overlay))->toBeFalse();
    $sender->invalidate();
    expect($sender->presentFrame($frozen, $overlay))->toBeTrue()->and(end($transport->sent)->payload['reset'])->toBeTrue();
});

it('prepares an incoming canvas without exposing it until the covered atomic replacement', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $sender->present(new ConsolePresentationChanges(20, 4, true, order: []), [], retainedTestViewport(), retainedTestWorld());
    $transport->acknowledgeLatestFrame($sender);
    $outgoing = $sender->captureFrame();
    $sent = count($transport->sent);
    $incoming = $sender->prepareCanvas(new PresentationCanvas(200, 80, [new CanvasImage('battle', 'battle.png', new CanvasRectangle(0, 0, 200, 80))]));
    expect(count($transport->sent))->toBe($sent)->and($sender->captureFrame())->toEqual($outgoing);
    $overlay = new \Ichiloto\Engine\Rendering\ScreenTransitionTreatment(getScreenTransitionFixture())
        ->compose(\Ichiloto\Engine\Rendering\ScreenTransitionPhase::HOLD, 1, 200, 80);
    $sender->presentFrame($incoming, $overlay);
    $frame = RetainedFrameState::getLatestFrame($transport->sent);
    expect($frame['worlds'] ?? [])->toBe([])->and($frame['sprites'])->toBe([])->and($frame['textLayers'])->toBe([])
        ->and($frame['canvas']['images'][0]['id'])->toBe('battle')
        ->and($frame['canvas']['composites'][0]['operations'][0]['opacity'])->toBe(1.0);
    $transport->acknowledgeLatestFrame($sender);
    $sender->presentFrame($incoming);
    expect(RetainedFrameState::getLatestFrame($transport->sent)['canvas'])->not->toHaveKey('composites');
    $foreign = new RetainedPresentation(new RendererClient(new FakeRendererTransport()));
    expect(fn() => $foreign->presentFrame($incoming))->toThrow(LogicException::class, 'belongs');
});

it('prepares retained incoming rows without consuming the outgoing desired state', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $text = fn(string $value) => new ConsolePresentationChanges(20, 4, false, [
        ['id' => 'incoming', 'layer' => 1000, 'rows' => [['row' => 1, 'runs' => [new PresentationTextRun(1, 0, $value)]]]],
    ], order: ['incoming']);
    $sender->present($text('Old'), [], null);
    $outgoing = $sender->captureFrame();
    $incoming = $sender->prepareFrame($text('New'), [new PresentationSprite('actor', 'actor.png', 0, 0, 10, 20)], null);
    expect($sender->captureFrame())->toEqual($outgoing)->and($transport->sent)->toHaveCount(1);
    $sender->presentFrame($incoming);
    expect(RetainedFrameState::getLatestFrame($transport->sent)['textLayers'][0]['runs'][0]['text'])->toBe('New')
        ->and($sender->presentFrame($incoming))->toBeFalse();
});

it('holds superseded frames while the renderer owes acknowledgements and sends only the newest state once it catches up', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $sprite = fn(int $x) => [new PresentationSprite('hero', 'hero.png', $x, 0, 10, 20)];
    $sent = range(0, RetainedPresentation::MAX_FRAMES_IN_FLIGHT - 1);
    foreach ($sent as $x) {
        expect($sender->present(new ConsolePresentationChanges(20, 4, $x === 0, order: []), $sprite($x), null))->toBeTrue();
    }
    $text = new ConsolePresentationChanges(20, 4, false);
    foreach (range(count($sent), 40) as $x) { expect($sender->present($text, $sprite($x), null))->toBeFalse(); }
    expect($transport->sent)->toHaveCount(count($sent))->and($sender->hasPendingUpload())->toBeTrue();
    $sender->acknowledge($transport->sent[0]->payload['generation'], true);
    expect($sender->present($text, $sprite(40), null))->toBeTrue();
    $latest = end($transport->sent)->payload;
    expect($latest['operations'])->toHaveCount(1)->and($latest['operations'][0]['value']['x'])->toBe(40)
        ->and(array_map(fn(array $frame) => $frame['sprites'][0]['x'], RetainedFrameState::replay($transport->sent)))
        ->toBe([...$sent, 40]);
    $transport->acknowledgeLatestFrame($sender);
    expect($sender->present($text, $sprite(40), null))->toBeFalse()->and($sender->hasPendingUpload())->toBeFalse();
});

it('sends nothing after catching up when held changes returned to the delivered state', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $text = new ConsolePresentationChanges(20, 4, false);
    $sprite = fn(int $x) => [new PresentationSprite('hero', 'hero.png', $x, 0, 10, 20)];
    $sender->present(new ConsolePresentationChanges(20, 4, true, order: []), $sprite(0), null);
    foreach (range(1, RetainedPresentation::MAX_FRAMES_IN_FLIGHT - 1) as $x) { $sender->present($text, $sprite($x), null); }
    $delivered = RetainedPresentation::MAX_FRAMES_IN_FLIGHT - 1;
    expect($sender->present($text, $sprite(99), null))->toBeFalse()
        ->and($sender->present($text, $sprite($delivered), null))->toBeFalse();
    $transport->acknowledgeLatestFrame($sender);
    expect($sender->present($text, $sprite($delivered), null))->toBeFalse()
        ->and($transport->sent)->toHaveCount(RetainedPresentation::MAX_FRAMES_IN_FLIGHT)
        ->and($sender->hasPendingUpload())->toBeFalse();
});

it('never holds recovery behind unacknowledged frames after a rejection or an acknowledgement timeout', function () {
    $transport = new FakeRendererTransport(); $time = 0.0;
    $sender = new RetainedPresentation(new RendererClient($transport), clock: function () use (&$time): float { return $time; });
    $text = new ConsolePresentationChanges(20, 4, false);
    $sprite = fn(int $x) => [new PresentationSprite('hero', 'hero.png', $x, 0, 10, 20)];
    $sender->present(new ConsolePresentationChanges(20, 4, true, order: []), $sprite(0), null);
    foreach (range(1, RetainedPresentation::MAX_FRAMES_IN_FLIGHT) as $x) { $sender->present($text, $sprite($x), null); }
    $sender->invalidate(expectedGeneration: 1);
    expect($sender->present($text, $sprite(5), null))->toBeTrue()->and(end($transport->sent)->payload['reset'])->toBeTrue();
    expect($sender->present($text, $sprite(6), null))->toBeTrue();
    expect($sender->present($text, $sprite(7), null))->toBeFalse();
    $time = RetainedPresentation::ACK_TIMEOUT_SECONDS;
    expect($sender->present($text, $sprite(8), null))->toBeTrue()
        ->and(end($transport->sent)->payload['reset'])->toBeTrue()
        ->and(RetainedFrameState::getLatestFrame($transport->sent)['sprites'][0]['x'])->toBe(8);
});

it('re-sends only an inserted or removed record, never the unchanged records painted after it', function () {
    $transport = new FakeRendererTransport();
    $sender = new RetainedPresentation(new RendererClient($transport));
    $canvas = fn(bool $bird) => new PresentationCanvas(100, 100, [
        ...($bird ? [new CanvasImage('bird', 'bird.png', new CanvasRectangle(0, 0, 8, 8))] : []),
        ...array_map(fn(int $n) => new CanvasImage("panel-$n", 'panel.png', new CanvasRectangle($n * 8, 8, 8, 8)), range(0, 9)),
    ]);
    $sprites = fn(bool $npc) => [new PresentationSprite('a', 'a.png', 0, 0, 10, 20),
        ...($npc ? [new PresentationSprite('npc', 'npc.png', 1, 3, 10, 20)] : []), new PresentationSprite('c', 'c.png', 2, 5, 10, 20)];
    $text = fn(bool $dialogue) => new ConsolePresentationChanges(20, 4, false,
        order: ['hud', ...($dialogue ? ['dialogue'] : []), 'notice']);
    $layer = fn(string $id) => ['id' => $id, 'layer' => 1000, 'rows' => [['row' => 0, 'runs' => [new PresentationTextRun(0, 0, $id)]]]];
    $sender->present(new ConsolePresentationChanges(20, 4, true, [$layer('hud'), $layer('notice')], order: ['hud', 'notice']),
        $sprites(false), null, canvasOverlay: null);
    $transport->acknowledgeLatestFrame($sender);
    $sender->presentCanvas($canvas(false));
    $transport->acknowledgeLatestFrame($sender);
    $sender->presentCanvas($canvas(true));
    expect(end($transport->sent)->payload['operations'])->toHaveCount(1)
        ->and(end($transport->sent)->payload['operations'][0]['id'])->toBe('bird')
        ->and(array_column(RetainedFrameState::getLatestFrame($transport->sent)['canvas']['images'], 'id'))
        ->toBe(['bird', ...array_map(fn(int $n) => "panel-$n", range(0, 9))]);
    $transport->acknowledgeLatestFrame($sender);
    $sender->presentCanvas($canvas(false));
    expect(end($transport->sent)->payload['operations'])->toBe([['op' => 'remove', 'kind' => 'canvas_image', 'id' => 'bird']]);
    $transport->acknowledgeLatestFrame($sender);
    expect($sender->presentCanvas($canvas(false)))->toBeFalse();

    $field = new RetainedPresentation(new RendererClient($peer = new FakeRendererTransport()));
    $field->present(new ConsolePresentationChanges(20, 4, true, [$layer('hud'), $layer('notice')], order: ['hud', 'notice']), $sprites(false), null);
    $peer->acknowledgeLatestFrame($field);
    $field->present(new ConsolePresentationChanges(20, 4, false, [$layer('dialogue')], order: ['hud', 'dialogue', 'notice']), $sprites(true), null);
    $operations = end($peer->sent)->payload['operations'];
    $frame = RetainedFrameState::getLatestFrame($peer->sent);
    expect(array_map(fn(array $op) => $op['op'] . ':' . ($op['kind'] ?? '') . ':' . $op['id'], $operations))
        ->toEqualCanonicalizing(['put:text:dialogue', 'put:sprite:npc'])
        ->and(array_column($frame['sprites'], 'id'))->toBe(['a', 'npc', 'c'])
        ->and(array_column($frame['textLayers'], 'id'))->toBe(['hud', 'dialogue', 'notice']);
    $peer->acknowledgeLatestFrame($field);
    expect($field->present($text(true), $sprites(true), null))->toBeFalse();
});
