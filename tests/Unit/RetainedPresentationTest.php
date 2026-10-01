<?php

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\ConsolePresentationChanges;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSprite;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\Rendering\Presentation\PresentationViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\RendererClient;
use Tests\Support\Input\FakeRendererTransport;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Input/FakeRendererTransport.php';
require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

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
    $transport->sent = [];
    $text = new ConsolePresentationChanges(200, 50, false);
    foreach ([[1, 0], [1, 1], [0, 1], [0, 0], [-3, -2]] as [$x, $y]) {
        expect($sender->present($text, [], retainedTestViewport($x, $y), $world))->toBeTrue();
        $payload = end($transport->sent)->payload;
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
    $transport->sent = [];
    $sender->present(new ConsolePresentationChanges(20, 4, false, [$layer('Hello')]), [$sprite], null);
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
    $image = fn(float $opacity) => new CanvasImage('portrait', 'actor.png', new CanvasRectangle(0, 0, 10, 20), opacity: $opacity);
    $sender->presentCanvas(new PresentationCanvas(100, 100, [$image(1)]));
    $entry = end($transport->sent)->payload;
    expect($entry['operations'][0])->toBe(['op' => 'remove', 'kind' => 'world', 'id' => 'map'])
        ->and($entry['viewport'])->toBeNull();
    $transport->sent = [];
    $sender->presentCanvas(new PresentationCanvas(100, 100, [$image(.5)]));
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
    $transport->sent = [];
    $canvas = new PresentationCanvas(200, 80, [new CanvasImage('dialogue-frame', 'frame.png', new CanvasRectangle(0, 40, 200, 40))]);
    $sender->present(new ConsolePresentationChanges(20, 4, false), [$sprite], $viewport, $world, $canvas);
    $operations = end($transport->sent)->payload['operations'];
    expect(array_column($operations, 'kind'))->toBe(['canvas', 'canvas_image'])
        ->and($operations[0]['value']['mode'])->toBe('overlay');
    $transport->sent = [];
    $sender->present(new ConsolePresentationChanges(20, 4, false), [$sprite], $viewport, $world);
    expect(end($transport->sent)->payload['operations'])->toBe([
        ['op' => 'remove', 'kind' => 'canvas', 'id' => 'canvas'],
        ['op' => 'remove', 'kind' => 'canvas_image', 'id' => 'dialogue-frame'],
    ]);
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
