<?php

use Ichiloto\Engine\Battle\BattlePartyRoster;
use Ichiloto\Engine\Battle\ReservePolicy;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\SystemData;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;

function createVariableParty(int $count = 9): Party
{
  $party = new Party();
  for ($i = 1; $i <= $count; $i++) {
    $party->addMember(new Character('Actor' . $i, 1, new Stats(currentHp: 100, currentMp: 20)));
  }
  return $party;
}

it('uses every configured frontline size without truncating or reordering actors', function (int $size) {
  $party = createVariableParty();
  $original = $party->members->toArray();
  $party->setBattleSize($size);
  $roster = new BattlePartyRoster($party);
  expect($roster->battlers)->toBe(array_slice($original, 0, $size))
    ->and($party->members->toArray())->toBe($original);
  $positions = PartyBattlerPositions::createForSize($size);
  expect(count($positions->idlePositions))->toBeGreaterThanOrEqual($size)
    ->and(count($positions->activePositions))->toBeGreaterThanOrEqual($size);
})->with(range(1, 8));

it('selects a nonleading actor for field and combat without losing permanent ownership', function () {
  $party = createVariableParty();
  $original = $party->members->toArray();
  $party->selectParticipants(['Actor8']);
  expect($party->leader)->toBe($original[7])
    ->and($party->participants->toArray())->toBe([$original[7]])
    ->and((new BattlePartyRoster($party))->battlers)->toBe([$original[7]])
    ->and($party->members->toArray())->toBe($original);
  $original[7]->stats->currentHp = 37;
  $party->regroupParticipants();
  $party->regroupParticipants();
  expect($party->leader)->toBe($original[0])
    ->and($party->members->toArray())->toBe($original)
    ->and($party->members[7]->stats->currentHp)->toBe(37);
});

it('does not promote separated actors into a defeated solo battle', function () {
  $party = createVariableParty();
  $party->selectParticipants(['Actor8']);
  $party->leader->stats->currentHp = 0;
  $roster = new BattlePartyRoster($party, ReservePolicy::REPLACE_AFTER_WIPEOUT);
  expect($party->isDefeated())->toBeTrue()
    ->and($roster->promoteReservesAfterWipeout())->toBeFalse()
    ->and($roster->isDefeated)->toBeTrue();
});

it('promotes present reserves using the current capacity', function () {
  $party = createVariableParty();
  $party->setBattleSize(1);
  $party->selectParticipants(['Actor8', 'Actor6']);
  $party->leader->stats->currentHp = 0;
  $roster = new BattlePartyRoster($party, ReservePolicy::REPLACE_AFTER_WIPEOUT);
  expect($roster->promoteReservesAfterWipeout())->toBeTrue()
    ->and($roster->battlers)->toBe([$party->members[5]]);
});

it('keeps permanent formation order when rearranging a temporary group', function () {
  $party = createVariableParty();
  $original = $party->members->toArray();
  $party->selectParticipants(['Actor8', 'Actor2']);
  $party->swapMembers(0, 1);
  expect($party->leader)->toBe($original[1])
    ->and($party->members->toArray())->toBe($original);
  $party->regroupParticipants();
  expect($party->participants->toArray())->toBe($original);
});

it('rejects malformed selections atomically', function (array $selection) {
  $party = createVariableParty();
  $party->selectParticipants(['Actor8']);
  expect(fn() => $party->selectParticipants($selection))->toThrow(InvalidArgumentException::class)
    ->and($party->getSelectedActorIds())->toBe(['Actor8']);
})->with([ [[]], [['Actor1', 'Actor1']], [['Missing']], [['']], [[1]], [array_map(fn($n) => 'Actor'.$n, range(1, 9))] ]);

it('rejects an invalid capacity without changing the party', function (int $size) {
  $party = createVariableParty();
  expect(fn() => $party->setBattleSize($size))->toThrow(InvalidArgumentException::class)
    ->and($party->battleSize)->toBe(3);
})->with([0, -1, 9]);

it('reads authored sizes strictly and keeps the legacy default', function () {
  $base = ['title' => 'Synthetic', 'currency' => [], 'startingPositions' => ['player' => []]];
  expect(SystemData::fromArray($base)->battle->partySize)->toBe(3)
    ->and(SystemData::fromArray($base + ['battle' => ['partySize' => 8]])->battle->partySize)->toBe(8);
  expect(fn() => SystemData::fromArray($base + ['battle' => ['partySize' => 9]]))->toThrow(InvalidArgumentException::class);
});
