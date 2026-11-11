<?php

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\PlayStatistic;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Core\WorldConditionExclusion;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Game\GameConfig;

it('persists owned statistics through game configuration and restores transactions without replaying observers', function () {
  $state = new GameState();
  $writes = [];
  $observer = static function ($kind, $name) use (&$writes) { $writes[] = [$kind, $name]; };
  $state->onChange = $observer;
  $state->setVariable('battles_won', 99);
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON);
  $snapshot = $state->toArray();
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON, 2);
  $state->restoreSnapshot($snapshot);
  expect($state->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(1)
    ->and($state->getVariable('battles_won'))->toBe(99)
    ->and($state->onChange)->toBe($observer)->and($writes)->toHaveCount(3);

  $config = new GameConfig('synthetic', new Party(), new Vector2(), new Rect(0, 0, 1, 1),
    MovementHeading::SOUTH, gameState: $state->toArray());
  $copy = unserialize(serialize($config));
  $loaded = GameState::fromArray($copy->gameState);
  expect($loaded->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(1)
    ->and($loaded->onChange)->toBeNull()->and($loaded->toArray())->toBe($state->toArray());
});

it('imports only a monotonic proven minimum and bounds integer arithmetic', function () {
  $state = new GameState();
  $writes = 0;
  $state->onChange = static function () use (&$writes) { $writes++; };
  $state->ensurePlayStatisticMinimum(PlayStatistic::BATTLES_WON, 1);
  $state->ensurePlayStatisticMinimum(PlayStatistic::BATTLES_WON, 1);
  $state->ensurePlayStatisticMinimum(PlayStatistic::BATTLES_WON, 0);
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON);
  expect($state->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(2)->and($writes)->toBe(2);
  $state->ensurePlayStatisticMinimum(PlayStatistic::BATTLES_WON, PHP_INT_MAX);
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON, PHP_INT_MAX);
  expect($state->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(PHP_INT_MAX)->and($writes)->toBe(3);
  expect(fn() => $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON, 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => $state->ensurePlayStatisticMinimum(PlayStatistic::BATTLES_WON, -1))->toThrow(InvalidArgumentException::class);
});

it('loads old saves at zero and ignores malformed or unknown saved counters', function (mixed $value) {
  $state = GameState::fromArray(['playStatistics' => ['battles_won' => $value, 'unknown' => 200]]);
  expect($state->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0)
    ->and(GameState::fromArray([])->getPlayStatistic(PlayStatistic::BATTLES_WON))->toBe(0)
    ->and($state->toArray()['playStatistics'])->toBe([]);
})->with([-1, '10', 1.5, true, null, [[]]]);

it('evaluates owned progress through comparisons negation and alternatives without consulting authored variables', function () {
  $state = new GameState();
  $state->setVariable('battles_won', 999);
  $condition = ['type' => 'play_statistic', 'name' => 'battles_won', 'op' => '>=', 'value' => 1];
  WorldConditionEvaluator::validateAll([$condition]);
  expect(WorldConditionEvaluator::allHold([$condition], $state))->toBeFalse();
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON);
  expect(WorldConditionEvaluator::allHold([$condition], $state))->toBeTrue()
    ->and(WorldConditionEvaluator::allHold([[...$condition, 'negate' => true]], $state))->toBeFalse()
    ->and(WorldConditionEvaluator::allHold([['type' => 'any', 'conditions' => [
      ['type' => 'switch', 'name' => 'absent'], $condition]]], $state))->toBeTrue();
  $equal = [...$condition, 'op' => '=='];
  expect(WorldConditionExclusion::proveExclusive([$equal], [[...$equal, 'op' => '!=']]))->toBeTrue()
    ->and(WorldConditionExclusion::proveExclusive([$equal], [
      ['type' => 'variable', 'name' => 'battles_won', 'op' => '!=', 'value' => 1]]))->toBeFalse();
});

it('refuses malformed statistic conditions before negation can expose content', function (array $changes) {
  $condition = [...['type' => 'play_statistic', 'name' => 'battles_won', 'value' => 1], ...$changes];
  expect(fn() => WorldConditionEvaluator::validateAll([$condition]))->toThrow(InvalidArgumentException::class)
    ->and(WorldConditionEvaluator::allHold([[...$condition, 'negate' => true]], new GameState()))->toBeFalse();
})->with([
  'unknown statistic' => [['name' => 'unknown']], 'missing value' => [['value' => null]],
  'negative value' => [['value' => -1]], 'text value' => [['value' => '1']],
  'float value' => [['value' => 1.5]], 'bad operator' => [['op' => 'contains']],
  'unknown field' => [['quantity' => 1]],
]);

it('keeps summon availability on the shared statistic validation boundary', function () {
  $condition = ['type' => 'play_statistic', 'name' => 'battles_won', 'op' => '>=', 'value' => 1];
  $availability = \Ichiloto\Engine\Cutscenes\Summons\SummonAvailability::fromAuthored(['conditions' => [$condition]]);
  $state = new GameState();
  expect($availability->isValid())->toBeTrue()->and($availability->isSatisfied($state))->toBeFalse();
  $state->incrementPlayStatistic(PlayStatistic::BATTLES_WON);
  expect($availability->isSatisfied($state))->toBeTrue();
  $invalid = \Ichiloto\Engine\Cutscenes\Summons\SummonAvailability::fromAuthored([
    'conditions' => [[...$condition, 'name' => 'unknown', 'negate' => true]]]);
  expect($invalid->isValid())->toBeFalse()->and($invalid->isSatisfied($state))->toBeFalse();
});
