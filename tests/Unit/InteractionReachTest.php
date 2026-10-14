<?php

use Ichiloto\Engine\Field\InteractionReach;

it('removes multi-cell-counter reach while retaining direct and one-counter reach in every heading', function (array $direction, int $depth) {
  [$dx, $dy] = $direction;
  [$x, $y] = [11, 17];
  $npc = [$x + $dx * ($depth + 1), $y + $dy * ($depth + 1)];
  $counters = [];
  for ($distance = 1; $distance <= $depth; $distance++) {
    $counters[] = [$x + $dx * $distance, $y + $dy * $distance];
  }
  $probes = [];
  $result = InteractionReach::findTalkCell($x, $y, $dx, $dy,
    static fn(int $cx, int $cy): bool => in_array([$cx, $cy], $counters, true),
    static function (int $cx, int $cy) use ($npc, &$probes): bool {
      $probes[] = [$cx, $cy];
      return [$cx, $cy] === $npc;
    });
  expect($result)->toBe($depth <= 1 ? $npc : null)
    ->and(count($probes))->toBeLessThanOrEqual(2)
    ->and(InteractionReach::MAX_COUNTER_CELLS)->toBe(1);
})->with(['north' => [[0, -1]], 'east' => [[1, 0]], 'south' => [[0, 1]], 'west' => [[-1, 0]]])
  ->with(['direct' => [0], 'one counter' => [1], 'two counters removed' => [2], 'three counters removed' => [3], 'long run removed' => [10]]);

it('stops at ordinary blockers or gaps and requires the NPC directly behind the faced counter', function (array $counters, array $npcs, ?array $expected) {
  expect(InteractionReach::findTalkCell(0, 0, 1, 0,
    static fn(int $x, int $y): bool => in_array([$x, $y], $counters, true),
    static fn(int $x, int $y): bool => in_array([$x, $y], $npcs, true)))->toBe($expected);
})->with([
  'floor or wall in front' => [[], [[2, 0]], null],
  'gap behind counter' => [[[1, 0]], [[3, 0]], null],
  'wall behind counter' => [[[1, 0]], [[4, 0]], null],
  'turn around counter is not faced reach' => [[[1, 0]], [[1, 1]], null],
  'map edge has no NPC' => [[[1, 0]], [], null],
  'faced NPC has priority on a counter' => [[[1, 0]], [[1, 0], [2, 0]], [1, 0]],
  'NPC behind counter has priority over farther NPC' => [[[1, 0]], [[2, 0], [3, 0]], [2, 0]],
]);

it('rejects non-cardinal or non-unit headings without probing cells', function (array $direction) {
  $probes = 0;
  $probe = static function (int $x, int $y) use (&$probes): bool { $probes++; return true; };
  expect(InteractionReach::findTalkCell(0, 0, ...[...$direction, $probe, $probe]))->toBeNull()
    ->and($probes)->toBe(0);
})->with(['stationary' => [[0, 0]], 'diagonal' => [[1, 1]], 'long stride' => [[2, 0]], 'negative stride' => [[0, -2]]]);

it('has a finite reach even when every probed cell is a counter', function () {
  $probes = 0;
  expect(InteractionReach::findTalkCell(0, 0, 1, 0, static fn(int $x, int $y): bool => true,
    static function (int $x, int $y) use (&$probes): bool { $probes++; return false; }))
    ->toBeNull()->and($probes)->toBe(2);
});
