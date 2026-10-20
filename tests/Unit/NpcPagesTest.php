<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Field\MapRouteGraph;
use Ichiloto\Engine\Field\MapSubjectGates;
use Ichiloto\Engine\Field\NpcPages;
use Ichiloto\Engine\Field\Validation\MapNpcInteractionRule;
use Ichiloto\Engine\Events\Triggers\ScriptEventTrigger;
use Ichiloto\Engine\Events\Triggers\ShopEventTrigger;

/** A synthetic NPC entry: the delivering NPC of a story beat with one cued story page and its ordinary line. */
function makeStoryNpc(array $overrides = []): array
{
  return array_replace([
    'id' => 'mentor', 'name' => 'Mentor', 'sprite' => 'M', 'x' => 4, 'y' => 4,
    'dialogue' => [
      ['id' => 'briefing', 'conditions' => [['type' => 'event', 'name' => 'arrived']], 'scriptId' => 'briefing',
        'reusable' => false, 'cue' => ['symbol' => '!', 'color' => 'bright-yellow']],
      ['lines' => [['name' => 'Mentor', 'text' => 'Ready when you are.']]],
    ],
  ], $overrides);
}

it('reads plain lines, a top-level script and a page list as pages, with the shorthand cue on its single page', function () {
  $plain = NpcPages::fromEntry(['dialogue' => [['name' => 'A', 'text' => 'Hi.']], 'cue' => ['symbol' => '?', 'color' => 'cyan']]);
  $script = NpcPages::fromEntry(['script' => [['type' => 'wait', 'seconds' => 1]]]);
  $pages = NpcPages::fromEntry(makeStoryNpc());

  expect($plain->pages)->toHaveCount(1)->and($plain->pages[0]->cue?->symbol)->toBe('?')->and($plain->pages[0]->lines)->toHaveCount(1)
    ->and($script->pages[0]->hasAction)->toBeTrue()
    ->and($pages->pages)->toHaveCount(2)->and($pages->pages[0]->scriptId)->toBe('briefing')->and($pages->hasCue)->toBeTrue()
    ->and(NpcPages::fromEntry([])->pages)->toBe([])->and(NpcPages::fromEntry([])->isTalkable)->toBeFalse();
});

it('refuses pages whose owner or action is ambiguous', function (array $entry, string $message) {
  expect(fn() => NpcPages::fromEntry($entry))->toThrow(InvalidArgumentException::class, $message);
})->with([
  'an NPC cue beside a page list' => [makeStoryNpc(['cue' => ['symbol' => '!', 'color' => 'bright-yellow']]), 'belongs on one of its pages'],
  'two actions on one page' => [makeStoryNpc(['dialogue' => [['script' => [['type' => 'wait']], 'scriptId' => 'x']]]), 'more than one'],
  'a one-shot page without an id' => [makeStoryNpc(['dialogue' => [['scriptId' => 'x', 'reusable' => false]]]), 'needs a stable id'],
  'a one-shot page on an NPC without an id' => [makeStoryNpc(['id' => '']), 'NPC with a page that runs once'],
  'an id that would split a saved completion' => [makeStoryNpc(['dialogue' => [['id' => 'a/b', 'scriptId' => 'x', 'reusable' => false]]]), 'may not contain'],
  'duplicate page ids' => [makeStoryNpc(['dialogue' => [['id' => 'a', 'lines' => [['text' => '1']]], ['id' => 'a', 'lines' => [['text' => '2']]]]]), 'share the id'],
  'an unknown page field' => [makeStoryNpc(['dialogue' => [['lines' => [['text' => 'x']], 'trigger' => 'auto']]]), 'unknown field "trigger"'],
  'an invalid page cue' => [makeStoryNpc(['dialogue' => [['lines' => [['text' => 'x']], 'cue' => ['symbol' => '!!']]]]), 'exactly one terminal cell'],
]);

it('selects the first available page, skipping a retired one-shot page', function () {
  $pages = NpcPages::fromEntry(makeStoryNpc());
  $state = new GameState();

  expect($pages->selectPage('room', $state)?->index)->toBe(1);
  $state->recordStoryEvent('arrived');
  $briefing = $pages->selectPage('room', $state);
  expect($briefing?->id)->toBe('briefing');
  $pages->retire($briefing, 'room', $state);
  expect($state->isEventComplete('room', 'mentor/briefing'))->toBeTrue()
    ->and($pages->selectPage('room', $state)?->index)->toBe(1);
});

it('shows an NPC waymark while the page talking runs shows its cue, and an uncued NPC while it is present', function () {
  $read = MapSubjectGates::readMap(['npcs' => [makeStoryNpc(), ['id' => 'clerk', 'name' => 'Clerk', 'x' => 1, 'y' => 1,
    'dialogue' => [['name' => 'Clerk', 'text' => 'Next.']]]]]);
  $gates = new MapSubjectGates([], ['room' => $read['npcs']]);
  $state = new GameState();

  expect($gates->allowsNpc('room', 'mentor', $state))->toBeFalse()
    ->and($gates->allowsNpc('room', 'clerk', $state))->toBeTrue()
    ->and($gates->allowsNpcPage('room', 'mentor', 1, $state))->toBeTrue();
  $state->recordStoryEvent('arrived');
  expect($gates->allowsNpc('room', 'mentor', $state))->toBeTrue()
    ->and($gates->allowsNpcPage('room', 'mentor', 1, $state))->toBeFalse();
  $state->markEventComplete('room', 'mentor/briefing');
  expect($gates->allowsNpc('room', 'mentor', $state))->toBeFalse();
});

it('reads a reusable NPC page that transfers as a route used by talking to the NPC, never a one-shot page', function () {
  $transfer = [['type' => 'transfer', 'map' => 'mine', 'x' => 1, 'y' => 1]];
  $routes = MapRouteGraph::readMap('annex', ['npcs' => [makeStoryNpc(['conditions' => [['type' => 'event', 'name' => 'here']],
    'dialogue' => [
      ['id' => 'story', 'conditions' => [['type' => 'event', 'name' => 'arrived']], 'script' => [['type' => 'transfer', 'map' => 'garden', 'x' => 0, 'y' => 0]],
        'reusable' => false],
      ['conditions' => [['type' => 'event', 'name' => 'briefed']], 'script' => $transfer],
    ]])]], [], sys_get_temp_dir());

  expect($routes)->toHaveCount(1)
    ->and($routes[0]->destination)->toBe('mine')->and($routes[0]->marker)->toBe('@npc:mentor')
    ->and($routes[0]->npcId)->toBe('mentor')->and($routes[0]->npcPage)->toBe(1)->and($routes[0]->area->cells)->toBe([[4, 4]])
    ->and($routes[0]->conditions)->toBe([['type' => 'event', 'name' => 'here'], ['type' => 'event', 'name' => 'briefed']]);
});

it('flags a player-started event an NPC delivers from a separate cell, not arrivals, shops, objects, places or unvoiced events', function () {
  $event = static fn(string $marker, string $class, array $data, ?array $speakers): array => array_filter([
    'marker' => $marker, 'class' => $class, 'data' => $data, 'area' => ['cells' => [[4, 3]]], 'speakers' => $speakers,
  ], static fn($value): bool => $value !== null);
  $issues = MapNpcInteractionRule::inspectEvents('room', ['npcs' => [makeStoryNpc()]], [
    $event('L', ScriptEventTrigger::class, ['mode' => 'action', 'scriptId' => 'briefing'], ['mentor']),
    $event('A', ScriptEventTrigger::class, ['mode' => 'auto', 'scriptId' => 'arrival'], ['mentor']),
    $event('S', ShopEventTrigger::class, [], ['mentor']),
    $event('N', ScriptEventTrigger::class, ['mode' => 'action', 'scriptId' => 'notice'], null),
    [...$event('T', ScriptEventTrigger::class, ['mode' => 'action', 'scriptId' => 'terminal'], ['mentor']),
      'subject' => ['kind' => 'world_object', 'id' => 'terminal-1']],
    [...$event('P', ScriptEventTrigger::class, ['mode' => 'action', 'scriptId' => 'safe-position'], ['mentor']),
      'subject' => ['kind' => 'area', 'id' => 'safe-position']],
  ]);

  expect($issues)->toHaveCount(1)
    ->and($issues[0]->rule)->toBe(MapNpcInteractionRule::EVENT_RULE)->and($issues[0]->marker)->toBe('L')
    ->and($issues[0]->message)->toContain("Mentor's to deliver")->and($issues[0]->hint)->toContain("Mentor's pages");
});

it('flags a page an earlier reusable page always pre-empts, and a staged speaker never there while it runs', function () {
  $npc = makeStoryNpc(['dialogue' => [
    ['conditions' => [['type' => 'event', 'name' => 'arrived']], 'lines' => [['name' => 'Mentor', 'text' => 'See the desk.']]],
    ['id' => 'briefing', 'conditions' => [['type' => 'event', 'name' => 'arrived'], ['type' => 'event', 'name' => 'gone', 'negate' => true]],
      'scriptId' => 'briefing', 'reusable' => false, 'speakers' => ['aide'], 'cue' => ['symbol' => '!', 'color' => 'bright-yellow']],
    ['lines' => [['name' => 'Mentor', 'text' => 'Hello.']]],
  ]]);
  // Across the room is fine: the scene happens beside the mentor. Gone whenever the briefing can run is not.
  $aide = ['id' => 'aide', 'name' => 'Aide', 'x' => 9, 'y' => 9, 'conditions' => [['type' => 'event', 'name' => 'gone']],
    'dialogue' => [['name' => 'Aide', 'text' => 'Hm.']]];
  $issues = MapNpcInteractionRule::inspectPages('room', ['npcs' => [$npc, $aide]]);
  $rules = array_map(static fn($issue): string => $issue->rule . ': ' . $issue->message, $issues);

  expect($rules)->toHaveCount(2)
    ->and($rules[0])->toContain('event.speaker')->toContain("Mentor's page 2")->toContain("'aide', who is never present")
    ->and($rules[1])->toContain('npc.page')->toContain("Mentor's page 2 never runs: page 1")->toContain('scene and cue');

  unset($aide['conditions']);
  $npc['dialogue'] = array_reverse($npc['dialogue']);
  $npc['dialogue'] = [$npc['dialogue'][1], $npc['dialogue'][2], $npc['dialogue'][0]];
  expect(MapNpcInteractionRule::inspectPages('room', ['npcs' => [$npc, $aide]]))->toBe([]);
});

it('keeps a top-level script replacing dialogue, and names the dialogue that never runs', function () {
  $entry = makeStoryNpc(['script' => [['type' => 'wait', 'seconds' => 1]]]);
  $issues = MapNpcInteractionRule::inspectPages('room', ['npcs' => [$entry]]);

  expect(NpcPages::fromEntry($entry)->pages)->toHaveCount(1)->and(NpcPages::fromEntry($entry)->pages[0]->script)->toHaveCount(1)
    ->and($issues)->toHaveCount(1)->and($issues[0]->message)->toContain('dialogue never runs');
});

it('reports malformed pages through the map validator instead of failing the map', function () {
  $issues = MapNpcInteractionRule::inspectPages('room', ['npcs' => [makeStoryNpc(['cue' => ['symbol' => '!', 'color' => 'bright-yellow']])]]);

  expect($issues)->toHaveCount(1)->and($issues[0]->rule)->toBe('npc.page')->and($issues[0]->path)->toBe(['npcs', 0, 'dialogue']);
});
