<?php

use Ichiloto\Engine\Battle\Formation\BattleFormation;
use Ichiloto\Engine\Battle\Formation\BattlePosition;
use Ichiloto\Engine\Battle\Formation\BattleRow;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

function createFormationMember(string $name = 'Same name'): Character
{
  return new Character($name, 0, new Stats());
}

it('resolves authored rows and lines by instance identity independent of artwork and names', function () {
  $actor = createFormationMember();
  [$front, $back, $other] = [createFormationMember(), createFormationMember(), createFormationMember()];
  $formation = new BattleFormation([$actor], [$front, $back, $other], [], [
    new BattlePosition(BattleRow::FRONT, 2), new BattlePosition(BattleRow::BACK, 2), new BattlePosition(BattleRow::BACK, 4),
  ]);
  expect($formation->getLineTargets($back, [$other, $back, $front, $actor]))->toBe([$front, $back])
    ->and($formation->getRowTargets(BattleRow::BACK, [$front, $back, $other]))->toBe([$back, $other]);
  // Target status belongs to the recipient policy; formation never widens the supplied eligible pool.
  expect($formation->getLineTargets($back, [$back]))->toBe([$back])
    ->and($formation->getLineTargets(createFormationMember(), [$front]))->toBe([]);
});

it('reserves authored places before assigning legacy defaults and rejects conflicting positions atomically', function () {
  $one = createFormationMember(); $two = createFormationMember();
  $formation = new BattleFormation([], [$one, $two], [], [null, new BattlePosition(BattleRow::FRONT, 0)]);
  expect($formation->getPosition($one)->lane)->toBe(1)->and($formation->getPosition($two)->lane)->toBe(0);
  $before = $formation->getPosition($one);
  expect(fn() => $formation->synchronizeSide('troop', [$one, $two], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::FRONT, 0)]))
    ->toThrow(InvalidArgumentException::class)
    ->and($formation->getPosition($one))->toBe($before)
    ->and(fn() => $formation->synchronizeSide('party', [$one]))->toThrow(InvalidArgumentException::class);
});

it('shuffles selected occupied places once and leaves the other side and unselected members untouched', function () {
  [$actor, $front, $back, $other] = [createFormationMember(), createFormationMember(), createFormationMember(), createFormationMember()];
  $formation = new BattleFormation([$actor], [$front, $back, $other], [], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::BACK, 1)]);
  $random = new class implements CombatRandomSource {
    public int $calls = 0;
    public function nextInt(int $minimum, int $maximum): int { ++$this->calls; return $minimum; }
  };
  $actorPlace = $formation->getPosition($actor); $otherPlace = $formation->getPosition($other);
  $formation->shufflePositions([$front, $back], $random);
  expect($formation->getPosition($front)->row)->toBe(BattleRow::BACK)
    ->and($formation->getPosition($back)->row)->toBe(BattleRow::FRONT)
    ->and($formation->getInitialPosition($front)->row)->toBe(BattleRow::FRONT)
    ->and($formation->getPosition($actor))->toBe($actorPlace)->and($formation->getPosition($other))->toBe($otherPlace)
    ->and($random->calls)->toBe(1);
  expect(fn() => $formation->shufflePositions([$front, $actor], $random))->toThrow(InvalidArgumentException::class)
    ->and($random->calls)->toBe(1);
});

it('pushes back-row members into separate front positions without overlapping or moving an existing front member', function () {
  [$front, $back, $second] = [createFormationMember(), createFormationMember(), createFormationMember()];
  $formation = new BattleFormation([], [$front, $back, $second], [], [new BattlePosition(BattleRow::FRONT, 0), new BattlePosition(BattleRow::BACK, 0), new BattlePosition(BattleRow::BACK, 2)]);
  $formation->moveToFront([$front, $back, $second]);
  expect($formation->getPosition($front)->toArray())->toBe(['row' => 'front', 'lane' => 0])
    ->and($formation->getPosition($back)->toArray())->toBe(['row' => 'front', 'lane' => 1])
    ->and($formation->getPosition($second)->toArray())->toBe(['row' => 'front', 'lane' => 2]);
});

it('owns formation per battle, synchronizes replacement reserves and keeps mutations out of party progress', function () {
  $party = new Party(); $party->setBattleSize(8);
  foreach (range(1, 9) as $index) { $party->addMember(createFormationMember((string)$index)); }
  $config = new BattleConfig($party, new Troop('Empty'), settings: ['reservePolicy' => 'replace_after_wipeout',
    'partyFormation' => [['row' => 'back', 'lane' => 0]]]);
  $original = $config->partyRoster->battlers;
  $formation = $config->formation;
  expect($formation->getPosition($original[0])->row)->toBe(BattleRow::BACK);
  $formation->moveToFront([$original[0]]);
  $fresh = new BattleConfig($party, new Troop('Another'), settings: $config->settings);
  expect($fresh->formation->getPosition($original[0])->row)->toBe(BattleRow::BACK)
    ->and(array_keys($config->__serialize()))->not->toContain('formation', 'formationOwner');
  foreach ($original as $member) { $member->stats->currentHp = 0; }
  expect($config->partyRoster->promoteReservesAfterWipeout())->toBeTrue();
  $replacement = $config->partyRoster->battlers[0];
  expect($config->formation)->toBe($formation)
    ->and($formation->getPosition($replacement)->row)->toBe(BattleRow::BACK)
    ->and($formation->getPosition($original[0]))->toBeNull()
    ->and($party->battleSize)->toBe(8)->and($party->members->count())->toBe(9);
});

it('rejects malformed logical positions and defaults omitted troop positions safely', function () {
  foreach ([null, [], ['row' => 'rear', 'lane' => 0], ['row' => 'front', 'lane' => -1], ['row' => 'back', 'lane' => 1.2], ['row' => 'front', 'lane' => 0, 'x' => 9]] as $data) {
    expect(fn() => BattlePosition::fromArray($data))->toThrow(InvalidArgumentException::class);
  }
  $party = new Party(); $party->addMember(createFormationMember());
  expect((new BattleConfig($party, new Troop('Empty')))->formation->getPosition($party->leader)->toArray())
    ->toBe(['row' => 'front', 'lane' => 0]);
});


it('validates configuration without freezing participants until the battle acquires its roster', function () {
  $party = new \Ichiloto\Engine\Entities\Party();
  $original = new \Ichiloto\Engine\Entities\Character('Original', 0, new \Ichiloto\Engine\Entities\Stats());
  $party->addMember($original);
  $config = new \Ichiloto\Engine\Scenes\Battle\BattleConfig($party, new \Ichiloto\Engine\Entities\Troop('Empty', []));
  $replacement = new \Ichiloto\Engine\Entities\Character('Replacement', 0, new \Ichiloto\Engine\Entities\Stats());
  $party->members[0] = $replacement;
  expect($config->partyRoster->battlers)->toBe([$replacement])
    ->and($config->formation->getPosition($replacement))->not->toBeNull()
    ->and($config->formation->getPosition($original))->toBeNull();
});
