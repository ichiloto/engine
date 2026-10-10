<?php

use Ichiloto\Engine\Scenes\Arena\ArenaScene;

it('justifies the arena party: names left, figures right-aligned in columns ending at the edge', function () {
  $rows = ArenaScene::formatPartyRows([
    ['Kaelion', ['Lv' => '1', 'HP' => '140/140', 'MP' => '8/10']],
    ['Liora', ['Lv' => '12', 'HP' => '90/90', 'MP' => '18/24']],
    ['Drazek', ['Lv' => '1', 'HP' => '110/110', 'MP' => '10/12']],
  ], 56);

  expect($rows)->toBe([
    ' Kaelion                    Lv  1  HP 140/140  MP  8/10 ',
    ' Liora                      Lv 12  HP   90/90  MP 18/24 ',
    ' Drazek                     Lv  1  HP 110/110  MP 10/12 ',
  ])->and(array_map(strlen(...), $rows))->toBe([56, 56, 56]);
});

it('keeps a space between a long name and its stats rather than overrunning them', function () {
  $rows = ArenaScene::formatPartyRows([['A very long party member name', ['Lv' => '1', 'HP' => '1/1']]], 30);

  expect($rows[0])->toContain('name Lv 1  HP 1/1 ');
});