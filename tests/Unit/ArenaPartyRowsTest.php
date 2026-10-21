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

it('cuts a name too long for its row with an ellipsis, keeping the stats in their columns at the edge', function () {
  $rows = ArenaScene::formatPartyRows([['A very long party member name', ['Lv' => '1', 'HP' => '1/1']], ['Al', ['Lv' => '20', 'HP' => '10/10']]], 30);

  expect(mb_strlen($rows[0]))->toBe(30)->and($rows[0])->toContain("\u{2026}")->toEndWith('Lv  1  HP   1/1 ')
    ->and($rows[1])->toEndWith('Lv 20  HP 10/10 ');
});
