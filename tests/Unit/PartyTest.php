<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;

/**
 * Builds a minimal stats payload for Party::fromArray tests.
 *
 * @return array<string, int> The stats data.
 */
function makePartyTestStats(): array
{
  return [
    'currentHp' => 100,
    'currentMp' => 10,
    'currentAp' => 0,
    'attack' => 10,
    'defence' => 5,
    'magicAttack' => 5,
    'magicDefence' => 5,
  ];
}

it('swaps party members and updates the leader order', function () {
  $party = new Party();
  $party->addMember(new Character('Kaelion', 1, new Stats()));
  $party->addMember(new Character('Liora', 1, new Stats()));
  $party->addMember(new Character('Orwin', 1, new Stats()));

  $party->swapMembers(0, 2);

  $members = $party->members->toArray();
  $battlers = $party->battlers->toArray();

  expect($party->leader?->name)->toBe('Orwin')
    ->and($members[0]->name)->toBe('Orwin')
    ->and($members[1]->name)->toBe('Liora')
    ->and($members[2]->name)->toBe('Kaelion')
    ->and($battlers[0]->name)->toBe('Orwin');
});

it('throws when asked to swap an invalid party slot', function () {
  $party = new Party();
  $party->addMember(new Character('Kaelion', 1, new Stats()));
  $party->addMember(new Character('Liora', 1, new Stats()));

  expect(fn() => $party->swapMembers(0, 3))->toThrow(InvalidArgumentException::class);
});

it('hydrates members from an array without treating the location entry as a member', function () {
  $party = Party::fromArray([
    'location' => ['name' => 'Happyville', 'region' => 'The Meadows'],
    ['name' => 'Kaelion', 'currentExp' => 0, 'stats' => makePartyTestStats()],
    ['name' => 'Liora', 'currentExp' => 0, 'stats' => makePartyTestStats()],
  ]);

  $members = $party->members->toArray();

  expect($members)->toHaveCount(2)
    ->and($members[0]->name)->toBe('Kaelion')
    ->and($members[1]->name)->toBe('Liora')
    ->and($party->location?->name)->toBe('Happyville')
    ->and($party->location?->region)->toBe('The Meadows');
});

it('removes automatic reserve fallback and keeps the wiped frontline selected', function () {
  $party = new Party();
  foreach (['First', 'Second', 'Third'] as $name) {
    $party->addMember(new Character($name, 1, new Stats(currentHp: 0, currentMp: 10)));
  }
  $reserve = new Character('Reserve', 1, new Stats(currentHp: 250, currentMp: 50));
  $party->addMember($reserve);

  $battlers = $party->battlers->toArray();

  expect($battlers)->toHaveCount(3)
    ->and(array_column($battlers, 'name'))->toBe(['First', 'Second', 'Third'])
    ->and($party->isDefeated())->toBeTrue()
    ->and($reserve->isKnockedOut)->toBeFalse();
});
