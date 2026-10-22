<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Field\MapSubjectGates;
use Ichiloto\Engine\Quests\ActiveQuestWaymark;
use Ichiloto\Engine\Quests\Quest;
use Ichiloto\Engine\Quests\QuestLog;
use Ichiloto\Engine\Quests\QuestWaymark;
use Ichiloto\Engine\Quests\QuestWaymarkSubject;
use Ichiloto\Engine\Quests\Story\StoryContentReader;
use Ichiloto\Engine\Quests\Story\StoryGuidance;
use Ichiloto\Engine\Quests\Story\StoryIndex;
use Ichiloto\Engine\Quests\Story\StoryWayfinder;

/*
 * A synthetic story: the errand quest, then the next quest. Maps are resolved
 * map data (events listing their markers), as the runtime reads them.
 */

/** @param array<string, array<string, mixed>> $maps */
function storyWayfinder(array $maps, array &$reports, array $troops = [], array $scripts = []): StoryWayfinder
{
  $reader = new StoryContentReader(static fn(string $id): mixed => $scripts[$id] ?? null, static fn(): mixed => null, $troops);
  $index = StoryIndex::read($reader, $maps);
  $events = $npcs = [];
  foreach ($maps as $id => $map) {
    ['events' => $events[$id], 'npcs' => $npcs[$id]] = MapSubjectGates::readMap($map);
  }

  return new StoryWayfinder(static fn(): StoryIndex => $index, static fn(): MapSubjectGates => new MapSubjectGates($events, $npcs),
    static function (string $message) use (&$reports): void { $reports[] = $message; });
}

function storyQuest(string $id, array $prerequisites = [], array $objectives = [], bool $optional = false): Quest
{
  return Quest::fromArray(['id' => $id, 'name' => ucfirst($id), 'optional' => $optional, 'prerequisites' => $prerequisites,
    'objectives' => $objectives ?: [['type' => 'flag', 'target' => "{$id}_done", 'waymarks' => [['map' => 'far']]]]]);
}

function storyEvent(string $marker, array $definition): array
{
  return ['marker' => $marker, 'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger', ...$definition];
}

/** The errand is done and the next quest needs a flag the keeper's second page sets. */
function storyBetweenQuests(): array
{
  return ['home' => ['events' => [], 'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1, 'dialogue' => [
    ['id' => 'report', 'reusable' => false, 'conditions' => [['type' => 'quest', 'name' => 'errand']],
      'lines' => [['text' => 'Welcome back.']], 'sets' => [['type' => 'event', 'name' => 'errand_reported']]],
    ['lines' => [['text' => 'Hello.']]],
  ]]]]];
}

/** @return array{QuestLog, list<Quest>} */
function storyAfterErrand(): array
{
  $log = new QuestLog();
  $log->accept('errand', 1);
  $log->markCompleted('errand');

  return [$log, [storyQuest('errand'), storyQuest('next', [['type' => 'event', 'name' => 'errand_reported']])]];
}

function describeStoryWaymarks(array $waymarks): array
{
  return array_map(static fn(ActiveQuestWaymark $entry): string => sprintf('%s %s %s', $entry->waymark->map,
    $entry->waymark->subject?->value ?? 'map', $entry->waymark->subjectId), $waymarks);
}

it('leads to what sets the next quest\'s flag in the gap between two quests', function () {
  $reports = [];
  $wayfinder = storyWayfinder(storyBetweenQuests(), $reports);
  [$log, $quests] = storyAfterErrand();

  $waymarks = $wayfinder->findGapWaymarks([], $quests, $log, new GameState());
  expect(describeStoryWaymarks($waymarks))->toBe(['home npc keeper'])
    ->and($waymarks[0]->fillsGap)->toBeTrue()
    ->and($waymarks[0]->questId)->toBe('next')
    ->and($waymarks[0]->getId())->toStartWith('gap:')
    ->and($waymarks[0]->label)->toBe('Continue the story')
    ->and($reports)->toHaveCount(1)
    ->and($reports[0])->toContain('filled a gap', 'home NPC keeper page 1', 'errand_reported', '"next"');

  // Reported once per gap, however often the field asks.
  $wayfinder->findGapWaymarks([], $quests, $log, new GameState());
  expect($reports)->toHaveCount(1);
});

it('lets authored objectives of a main quest win', function () {
  $reports = [];
  $wayfinder = storyWayfinder(storyBetweenQuests(), $reports);
  [$log, $quests] = storyAfterErrand();
  $authored = new ActiveQuestWaymark('side', 0, 0, new QuestWaymark('home'), 'Side errand', isOptionalQuest: true);
  $main = new ActiveQuestWaymark('next', 0, 0, new QuestWaymark('far'), 'Go far');

  expect($wayfinder->findGapWaymarks([$main], $quests, $log, new GameState()))->toBe([])
    ->and(describeStoryWaymarks($wayfinder->findGapWaymarks([$authored], $quests, $log, new GameState())))->toBe(['home npc keeper'])
    ->and($reports)->toHaveCount(1);
});

it('leads to what starts the next quest once its prerequisites hold', function () {
  $reports = [];
  $maps = ['town' => ['events' => [storyEvent('S', ['sets' => [['type' => 'quest', 'name' => 'next']],
    'data' => ['mode' => 'action', 'reusable' => false, 'scriptId' => 'brief']])]]];
  $wayfinder = storyWayfinder($maps, $reports);
  $log = new QuestLog();
  $log->accept('errand', 1);
  $log->markCompleted('errand');
  $quests = [storyQuest('errand'), storyQuest('next', [['type' => 'quest', 'name' => 'errand']]),
    storyQuest('later', [['type' => 'quest', 'name' => 'next']])];

  expect(describeStoryWaymarks($wayfinder->findGapWaymarks([], $quests, $log, new GameState())))->toBe(['town event S']);
  $state = new GameState();
  $state->markEventComplete('town', 'S');
  // A finished one-shot is stale: nothing is shown, and the gap is reported.
  expect($wayfinder->findGapWaymarks([], $quests, $log, $state))->toBe([])
    ->and(end($reports))->toContain('nothing to lead with', 'nothing usable now starts "next"');
});

it('shows nothing and reports a step it cannot resolve', function () {
  $reports = [];
  $wayfinder = storyWayfinder(storyBetweenQuests(), $reports);
  [$log] = storyAfterErrand();
  $quests = [storyQuest('errand'), storyQuest('next', [['type' => 'variable', 'name' => 'trust', 'op' => '>=', 'value' => 3]])];

  expect($wayfinder->findGapWaymarks([], $quests, $log, new GameState()))->toBe([])
    ->and($reports)->toHaveCount(1)
    ->and($reports[0])->toContain('shows nothing rather than guess', '"next" needs variable "trust"');
});

it('adds nothing once the story is finished, or in a project without main quests', function () {
  $reports = [];
  $wayfinder = storyWayfinder(storyBetweenQuests(), $reports);
  [$log] = storyAfterErrand();

  expect($wayfinder->findGapWaymarks([], [storyQuest('errand')], $log, new GameState()))->toBe([])
    ->and($wayfinder->findGapWaymarks([], [storyQuest('side', optional: true)], new QuestLog(), new GameState()))->toBe([])
    ->and($reports)->toBe([]);
});

it('leads an active main quest\'s objectives that have no usable waymark', function () {
  $reports = [];
  $troops = [['name' => 'Wolf Pack', 'enemies' => [['enemy' => 'Grey Wolf']]]];
  $maps = ['home' => ['events' => [], 'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1,
    'dialogue' => [['lines' => [['text' => 'Noted.']], 'sets' => [['type' => 'event', 'name' => 'errand_reported']]]]]]],
    'road' => ['events' => [], 'encounters' => ['troops' => ['Wolf Pack' => 5]]]];
  $wayfinder = storyWayfinder($maps, $reports, $troops);
  $log = new QuestLog();
  $log->accept('errand', 3);
  $quest = storyQuest('errand', objectives: [
    ['type' => 'flag', 'target' => 'errand_reported', 'description' => 'Report back'],
    ['type' => 'defeat', 'target' => 'Grey Wolf', 'description' => 'Thin the wolves'],
    ['type' => 'collect', 'target' => 'Herb', 'description' => 'Gather a herb'],
  ]);
  $log->setProgress('errand', 0, 0);

  $waymarks = $wayfinder->findGapWaymarks([], [$quest], $log, new GameState());
  expect(describeStoryWaymarks($waymarks))->toBe(['home npc keeper', 'road map '])
    ->and(array_column($waymarks, 'label'))->toBe(['Report back', 'Thin the wolves'])
    ->and(array_column($waymarks, 'objectiveIndex'))->toBe([0, 1]);
});

it('leads through battles: the event that starts one and the map whose encounters bring it', function () {
  $reports = [];
  $troops = [
    ['name' => 'Gatekeeper', 'enemies' => [['enemy' => 'Warden']], 'events' => [
      ['span' => 'battle', 'conditions' => ['enemy' => 0, 'enemyHpPercent' => 25], 'commands' => [
        ['type' => 'record_event', 'name' => 'gate_won']]],
    ]],
    ['name' => 'Ambush', 'enemies' => [['enemy' => 'Bandit']], 'events' => [
      ['commands' => [['type' => 'common_event', 'id' => 'ambush-over']]],
    ]],
  ];
  $maps = [
    'gate' => ['events' => [storyEvent('G', ['data' => ['mode' => 'action', 'reusable' => true, 'scriptId' => 'duel']])]],
    'pass' => ['events' => [], 'encounters' => ['troops' => ['Ambush' => ['weight' => 3]]]],
  ];
  $scripts = ['duel' => [['type' => 'start_battle', 'troop' => 'Gatekeeper', 'resultVariable' => 'duel']],
    'ambush-over' => [['type' => 'set_switch', 'name' => 'pass_cleared', 'value' => true]]];
  $wayfinder = storyWayfinder($maps, $reports, $troops, $scripts);
  $log = new QuestLog();
  $quests = [storyQuest('siege', [['type' => 'event', 'name' => 'gate_won'], ['type' => 'switch', 'name' => 'pass_cleared']])];

  expect(describeStoryWaymarks($wayfinder->findGapWaymarks([], $quests, $log, new GameState())))
    ->toBe(['gate event G', 'pass map ']);
});

it('reads battle-set flags for the Editor\'s guidance check through the same index', function () {
  $troops = [['name' => 'Gatekeeper', 'enemies' => [['enemy' => 'Warden']],
    'events' => [['commands' => [['type' => 'record_event', 'name' => 'gate_won']]]]]];
  $reader = new StoryContentReader(static fn(): mixed => null, static fn(): mixed => null, $troops);
  $index = StoryIndex::read($reader, ['gate' => ['events' => [
    storyEvent('G', ['data' => ['mode' => 'action', 'reusable' => true],
      'script' => [['type' => 'start_battle', 'troop' => 'Gatekeeper']]]),
    ['marker' => 'D', 'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger', 'whenBlocked' => 'Shut.',
      'conditions' => [['type' => 'event', 'name' => 'gate_won']], 'data' => ['destinationMap' => 'keep']],
  ]]]);
  $led = storyQuest('siege', objectives: [['type' => 'flag', 'target' => 'gate_cleared', 'waymarks' => [['map' => 'gate', 'event' => 'G']]]]);

  expect(array_map(static fn($source): string => $source->describe(), $index->findSetters('gate_won')))->toBe(['gate event G'])
    ->and(array_map(static fn($gate): string => "{$gate->map} {$gate->marker}", StoryGuidance::findUnguidedGates([storyQuest('siege')], $index)))
    ->toBe(['gate D'])
    ->and(StoryGuidance::findUnguidedGates([$led], $index))->toBe([]);
});

/* Quest starts: what starts each main quest must be led to before it starts. */

function storyStarter(string $quest, array $page = []): array
{
  return [...$page, 'lines' => [['text' => 'A task for you.']], 'sets' => [['type' => 'quest', 'name' => $quest]]];
}

/** A keeper who starts the errand, and a scout who starts the next quest once the errand is done. */
function storyStartIndex(array $scoutPages = [], array $keeperPages = []): StoryIndex
{
  $reader = new StoryContentReader(static fn(): mixed => null, static fn(): mixed => null);

  return StoryIndex::read($reader, ['home' => ['events' => [], 'npcs' => [
    ['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1, 'dialogue' => $keeperPages ?: [storyStarter('errand')]],
    ['id' => 'scout', 'name' => 'Scout', 'x' => 2, 'y' => 1, 'dialogue' => $scoutPages ?: [storyStarter('next')]],
  ]]]);
}

function storyStartQuest(string $id, array $extra = []): Quest
{
  return Quest::fromArray(['id' => $id, 'name' => ucfirst($id),
    'objectives' => [['type' => 'flag', 'target' => "{$id}_done", 'waymarks' => [['map' => 'far']]]], ...$extra]);
}

function describeUnguidedStarts(array $quests, StoryIndex $index): array
{
  return array_map(static fn(Quest $quest): string => $quest->id, StoryGuidance::findUnguidedStarts($quests, $index));
}

it('finds a main quest whose start nothing leads to, and none once a start waymark or an earlier objective does', function () {
  $index = storyStartIndex();
  $next = ['prerequisites' => [['type' => 'quest', 'name' => 'errand']]];
  $toScout = ['objectives' => [['type' => 'flag', 'target' => 'errand_done', 'waymarks' => [['map' => 'home', 'npc' => 'scout']]]]];

  expect(describeUnguidedStarts([storyStartQuest('errand'), storyStartQuest('next', $next)], $index))->toBe(['errand', 'next'])
    // The opening: the first quest leads to its own giver before it starts.
    ->and(describeUnguidedStarts([storyStartQuest('errand', ['startWaymarks' => [['map' => 'home', 'npc' => 'keeper']]]),
      storyStartQuest('next', $next)], $index))->toBe(['next'])
    // Between quests: the errand's objective leads to the scout, or the next quest leads there itself.
    ->and(describeUnguidedStarts([storyStartQuest('errand', $toScout), storyStartQuest('next', $next)], $index))->toBe(['errand'])
    ->and(describeUnguidedStarts([storyStartQuest('next', [...$next, 'startWaymarks' => [['map' => 'home', 'npc' => 'scout']]])], $index))
    ->toBe([]);
});

it('does not count guidance of a quest start that can only show once the quest has started', function (array $errand) {
  expect(describeUnguidedStarts([storyStartQuest('errand', $errand), storyStartQuest('next')], storyStartIndex()))->toContain('next');
})->with([
  'a waymark waiting for the quest' => [['objectives' => [['type' => 'flag', 'target' => 'errand_done', 'waymarks' => [
    ['map' => 'home', 'npc' => 'scout', 'conditions' => [['type' => 'quest', 'name' => 'next', 'status' => 'active']]]]]]]],
  'a quest needing the quest to start' => [['prerequisites' => [['type' => 'quest', 'name' => 'next', 'status' => 'completed']],
    'objectives' => [['type' => 'flag', 'target' => 'errand_done', 'waymarks' => [['map' => 'home', 'npc' => 'scout']]]]]],
  'an optional quest' => [['optional' => true, 'startWaymarks' => [['map' => 'home', 'npc' => 'scout']]]],
]);

it('does not count the objectives of a quest that starts only with the quest it leads to', function () {
  // Only the scout starts the errand, handing over both quests at once, so neither is active before the other.
  $index = storyStartIndex([['lines' => [['text' => 'Two tasks.']],
    'sets' => [['type' => 'quest', 'name' => 'next'], ['type' => 'quest', 'name' => 'errand']]]], [['lines' => [['text' => 'Hello.']]]]);
  $errand = storyStartQuest('errand', ['objectives' => [['type' => 'flag', 'target' => 'errand_done',
    'waymarks' => [['map' => 'home', 'npc' => 'scout']]]]]);

  expect(describeUnguidedStarts([$errand, storyStartQuest('next')], $index))->toContain('next');
});

it('reports a main quest nothing in the project starts, and leaves side quests alone', function () {
  $index = storyStartIndex();

  expect(describeUnguidedStarts([storyStartQuest('stray')], $index))->toBe(['stray'])
    ->and($index->findStarters('stray'))->toBe([])
    ->and(describeUnguidedStarts([storyStartQuest('stray', ['optional' => true])], $index))->toBe([]);
});

it('counts a start waymark that leads to what sets a gate\'s flag', function () {
  // The keeper starts the errand and opens the gate in one conversation.
  $reader = new StoryContentReader(static fn(): mixed => null, static fn(): mixed => null);
  $index = StoryIndex::read($reader, ['home' => ['events' => [
    ['marker' => 'D', 'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger', 'whenBlocked' => 'Shut.',
      'conditions' => [['type' => 'event', 'name' => 'gate_opened']], 'data' => ['destinationMap' => 'yard']],
  ], 'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1, 'dialogue' => [['lines' => [['text' => 'Go on.']],
    'sets' => [['type' => 'quest', 'name' => 'errand'], ['type' => 'event', 'name' => 'gate_opened']]]]]]]]);

  expect(StoryGuidance::findUnguidedGates([storyStartQuest('errand')], $index))->toHaveCount(1)
    ->and(StoryGuidance::findUnguidedGates([storyStartQuest('errand', ['startWaymarks' => [['map' => 'home', 'npc' => 'keeper']]])],
      $index))->toBe([]);
});

it('leaves the opening to the first quest\'s start waymark, with nothing filled or reported', function () {
  $reports = [];
  $maps = ['home' => ['events' => [], 'npcs' => [['id' => 'keeper', 'name' => 'Keeper', 'x' => 1, 'y' => 1,
    'dialogue' => [storyStarter('errand')]]]]];
  $wayfinder = storyWayfinder($maps, $reports);
  $log = new QuestLog();
  $state = new GameState();
  $quests = ['errand' => storyStartQuest('errand', ['startWaymarks' => [['map' => 'home', 'npc' => 'keeper']]])];

  $shown = \Ichiloto\Engine\Quests\QuestManager::selectStartWaymarks($quests, $log, $state);
  expect(describeStoryWaymarks($shown))->toBe(['home npc keeper'])
    ->and($wayfinder->findGapWaymarks($shown, $quests, $log, $state))->toBe([])
    ->and($reports)->toBe([])
    // Without it, the runtime fills the same step and reports the gap.
    ->and(describeStoryWaymarks($wayfinder->findGapWaymarks([], [storyStartQuest('errand')], $log, $state)))->toBe(['home npc keeper'])
    ->and($reports)->toHaveCount(1);
});
