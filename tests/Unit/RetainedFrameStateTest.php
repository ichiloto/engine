<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Presentation\RetainedUpload;
use Tests\Support\Rendering\RetainedFrameState;

require_once __DIR__ . '/../Support/Rendering/RetainedFrameState.php';

it('replays compact and ordinary world rows with the same ownership, gaps and replacements', function () {
  $ground = ['ownerLayerId' => 'ground', 'glyph' => '.'];
  $wall = ['ownerLayerId' => 'wall', 'glyph' => '#'];
  $definition = ['op' => 'put', 'kind' => 'world', 'id' => 'map', 'value' => ['id' => 'map']];
  $ordinary = [
    ['op' => 'worldRows', 'id' => 'map', 'rows' => [['row' => 2, 'cells' => [$ground, $ground, $wall]]]],
    ['op' => 'worldTiles', 'id' => 'map', 'layerId' => 'decor', 'rows' => [['row' => 2, 'cells' => [
      ['column' => 1, 'tile' => 5], ['column' => 2, 'tile' => 5], ['column' => 4, 'tile' => 8]]]]],
  ];
  $compact = [
    ['op' => 'worldRowRuns', 'id' => 'map', 'rows' => [['row' => 2, 'runs' => [
      ['count' => 2, 'cell' => $ground], ['count' => 1, 'cell' => $wall]]]]],
    ['op' => 'worldTileRuns', 'id' => 'map', 'layerId' => 'decor', 'rows' => [['row' => 2, 'runs' => [
      ['column' => 1, 'tile' => 5, 'count' => 2], ['column' => 4, 'tile' => 8, 'count' => 1]]]]],
  ];
  $replay = static function (array $operations): RetainedFrameState {
    $state = new RetainedFrameState();
    $upload = new RetainedUpload($operations, 1, true, false, null, [], [], null);
    $state->applyMessage($upload->getMessage(0));
    return $state;
  };
  $plain = $replay([$definition, ...$ordinary]);
  $packed = $replay([$definition, ...$compact]);
  expect($packed->getFrame())->toBe($plain->getFrame());

  // A shorter replacement clears old owners and graphical cells, including gaps.
  $patch = new RetainedUpload([
    ['op' => 'worldRowRuns', 'id' => 'map', 'rows' => [['row' => 2, 'runs' => [['count' => 1, 'cell' => $wall]]]]],
    ['op' => 'worldTileRuns', 'id' => 'map', 'layerId' => 'decor', 'rows' => [['row' => 2, 'runs' => []]]],
  ], 2, false, false, null, [], [], null);
  $packed->applyMessage($patch->getMessage(1));
  $world = $packed->getFrame()['worlds']['map'];
  expect($world['glyphRows'])->toBe(['wall' => [2 => [0 => $wall]]])
    ->and($world['tileRows']['decor'][2])->toBe([]);

  $replacement = new RetainedUpload([$definition], 3, false, false, null, [], [], null);
  $packed->applyMessage($replacement->getMessage(2));
  expect($packed->getFrame()['worlds']['map']['glyphRows'])->toBe([])
    ->and($packed->getFrame()['worlds']['map']['tileRows'])->toBe([]);
});
