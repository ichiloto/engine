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
