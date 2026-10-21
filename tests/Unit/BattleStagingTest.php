<?php

use Ichiloto\Engine\Battle\Presentation\BattleArenaBindings;
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleFooting;
use Ichiloto\Engine\Battle\Presentation\BattleFormationClearance;
use Ichiloto\Engine\Battle\Presentation\BattleFormationSpacing;
use Ichiloto\Engine\Battle\Presentation\BattleGround;
use Ichiloto\Engine\Battle\Presentation\BattleGroundBand;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleStageSlot;
use Ichiloto\Engine\Battle\Presentation\BattleStaging;
use Ichiloto\Engine\Entities\Enemies\EnemyHabitat;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

function stagingArena(?BattleGround $ground, BattleFooting $footing = new BattleFooting()): BattleArenaDefinition
{
  return new BattleArenaDefinition('Synthetic field', new CanvasImage('field', 'field.png', new CanvasRectangle(0, 0, 1350, 720)),
    ground: $ground, footing: $footing);
}

function stagingLayout(array $partySlots = [], ?BattleGround $ground = null, float $gap = 0): BattleCanvasLayout
{
  return new BattleCanvasLayout(1350, 720, partySlots: $partySlots, battlerArea: new CanvasRectangle(0, 80, 1350, 520),
    enemyArea: new CanvasRectangle(0, 80, 675, 520), partyArea: new CanvasRectangle(675, 80, 675, 520),
    ground: $ground, groundGap: $gap);
}

it('stands the ground\'s near edge a theme gap above the battle UI and its far edge where the arena declares', function () {
  $layout = stagingLayout(ground: new BattleGround(top: 250), gap: 12);
  expect($layout->getHudTop())->toBe(600.0)
    ->and($layout->getGround(null)->toArray())->toBe(['top' => 250.0, 'bottom' => 588.0, 'perspective' => 1.0])
    ->and($layout->getGround(stagingArena(new BattleGround(top: 330)))->toArray())
    ->toBe(['top' => 330.0, 'bottom' => 588.0, 'perspective' => 1.0])
    ->and($layout->getGround(stagingArena(new BattleGround(bottom: 500, perspective: .9)))->toArray())
    ->toBe(['top' => 250.0, 'bottom' => 500.0, 'perspective' => .9])
    // With nothing declared, battlers may stand anywhere in the battler area above the battle UI.
    ->and(stagingLayout()->getGround(stagingArena(null))->toArray())->toBe(['top' => 80.0, 'bottom' => 600.0, 'perspective' => 1.0]);
});

it('refuses a ground that runs up the canvas, off it, or into the battle UI', function (callable $build) {
  expect($build)->toThrow(InvalidArgumentException::class);
})->with([
  'inverted' => [fn() => new BattleGround(top: 500, bottom: 400)],
  'negative' => [fn() => new BattleGround(top: -1)],
  'perspective' => [fn() => new BattleGround(perspective: 0)],
  'gap fills the field' => [fn() => stagingLayout(gap: 600)],
  'arena top below the near edge' => [fn() => stagingLayout(gap: 12)->getGround(stagingArena(new BattleGround(top: 590)))],
  'legacy height' => [fn() => BattleStageSlot::fromArray(['x' => 1, 'y' => 2, 'width' => 3, 'height' => 4])],
]);

it('stands one formation on every arena: the front rank on the near edge, depth kept, perspective by distance', function () {
  $party = [new BattleStageSlot(1000, 200, 100, 180), new BattleStageSlot(1100, 100, 100, 180), new BattleStageSlot(1200, 0, 100, 180, 1.05)];
  $layout = stagingLayout($party, new BattleGround(top: 200), 12);
  $bodies = array_fill(0, 3, ['width' => 100.0, 'height' => 180.0]);
  $road = BattleStaging::stage($layout, stagingArena(new BattleGround(top: 300)), $bodies, [], []);
  $yard = BattleStaging::stage($layout, stagingArena(new BattleGround(top: 150, perspective: .8)), $bodies, [], []);
  expect(array_map(static fn($slot): array => [$slot->x, $slot->y, $slot->displayScale], $road->party))
    ->toBe([[1000.0, 388.0, 1.0], [1100.0, 488.0, 1.0], [1200.0, 588.0, 1.05]])
    ->and($yard->party[2]->y)->toBe(588.0)->and($yard->party[0]->y)->toBe(388.0)
    // A battler 200 back on ground 438 deep stands 200/438 of the way to the far edge's 0.8 scale.
    ->and($yard->party[0]->displayScale)->toEqualWithDelta(1 - .2 * 200 / 438, 1e-9)
    ->and($yard->party[2]->displayScale)->toBe(1.05);
});

it('keeps a deep formation off the sky by compressing its depth as a whole on shallow ground', function () {
  $layout = stagingLayout([new BattleStageSlot(1000, 300, 100, 180), new BattleStageSlot(1200, 0, 100, 180)], gap: 12);
  $staged = BattleStaging::stage($layout, stagingArena(new BattleGround(top: 438)), array_fill(0, 2, ['width' => 100.0, 'height' => 180.0]), [], []);
  expect($staged->party[0]->y)->toBe(438.0)->and($staged->party[1]->y)->toBe(588.0)
    ->and($staged->partyCompression)->toBe(.5);
});

it('lets a smaller party take the formation\'s front-most slots', function () {
  $slots = [new BattleStageSlot(1000, 200, 100, 180), new BattleStageSlot(1100, 100, 100, 180), new BattleStageSlot(1200, 0, 100, 180)];
  $layout = stagingLayout($slots, new BattleGround(top: 250), 12);
  expect(BattleStaging::getPartyPlaces($layout, array_fill(0, 1, ['width' => 1.0, 'height' => 1.0])))->toBe([$slots[2]])
    ->and(BattleStaging::getPartyPlaces($layout, array_fill(0, 2, ['width' => 1.0, 'height' => 1.0])))->toBe([$slots[1], $slots[2]])
    ->and(fn() => BattleStaging::getPartyPlaces($layout, array_fill(0, 4, ['width' => 1.0, 'height' => 1.0])))
    ->toThrow(InvalidArgumentException::class);
});

it('arranges a staggered echelon that steps back by the battlers\' own size, inside the side\'s area', function (int $count) {
  $layout = stagingLayout(ground: new BattleGround(top: 250), gap: 12);
  $bodies = array_map(static fn(int $index): array => ['width' => 120.0 + 20 * $index, 'height' => 140.0 + 10 * $index], range(0, $count - 1));
  foreach ([[false, -1, 0, 675], [true, 1, 675, 1350]] as [$party, $outward, $left, $right]) {
    $side = BattleStaging::arrange($layout, $party, $bodies);
    expect(end($side)->depth)->toBe(0.0);
    foreach ($side as $index => $place) {
      // Inside the side's area, by the art's extent either side of its feet.
      expect($place->x - $bodies[$index]['width'] / 2)->toBeGreaterThanOrEqual($left - .000001)
        ->and($place->x + $bodies[$index]['width'] / 2)->toBeLessThanOrEqual($right + .000001)
        ->and([$place->width, $place->height])->toBe([$bodies[$index]['width'], $bodies[$index]['height']]);
      if ($index === 0) { continue; }
      // Each nearer battler stands in front by a share of its own height, on the other lane:
      // the front rank and every second rank behind it on the inner lane, the rest on the outer.
      $outer = ($count - 1 - $index) % 2 === 1;
      expect($side[$index - 1]->depth - $place->depth)->toEqualWithDelta(.45 * $bodies[$index]['height'], .000001)
        ->and($outward * ($outer ? $place->x - $side[$index - 1]->x : $side[$index - 1]->x - $place->x))->toBeGreaterThan(0.0);
      if ($index >= 2) { expect($place->x)->toEqualWithDelta($side[$index - 2]->x, .000001); }
    }
  }
  $one = BattleStaging::arrange($layout, false, [['width' => 100.0, 'height' => 100.0]]);
  expect([$one[0]->x, $one[0]->depth])->toBe([337.5, 0.0]);
})->with([2, 3, 4, 6]);

it('stands a battler whose art reaches below the battler area far enough back to keep it above', function () {
  $layout = stagingLayout(ground: new BattleGround(top: 250), gap: 12);
  // Feet on the near edge at 588 would put the 30% of its 200 drawn below them at 648, past the area's bottom at 600.
  $places = BattleStaging::arrange($layout, false, [['width' => 100.0, 'height' => 200.0, 'pivotY' => .7]]);
  expect($places[0]->depth)->toEqualWithDelta(48.0, .000001);
});

it('follows the theme\'s spacing', function () {
  $wide = new BattleCanvasLayout(1350, 720, spacing: new BattleFormationSpacing(depth: 1, lateral: 1));
  $places = BattleStaging::arrange($wide, true, array_fill(0, 2, ['width' => 100.0, 'height' => 150.0]));
  // The back rank on the party's outer (right) lane, the front on the inner.
  expect($places[0]->depth - $places[1]->depth)->toBe(150.0)->and($places[0]->x - $places[1]->x)->toBe(100.0);
});

it('keeps authored places and gives unplaced members the spots no placed member stands nearest', function () {
  $layout = stagingLayout(ground: new BattleGround(top: 250), gap: 12);
  $bodies = array_fill(0, 3, ['width' => 100.0, 'height' => 100.0]);
  $full = BattleStaging::arrange($layout, false, $bodies);
  $authored = new BattleStageSlot($full[2]->x + 3, $full[2]->depth + 2, 80, 90, 1.1);
  $arranged = BattleStaging::arrange($layout, false, $bodies, [null, $authored, null]);
  expect($arranged[1])->toBe($authored)
    ->and([$arranged[0]->x, $arranged[0]->depth])->toBe([$full[0]->x, $full[0]->depth])
    ->and([$arranged[2]->x, $arranged[2]->depth])->toBe([$full[1]->x, $full[1]->depth]);
});

it('faces the party with an arranged troop: centred on the theme formation\'s middle, a lone enemy mid-field', function (int $count) {
  $party = [new BattleStageSlot(1000, 200, 100, 180), new BattleStageSlot(1100, 100, 100, 180), new BattleStageSlot(1200, 0, 100, 180)];
  $layout = stagingLayout($party, new BattleGround(top: 200), 12);
  $bodies = array_fill(0, $count, ['width' => 100.0, 'height' => 120.0]);
  $staging = BattleStaging::stage($layout, stagingArena(new BattleGround(top: 300)), array_fill(0, 3, ['width' => 100.0, 'height' => 180.0]),
    array_fill(0, $count, null), $bodies);
  $feet = array_map(static fn($slot): float => $slot->y, $staging->enemies);
  // The party's middle stands 100 back from the near edge at 588: the troop's middle stands there too.
  expect((min($feet) + max($feet)) / 2)->toEqualWithDelta(488.0, .000001)
    ->and(BattleStaging::arrange($layout, false, $bodies) == $staging->enemyPlaces)->toBeTrue();
  if ($count === 1) { expect($feet[0])->toBe($staging->party[1]->y); }
})->with([1, 2, 4]);

it('never stands a centred troop in front of the near edge or with its art below the battler area', function () {
  $party = [new BattleStageSlot(1000, 20, 100, 180), new BattleStageSlot(1200, 0, 100, 180)];
  $layout = stagingLayout($party, new BattleGround(top: 250), 12);
  // A deep troop centred on a shallow party would put its front rank 60 in front of the near edge.
  $deep = BattleStaging::arrange($layout, false, array_fill(0, 3, ['width' => 100.0, 'height' => 200.0]));
  expect(min(array_map(static fn($place): float => $place->depth, $deep)))->toBe(0.0);
  // Feet at the party's middle (578) would put 30% of 200 drawn below them at 638, past the area's bottom at 600.
  $low = BattleStaging::arrange($layout, false, [['width' => 100.0, 'height' => 200.0, 'pivotY' => .7]]);
  expect($low[0]->depth)->toEqualWithDelta(48.0, .000001);
});

it('reads footing polygons by their widest run at a height and finds the nearest height they reach', function () {
  // A U open at the top: two posts joined across the bottom.
  $u = [[0, 0], [100, 0], [100, 100], [0, 100]];
  $posts = [[0, 0], [20, 0], [20, 80], [90, 80], [90, 0], [100, 0], [100, 100], [0, 100]];
  expect(BattleFooting::getSpan($u, 50))->toBe([0.0, 100.0])
    ->and(BattleFooting::getSpan($posts, 40))->toBe([0.0, 20.0])
    ->and(BattleFooting::getSpan($posts, 90))->toBe([0.0, 100.0])
    ->and(BattleFooting::getSpan($u, 150))->toBeNull()
    ->and(BattleFooting::getSpan([], 50))->toBeNull()
    ->and(BattleFooting::getSpan($u, 100))->toBe([0.0, 100.0])
    ->and(BattleFooting::getNearestFootedHeight($u, 150))->toBe(100.0)
    ->and(BattleFooting::getNearestFootedHeight($u, 50))->toBe(50.0)
    ->and(fn() => new BattleFooting([[0, 0], [1, 1]]))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new BattleFooting(water: [[0, 0], [1, 1], [-1, 5]]))->toThrow(InvalidArgumentException::class)
    ->and((new BattleFooting($u))->toArray())->toBe(['land' => [['x' => 0.0, 'y' => 0.0], ['x' => 100.0, 'y' => 0.0],
      ['x' => 100.0, 'y' => 100.0], ['x' => 0.0, 'y' => 100.0]]]);
});

it('stands each side on its arena footing: an arranged troop follows a road, authored and theme places step onto it', function () {
  $party = [new BattleStageSlot(700, 100, 100, 180), new BattleStageSlot(1200, 0, 100, 180)];
  $layout = stagingLayout($party, new BattleGround(top: 300), 12);
  // A road whose left edge runs from (300, 300) down to (550, 588), with a verge on its left.
  $road = [[300, 300], [1350, 300], [1350, 588], [550, 588]];
  // Rocks past 1150 on the party's side.
  $road = [[300, 300], [1150, 300], [1150, 588], [550, 588]];
  $arena = stagingArena(new BattleGround(top: 300), new BattleFooting($road));
  $leftEdge = static fn(float $y): float => 300 + ($y - 300) * 250 / 288;
  foreach ([1, 2, 4] as $count) {
    $staging = BattleStaging::stage($layout, $arena, array_fill(0, 2, ['width' => 100.0, 'height' => 180.0]),
      array_fill(0, $count, null), array_fill(0, $count, ['width' => 100.0, 'height' => 120.0]));
    foreach ($staging->enemies as $slot) {
      expect($slot->x)->toBeGreaterThanOrEqual($leftEdge($slot->y) - .000001)->toBeLessThanOrEqual(675.0);
    }
    expect(array_map(static fn($slot): float => $slot->x, $staging->party))->toBe([700.0, 1150.0]);
    // Footing holds feet, not art: the lanes keep their spacing rather than squeezing into a column.
    $columns = array_map(static fn($slot): float => $slot->x, $staging->enemies);
    if ($count > 1) { expect(max($columns) - min($columns))->toBeGreaterThan(100.0); }
  }
  // An authored place on the verge steps across onto the road at the same height.
  $verge = BattleStaging::stage($layout, $arena, [], [new BattleStageSlot(320, 0, 100, 120)], [['width' => 100.0, 'height' => 120.0]]);
  expect([$verge->enemies[0]->x, $verge->enemies[0]->y])->toBe([550.0, 588.0])
    // Without footing the same place stands where it was authored.
    ->and(BattleStaging::stage($layout, stagingArena(new BattleGround(top: 300)), [], [new BattleStageSlot(320, 0, 100, 120)],
      [['width' => 100.0, 'height' => 120.0]])->enemies[0]->x)->toBe(320.0);
});

it('stands each enemy where its habitat lives: a land beast on a lake\'s shore, a water creature in it, a flier over either', function () {
  $layout = stagingLayout([new BattleStageSlot(1000, 100, 100, 180)], new BattleGround(top: 300), 12);
  // The lake fills the left of the picture; its shore runs down the right, into the enemies' half only near the bottom.
  $water = [[0, 300], [700, 300], [600, 588], [0, 588]];
  $shore = [[700, 300], [1350, 300], [1350, 588], [600, 588]];
  $lake = new BattleFooting($shore, $water);
  $arena = stagingArena(new BattleGround(top: 300), $lake);
  $body = [['width' => 100.0, 'height' => 120.0]];
  $stand = static fn(EnemyHabitat $habitat) => BattleStaging::stage($layout, $arena, [], [null], $body, [$habitat])->enemies[0];
  $inside = static fn(array $polygon, $slot): bool => ($run = BattleFooting::getSpan($polygon, $slot->y)) !== null
    && $slot->x >= $run[0] - .000001 && $slot->x <= $run[1] + .000001;
  expect($inside($water, $stand(EnemyHabitat::WATER)))->toBeTrue()
    ->and($inside($shore, $stand(EnemyHabitat::LAND)))->toBeTrue()
    // A flier keeps to its own side: over the lake rather than the shore across the field.
    ->and($inside($water, $stand(EnemyHabitat::AIR)))->toBeTrue()
    // Footing a habitat has, or would have on an arena marking none, which is land throughout.
    ->and(array_map($lake->hasFootingFor(...), EnemyHabitat::cases()))->toBe([true, true, true])
    ->and(array_map((new BattleFooting($shore))->hasFootingFor(...), EnemyHabitat::cases()))->toBe([true, false, true])
    ->and(array_map((new BattleFooting())->hasFootingFor(...), EnemyHabitat::cases()))->toBe([true, false, true])
    ->and((new BattleFooting())->getFootedRun(EnemyHabitat::LAND, 400))->toBeNull()
    ->and(fn() => BattleStaging::stage($layout, $arena, [], [null], $body, ['water']))->toThrow(InvalidArgumentException::class);
});

it('reads arenas as data with their ground and binds them beside code arenas with one owner each', function () {
  $data = ['default' => 'arena.road', 'arenas' => [
    'arena.road' => ['name' => 'Road', 'background' => ['image' => 'Arenas/Road.png'], 'ground' => ['top' => 330],
      'footing' => ['land' => [['x' => 340, 'y' => 330], ['x' => 675, 'y' => 330], ['x' => 600.0, 'y' => 590]]]],
    'arena.mine' => ['name' => 'Mine', 'background' => ['image' => 'Mine.png', 'source' => ['x' => 240, 'y' => 408, 'width' => 675, 'height' => 360]]],
  ]];
  $bindings = BattleArenaBindings::getFromArray($data);
  $catalog = new BattlePresentationCatalog(['arena.code' => stagingArena(null)], [], [], ui: stagingLayout());
  $bound = $catalog->bindArenas($bindings);
  expect(array_keys($bound->arenas))->toBe(['arena.code', 'arena.road', 'arena.mine'])
    ->and($bound->defaultArena)->toBe('arena.road')
    ->and($bound->arenas['arena.road']->ground)->toEqual(new BattleGround(top: 330))
    ->and($bound->arenas['arena.road']->background->destination->toArray())->toBe(['x' => 0.0, 'y' => 0.0, 'width' => 1350.0, 'height' => 720.0])
    ->and($bound->arenas['arena.mine']->background->sourceRect->toArray())->toBe(['x' => 240, 'y' => 408, 'width' => 675, 'height' => 360])
    ->and($bound->arenas['arena.mine']->ground)->toBeNull()
    ->and($bound->arenas['arena.road']->footing->land)->toBe([[340.0, 330.0], [675.0, 330.0], [600.0, 590.0]])
    ->and($bound->arenas['arena.road']->footing->water)->toBe([])
    ->and($bound->arenas['arena.mine']->footing->toArray())->toBe([])
    ->and(fn() => (new BattlePresentationCatalog(['arena.road' => stagingArena(null)], [], [], ui: stagingLayout()))->bindArenas($bindings))
    ->toThrow(InvalidArgumentException::class, 'arena.road is declared both in')
    ->and(fn() => (new BattlePresentationCatalog(['arena.code' => stagingArena(null)], [], [], ui: stagingLayout(), defaultArena: 'arena.code'))->bindArenas($bindings))
    ->toThrow(InvalidArgumentException::class, 'default battle arena is named both');
});

it('refuses unreadable arena data by its path in the file', function (array $data, string $where) {
  expect(fn() => BattleArenaBindings::getFromArray($data))->toThrow(InvalidArgumentException::class, $where);
})->with([
  'unknown key' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'], 'sky' => 1]]], 'arenas.a: unknown key sky'],
  'no name' => [['arenas' => ['a' => ['background' => ['image' => 'a.png']]]], 'arenas.a.name'],
  'no image' => [['arenas' => ['a' => ['name' => 'A', 'background' => []]]], 'arenas.a.background'],
  'fractional crop' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png', 'source' => ['x' => .5, 'y' => 0, 'width' => 1, 'height' => 1]]]]], 'arenas.a.background.source.x'],
  'ground' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'], 'ground' => ['top' => 'high']]]], 'arenas.a.ground.top'],
  'footing point' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'], 'footing' => ['land' => [['x' => 1, 'y' => 'low']]]]]], 'arenas.a.footing.land[0]'],
  'footing size' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'], 'footing' => ['water' => [['x' => 1, 'y' => 1]]]]]], 'arenas.a.footing: The water footing'],
  'footing habitat' => [['arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png'], 'footing' => ['lava' => []]]]], 'arenas.a.footing: expected land and water'],
  'unknown default' => [['default' => 'b', 'arenas' => ['a' => ['name' => 'A', 'background' => ['image' => 'a.png']]]], 'default'],
]);

it('treats battlers as clear when only their art overlaps and as conflicting when their feet share ground', function () {
  $footing = BattleFormationClearance::getFooting(new CanvasRectangle(100, 100, 100, 200));
  expect($footing->toArray())->toBe(['x' => 120.0, 'y' => 276.0, 'width' => 60.0, 'height' => 24.0]);
});

it('keeps the ground band\'s far edge as the furthest any battler stands', function () {
  $band = new BattleGroundBand(300, 588, .9);
  expect($band->getFootY(1000))->toBe(300.0)->and($band->getFootY(0))->toBe(588.0)
    ->and($band->getDepthScale(300))->toBe(.9)->and($band->getDepthScale(588))->toBe(1.0)
    ->and(fn() => $band->getFootY(-1))->toThrow(InvalidArgumentException::class);
});
