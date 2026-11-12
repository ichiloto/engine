<?php

use Ichiloto\Engine\Core\CellArea;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Events\Triggers\EventCue;
use Ichiloto\Engine\Events\Triggers\EventTriggerFactory;
use Ichiloto\Engine\Events\Triggers\PassageEventTrigger;
use Ichiloto\Engine\Field\MapKnowledge;
use Ichiloto\Engine\Field\MapLandmarkCatalog;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;

function makePassageDefinition(array $changes = []): array
{
  return array_replace(['class' => PassageEventTrigger::class, 'marker' => 'P',
    'area' => ['cells' => [[2, 1], [8, 1]]], 'engagement' => 'facing',
    'data' => ['reusable' => false, 'mode' => 'action'],
    'conditions' => [['type' => 'switch', 'name' => 'can_sense']],
    'cue' => ['symbol' => '!', 'maxDistance' => 2]], $changes);
}

it('keeps a passage closed before and after sensing until its own Action completes', function () {
  $state = new GameState();
  $event = EventTriggerFactory::create(makePassageDefinition(), 'cave');
  $event->bind($state);
  expect($event->isAvailable())->toBeFalse()
    ->and($event->getEntryRefusal(new Vector2(1, 1), new Vector2(2, 1)))->toBe('')
    ->and($event->shouldRenderCue(new Vector2(1, 1)))->toBeFalse();
  $state->setSwitch('can_sense');
  expect($event->isAvailable())->toBeTrue()
    ->and($event->shouldRenderCue(new Vector2(1, 1)))->toBeTrue()
    ->and($event->shouldRenderCue(new Vector2(5, 1)))->toBeFalse()
    ->and($event->getEntryRefusal(new Vector2(1, 1), new Vector2(2, 1)))->toBe('')
    ->and($event->getEntryRefusal(new Vector2(2, 1), new Vector2(1, 1)))->toBeNull();
  $event->complete();
  expect($state->isEventComplete('cave', 'P'))->toBeTrue()
    ->and($event->getEntryRefusal(new Vector2(1, 1), new Vector2(2, 1)))->toBeNull()
    ->and($event->shouldRenderCue(new Vector2(1, 1)))->toBeFalse();
});

it('shares one saved completion between access concealment and discovery with no approach leak', function () {
  $opened = ['type' => 'map_event', 'name' => 'cave', 'marker' => 'P'];
  $definition = makePassageDefinition();
  $map = ['id' => 'cave', 'events' => [$definition], 'landmarks' => [
    ['id' => 'hidden-way', 'kind' => 'passage', 'anchor' => ['event' => 'P'], 'secret' => true,
      'revealConditions' => [$opened]],
  ]];
  $grid = array_fill(0, 3, array_fill(0, 10, ' '));
  $catalog = MapLandmarkCatalog::readMap($map, $grid);
  $knowledge = new MapKnowledge('cave', $catalog);
  $state = new GameState();
  $state->setSwitch('can_sense');
  $knowledge->discoverNear(2, 1, $state);
  expect($knowledge->getVisibleLandmarks($state))->toBe([])
    ->and(WorldConditionEvaluator::allHold([[...$opened, 'negate' => true]], $state))->toBeTrue();
  $event = EventTriggerFactory::create($definition, 'cave');
  $event->bind($state);
  $event->complete();
  $knowledge->discoverNear(1, 1, $state);
  $restored = GameState::fromArray($state->toArray());
  // Later ability changes do not close an already opened passage.
  $restored->setSwitch('can_sense', false);
  expect($knowledge->getVisibleLandmarks($restored))->toHaveCount(1)
    ->and($knowledge->getVisibleLandmarks($restored)[0]['accessible'])->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([[...$opened, 'negate' => true]], $restored))->toBeFalse();
  // An undescribed passage creates no anonymous minimap marker.
  $map['landmarks'] = [];
  expect(MapLandmarkCatalog::readMap($map, $grid)->landmarks)->toBe([]);
});

it('refuses passage definitions that could reopen lose their saved identity or never be reached', function () {
  foreach ([['engagement' => 'inside'], ['data' => ['reusable' => true]],
    ['data' => ['reusable' => false, 'mode' => 'auto']], ['marker' => '']] as $change) {
    expect(fn() => EventTriggerFactory::create(makePassageDefinition($change), 'cave'))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => EventTriggerFactory::create(makePassageDefinition()))->toThrow(InvalidArgumentException::class);
  $event = makePassageDefinition(['engagement' => 'inside']);
  unset($event['area']);
  $issues = MapCompletenessValidator::validateEvents('cave', ['events' => ['P' => $event]], [[' ', 'P']]);
  expect(array_filter($issues, fn($issue) => str_contains($issue->message, 'facing')))->not->toBeEmpty();
});

it('bounds proximity cues per placement without changing legacy visibility or allowing malformed ranges', function () {
  $area = CellArea::fromCells([[2, 1], [8, 1]]);
  $cue = EventCue::fromArray(['symbol' => '!', 'maxDistance' => 2]);
  expect($cue->findVisiblePositions($area, new Vector2(1, 1)))->toEqual([new Vector2(2, 1)])
    ->and($cue->findVisiblePositions($area, new Vector2(9, 1)))->toEqual([new Vector2(8, 1)])
    ->and($cue->findVisiblePositions($area, null))->toBe([])
    ->and((new EventCue())->findVisiblePositions($area, null))->toHaveCount(2)
    ->and((new EventCue(maxDistance: 0))->isInRange(new Vector2(2, 1), new Vector2(2, 1)))->toBeTrue();
  foreach ([null, -1, 65, '2', 2.0, true] as $invalid) {
    expect(fn() => EventCue::fromArray(['symbol' => '!', 'maxDistance' => $invalid]))->toThrow(InvalidArgumentException::class);
  }
});

it('refuses permanently solid underlying passage cells before runtime activation', function () {
  $area = CellArea::fromCells([[1, 0]]);
  foreach ([1, 2, 10] as $blockedType) {
    expect(fn() => PassageEventTrigger::validateFloor($area, [[0, $blockedType]]))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => PassageEventTrigger::validateFloor($area, [[0]]))->toThrow(InvalidArgumentException::class);
  PassageEventTrigger::validateFloor($area, [[0, 0]]);
  expect(true)->toBeTrue();
});
