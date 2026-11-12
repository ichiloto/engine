<?php

use Ichiloto\Engine\Events\Triggers\DialogueEventTrigger;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Field\Validation\MapEventSpeakerRule;

/** A synthetic room: one desk event at 4,2 and the people who may speak it. */
function makeSpeakerMap(array $npcs, array $event = []): array
{
    return ['npcs' => $npcs, 'events' => ['B' => [
        'class' => DialogueEventTrigger::class, 'data' => ['dialogue' => []], 'speakers' => ['clerk'], ...$event,
    ]]];
}

function inspectSpeakers(array $map): array
{
    $grid = array_fill(0, 6, array_fill(0, 10, ' '));
    $grid[2][4] = 'B';
    return array_values(array_filter(MapCompletenessValidator::validateEvents('test/office', $map, $grid),
        static fn($issue): bool => $issue->rule === MapEventSpeakerRule::RULE));
}

it('accepts a speaker standing on or beside the event in any direction', function (int $x, int $y) {
    expect(inspectSpeakers(makeSpeakerMap([['id' => 'clerk', 'name' => 'Clerk', 'x' => $x, 'y' => $y]])))->toBe([]);
})->with([[4, 2], [5, 2], [3, 1], [5, 3], [4, 1]]);

it('reports a speaker who stands away from the event, naming where they stand', function () {
    $issues = inspectSpeakers(makeSpeakerMap([['id' => 'clerk', 'name' => 'Clerk', 'x' => 8, 'y' => 2]]));
    expect($issues)->toHaveCount(1)
        ->and($issues[0]->marker)->toBe('B')->and($issues[0]->cells)->toBe([[8, 2]])
        ->and($issues[0]->path)->toBe(['events', 'B', 'speakers'])
        ->and($issues[0]->message)->toContain('Clerk')->toContain('8,2');
});

it('reports a speaker missing from the map and one never present while the event can run', function () {
    $missing = inspectSpeakers(makeSpeakerMap([['id' => 'guard', 'name' => 'Guard', 'x' => 4, 'y' => 3]]));
    expect($missing)->toHaveCount(1)->and($missing[0]->message)->toContain("'clerk'")->toContain('not an NPC');
    $absent = inspectSpeakers(makeSpeakerMap(
        [['id' => 'clerk', 'name' => 'Clerk', 'x' => 4, 'y' => 3, 'conditions' => [['type' => 'event', 'name' => 'office_closed', 'negate' => true]]]],
        ['conditions' => [['type' => 'event', 'name' => 'office_closed']]],
    ));
    expect($absent)->toHaveCount(1)->and($absent[0]->message)->toContain('never present');
});

it('checks every page that can show with the event and ignores pages its conditions exclude', function () {
    $pages = [
        ['id' => 'clerk', 'name' => 'Clerk', 'x' => 4, 'y' => 3, 'conditions' => [['type' => 'event', 'name' => 'open']]],
        ['id' => 'clerk', 'name' => 'Clerk', 'x' => 9, 'y' => 5, 'conditions' => [['type' => 'event', 'name' => 'open', 'negate' => true]]],
    ];
    expect(inspectSpeakers(makeSpeakerMap($pages, ['conditions' => [['type' => 'event', 'name' => 'open']]])))->toBe([]);
    // Without the event's own condition, the far page may show while the event runs.
    $issues = inspectSpeakers(makeSpeakerMap($pages));
    expect($issues)->toHaveCount(1)->and($issues[0]->cells)->toBe([[9, 5]]);
});

it('requires a wandering speaker to keep its whole wander area beside the event', function () {
    $near = ['id' => 'clerk', 'name' => 'Clerk', 'x' => 4, 'y' => 3, 'movement' => 'wander', 'wanderArea' => ['x' => 3, 'y' => 3, 'width' => 3, 'height' => 1]];
    expect(inspectSpeakers(makeSpeakerMap([$near])))->toBe([]);
    $far = [...$near, 'wanderArea' => ['x' => 3, 'y' => 3, 'width' => 5, 'height' => 1]];
    expect(inspectSpeakers(makeSpeakerMap([$far]))[0]->cells)->toBe([[6, 3], [7, 3]]);
    $free = [...$near, 'wanderArea' => null];
    expect(inspectSpeakers(makeSpeakerMap([$free]))[0]->message)->toContain('wanders freely');
});

it('follows a subject-bound event wherever its object stands', function () {
    $map = makeSpeakerMap([['id' => 'clerk', 'name' => 'Clerk', 'x' => 7, 'y' => 1]], ['subject' => ['kind' => 'world_object', 'id' => 'desk']]);
    $map['worldObjects'] = [['id' => 'desk', 'anchor' => ['x' => 7, 'y' => 2], 'pivot' => ['x' => .5, 'y' => 1], 'sprites2d' => null]];
    $grid = array_fill(0, 6, array_fill(0, 10, ' '));
    $speakerIssues = static fn(array $map): array => array_values(array_filter(MapCompletenessValidator::validateEvents('test/office', $map, $grid),
        static fn($issue): bool => $issue->rule === MapEventSpeakerRule::RULE));
    expect($speakerIssues($map))->toBe([]);
    $map['worldObjects'][0]['anchor'] = ['x' => 2, 'y' => 4];
    expect($speakerIssues($map))->toHaveCount(1);
});

it('refuses malformed speaker lists without inferring anyone from dialogue', function (mixed $speakers) {
    $issues = inspectSpeakers(makeSpeakerMap([['id' => 'clerk', 'name' => 'Clerk', 'x' => 4, 'y' => 3]], ['speakers' => $speakers]));
    expect($issues)->toHaveCount(1)->and($issues[0]->path)->toBe(['events', 'B', 'speakers']);
})->with([
    'empty' => [[]],
    'not a list' => [['a' => 'clerk']],
    'repeated' => [['clerk', 'clerk']],
    'blank' => [[' ']],
    'padded' => [[' clerk']],
    'not a string' => [[7]],
    'too many' => [array_map(static fn(int $i): string => "npc-{$i}", range(1, 9))],
]);

it('leaves events without speakers to the existing rules', function () {
    $map = makeSpeakerMap([]);
    unset($map['events']['B']['speakers']);
    expect(inspectSpeakers($map))->toBe([]);
});
