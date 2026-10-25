<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorld;
use Ichiloto\Engine\Rendering\Presentation\RetainedPresentation;
use Ichiloto\Engine\Rendering\Presentation\RetainedUpload;
use Ichiloto\Engine\Rendering\Presentation\RetainedWorldUpload;

/** @return list<array<string, mixed>> Every packet's payload. */
function readRetainedWorldUploadPackets(RetainedUpload $upload): array
{
    $packets = [];
    do { $packets[] = $upload->getMessage(count($packets))->payload; } while (!$upload->advance());
    return $packets;
}

it('streams a queued world into exactly the packets its operation list makes, in transaction order', function () {
    // Many distinct styled rows: the world spans several transport chunks.
    $rows = array_map(static fn(int $y): string => implode('', array_map(
        static fn(int $x): string => "\e[3" . (($x + $y) % 8) . 'm' . chr(65 + ($x * $y) % 26) . "\e[0m", range(0, 199))), range(0, 59));
    $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 0, false, 'terrain', implode("\n", $rows))]));
    $before = [['op' => 'put', 'kind' => 'canvas', 'id' => 'canvas', 'value' => ['width' => 1]],
        ['op' => 'remove', 'kind' => 'world', 'id' => 'old']];
    $after = [['op' => 'remove', 'kind' => 'text', 'id' => 'gone'], ['op' => 'put', 'kind' => 'text', 'id' => 'hud', 'value' => []]];
    $arguments = [1, true, true, ['x' => 0], [], [], $world];
    $streamed = readRetainedWorldUploadPackets(new RetainedUpload([...$before, new RetainedWorldUpload($world, true, true), ...$after], ...$arguments));
    $listed = readRetainedWorldUploadPackets(new RetainedUpload([...$before, ...$world->getOperations(true, true), ...$after], ...$arguments));
    $operations = array_merge(...array_column($streamed, 'operations'));
    expect($streamed)->toBe($listed)
        ->and(count($streamed))->toBeGreaterThan(1)
        // Only the final packet presents, and carries the viewport.
        ->and(array_column($streamed, 'present'))->toBe([...array_fill(0, count($streamed) - 1, false), true])
        ->and(array_map(static fn(array $packet): bool => isset($packet['viewport']), $streamed))
        ->toBe([...array_fill(0, count($streamed) - 1, false), true])
        // Removals lead, then everything else in its order, the world where it was queued.
        ->and(array_slice(array_column($operations, 'op'), 0, 3))->toBe(['remove', 'remove', 'put'])
        ->and($operations[3])->toBe($world->getOperations(true, true)[0])
        ->and(end($operations))->toBe($after[1]);
    foreach ($streamed as $packet) {
        expect(strlen(json_encode($packet['operations'])))->toBeLessThan(RetainedPresentation::CHUNK_BYTES * 2);
    }
});

it('builds a world operation list only while one upload holds it, sharing nothing with later uploads', function () {
    $world = PresentationWorld::getFromLayers(new MapLayerSet([new MapLayer('terrain', 0, false, 'terrain', "ab\ncd")]));
    $first = $world->getOperations(false);
    $first[1]['rows'][0]['cells'][0]['glyph'] = 'changed';
    expect($world->getOperations(false))->not->toBe($first)
        ->and(iterator_to_array($world->streamOperations(false), false))->toBe($world->getOperations(false))
        ->and($world->operations)->toBe($world->getOperations(false));
});
