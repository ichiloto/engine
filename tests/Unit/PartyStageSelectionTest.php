<?php

use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Inn\InnOffer;

function makeStageSelectionParty(string ...$actorIds): Party
{
  $party = new Party();
  foreach ($actorIds as $id) {
    $party->addMember(new Character('Same display name', 0, new Stats(), actorId: $id));
  }
  return $party;
}

it('selects exact entire membership independent of party order without falling back to a leader shot', function () {
  $selection = new PartyStageSelection(PartyStageSelection::PARTY, parties: [
    ['actors' => ['alpha', 'beta', 'gamma', 'delta', 'reserve'], 'timeline' => 'all-rest'],
    ['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest'],
  ]);
  $party = makeStageSelectionParty('reserve', 'delta', 'gamma', 'beta', 'alpha');
  expect($party->battlers->count())->toBe(3)->and($selection->selectTimeline($party))->toBe('all-rest')
    ->and($selection->selectTimeline(makeStageSelectionParty('beta', 'alpha')))->toBe('pair-rest')
    ->and($selection->selectTimeline(makeStageSelectionParty('alpha', 'beta', 'gamma')))->toBeNull()
    ->and($selection->selectTimeline(makeStageSelectionParty('beta', 'alpha', 'gamma')))->toBeNull();
  $party->swapMembers(0, 4);
  expect($selection->selectTimeline($party))->toBe('all-rest');
});

it('uses stable identity rather than display names and never substitutes another guest for an unbound leader', function () {
  $selection = new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'alpha-rest', 'beta' => 'beta-rest']);
  $party = new Party();
  $party->addMember(new Character('Renamed guest', 0, new Stats(), actorId: 'alpha'));
  expect($selection->selectTimeline($party))->toBe('alpha-rest')
    ->and($selection->selectTimeline(makeStageSelectionParty('unknown', 'alpha')))->toBeNull()
    ->and($selection->selectTimeline(makeStageSelectionParty()))->toBeNull()
    ->and($selection->selectTimeline(makeStageSelectionParty('alpha', 'beta')))->toBe('alpha-rest')
    ->and($selection->selectTimeline(makeStageSelectionParty('beta', 'alpha')))->toBe('beta-rest');
});

it('honors explicitly authored solo compositions and numeric-string stable actor identities', function () {
  $leader = new PartyStageSelection(PartyStageSelection::LEADER, ['123' => 'numeric-rest']);
  $selection = new PartyStageSelection(PartyStageSelection::PARTY, parties: [
    ['actors' => ['alpha'], 'timeline' => 'solo-rest'],
  ]);
  expect($leader->selectTimeline(makeStageSelectionParty('123')))->toBe('numeric-rest')
    ->and($selection->selectTimeline(makeStageSelectionParty('alpha')))->toBe('solo-rest');
});

it('round trips descriptors without retaining caller references or replacing role identities', function () {
  $timeline = 'leader-rest';
  $actor = 'beta';
  $leader = new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => &$timeline]);
  $data = ['treatment' => 'party',
    'parties' => [['actors' => ['alpha', &$actor], 'timeline' => 'pair-rest']]];
  $expected = ['treatment' => 'party', 'leaders' => [],
    'parties' => [['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest']]];
  $selection = PartyStageSelection::fromArray($data);
  $timeline = 'changed-rest';
  $actor = 'changed';
  expect($selection->toArray())->toBe($expected)
    ->and($leader->leaders)->toBe(['alpha' => 'leader-rest'])
    ->and(PartyStageSelection::fromArray($selection->toArray())->toArray())->toBe($expected);
  foreach ([$expected, $selection] as $reference) {
    foreach (['array', 'object'] as $shape) {
      $data = ['confirmDialogue' => ['text' => 'Synthetic rest?'], 'presentation' => $reference];
      $offer = InnOffer::fromData($shape === 'object' ? (object) $data : $data);
      expect($offer->presentation)->toBeInstanceOf(PartyStageSelection::class)
        ->and($offer->presentation->toArray())->toBe($expected);
    }
  }
  expect(PartyStageSelection::fromArray(['treatment' => 'party'])->toArray())
    ->toBe(['treatment' => 'party', 'leaders' => [], 'parties' => []]);
});

it('rejects invalid selection descriptors before they can be authored as inn data', function (array $data) {
  $data = ['treatment' => array_key_exists('parties', $data) ? 'party' : 'leader', ...$data];
  expect(fn() => PartyStageSelection::fromArray($data))->toThrow(InvalidArgumentException::class)
    ->and(fn() => InnOffer::fromData(['confirmDialogue' => ['text' => 'Rest?'], 'presentation' => $data]))
    ->toThrow(InvalidArgumentException::class);
})->with([
  'null treatment' => [['treatment' => null]],
  'unknown treatment' => [['treatment' => 'automatic']],
  'non-string treatment' => [['treatment' => 1]],
  'leader with exact parties' => [['treatment' => 'leader', 'parties' => [['actors' => ['alpha'], 'timeline' => 'rest']]]],
  'party with leader fallback' => [['treatment' => 'party', 'leaders' => ['alpha' => 'rest']]],
  'unknown key' => [['guests' => []]],
  'null leaders' => [['leaders' => null]],
  'null parties' => [['parties' => null]],
  'non-array leaders' => [['leaders' => 'alpha']],
  'blank leader' => [['leaders' => ['' => 'rest']]],
  'padded leader' => [['leaders' => [' alpha ' => 'rest']]],
  'invalid timeline' => [['leaders' => ['alpha' => '../rest']]],
  'non-string timeline' => [['leaders' => ['alpha' => 2]]],
  'keyed compositions' => [['parties' => ['first' => ['actors' => ['alpha'], 'timeline' => 'rest']]]],
  'non-array composition' => [['parties' => ['rest']]],
  'unknown composition key' => [['parties' => [['actors' => ['alpha'], 'timeline' => 'rest', 'other' => 1]]]],
  'empty actors' => [['parties' => [['actors' => [], 'timeline' => 'rest']]]],
  'keyed actors' => [['parties' => [['actors' => ['first' => 'alpha'], 'timeline' => 'rest']]]],
  'invalid actor' => [['parties' => [['actors' => [''], 'timeline' => 'rest']]]],
  'non-string actor' => [['parties' => [['actors' => [123], 'timeline' => 'rest']]]],
  'repeated actor' => [['parties' => [['actors' => ['alpha', 'alpha'], 'timeline' => 'rest']]]],
  'missing timeline' => [['parties' => [['actors' => ['alpha']]]]],
  'repeated composition in another order' => [['parties' => [
    ['actors' => ['alpha', 'beta'], 'timeline' => 'first-rest'],
    ['actors' => ['beta', 'alpha'], 'timeline' => 'second-rest'],
  ]]],
  'too many leaders' => [['leaders' => array_fill_keys(array_map(static fn($n) => 'actor-' . $n, range(0, 1024)), 'rest')]],
  'too many parties' => [['parties' => array_fill(0, 1025, ['actors' => ['alpha'], 'timeline' => 'rest'])]],
  'too many actors' => [['parties' => [['actors' => array_map(static fn($n) => 'actor-' . $n, range(0, 1024)), 'timeline' => 'rest']]]],
]);

it('refuses ambiguous live party identities instead of showing a guessed composition', function () {
  $party = makeStageSelectionParty('alpha', 'alpha');
  expect(fn() => (new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'rest']))->selectTimeline($party))
    ->toThrow(InvalidArgumentException::class, 'duplicate guest identities');
});

it('requires an explicit treatment instead of silently narrowing a party to its leader', function () {
  expect(fn() => PartyStageSelection::fromArray(['leaders' => ['alpha' => 'rest']]))
    ->toThrow(InvalidArgumentException::class)
    ->and(fn() => PartyStageSelection::fromArray([]))->toThrow(InvalidArgumentException::class);
});

it('enumerates every reachable stage identity for validators without consulting a live party', function () {
  $leader = new PartyStageSelection(PartyStageSelection::LEADER, ['alpha' => 'shared-rest', 'beta' => 'beta-rest', 'gamma' => 'shared-rest']);
  $party = new PartyStageSelection(PartyStageSelection::PARTY, parties: [
    ['actors' => ['alpha'], 'timeline' => 'solo-rest'],
    ['actors' => ['beta'], 'timeline' => 'solo-rest'],
    ['actors' => ['alpha', 'beta'], 'timeline' => 'pair-rest'],
  ]);
  expect($leader->getTimelineIds())->toBe(['shared-rest', 'beta-rest'])
    ->and($party->getTimelineIds())->toBe(['solo-rest', 'pair-rest'])
    ->and((new PartyStageSelection(PartyStageSelection::PARTY))->getTimelineIds())->toBeEmpty();
});
