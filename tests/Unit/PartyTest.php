<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;

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
