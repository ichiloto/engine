<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\WorldConditionCoverage;
use Ichiloto\Engine\Progression\WorldAbilityCatalog;

it('proves conditional alternatives cover their owner without requiring an unconditional override', function (array $presence, array $pages, bool $covered) {
  expect(WorldConditionCoverage::proveCovered($presence, $pages))->toBe($covered);
})->with([
  'unconditional content' => [[], [[]], true],
  'none' => [[], [], false],
  'single gated page' => [[], [[['type' => 'event', 'name' => 'done', 'negate' => true]]], false],
  'same presence gate' => [[['type' => 'event', 'name' => 'here']], [[['type' => 'event', 'name' => 'here']]], true],
  'event complement' => [[], [[['type' => 'event', 'name' => 'done']], [['type' => 'event', 'name' => 'done', 'negate' => true]]], true],
  'switch false alias' => [[], [[['type' => 'switch', 'name' => 'done']], [['type' => 'switch', 'name' => 'done', 'value' => false]]], true],
  'compound partial' => [[], [[['type' => 'event', 'name' => 'a'], ['type' => 'event', 'name' => 'b']], [['type' => 'event', 'name' => 'a', 'negate' => true]]], false],
  'compound full' => [[], [[['type' => 'event', 'name' => 'a'], ['type' => 'event', 'name' => 'b']], [['type' => 'event', 'name' => 'a', 'negate' => true]], [['type' => 'event', 'name' => 'b', 'negate' => true]]], true],
  'impossible presence' => [[['type' => 'event', 'name' => 'a'], ['type' => 'event', 'name' => 'a', 'negate' => true]], [], true],
]);

it('uses current ability predicates through the existing shared condition proof', function () {
  $catalog = new WorldAbilityCatalog([['id' => 'form', 'name' => 'Form', 'conditions' => [['type' => 'switch', 'name' => 'form']]]]);
  $before = $catalog->getAbilities();
  expect(WorldConditionCoverage::proveCovered([['type' => 'world_ability', 'name' => 'form']],
    [[['type' => 'switch', 'name' => 'form']]], $catalog))->toBeTrue()->and($catalog->getAbilities())->toBe($before);
});


it('matches actual boolean world evaluation across conditional page pairs without mutating source predicates', function () {
  $a = ['type' => 'switch', 'name' => 'a']; $notA = [...$a, 'value' => false];
  $b = ['type' => 'switch', 'name' => 'b']; $notB = [...$b, 'negate' => true];
  $guards = [[], [$a], [$notA], [$b], [$notB], [$a, $b], [$a, $notB], [$notA, $b], [$notA, $notB]];
  foreach ($guards as $presence) {
    foreach ($guards as $first) {
      foreach ($guards as $second) {
        $expected = true;
        foreach ([false, true] as $hasA) {
          foreach ([false, true] as $hasB) {
            $state = new \Ichiloto\Engine\Core\GameState();
            $state->setSwitch('a', $hasA); $state->setSwitch('b', $hasB);
            if (\Ichiloto\Engine\Core\WorldConditionEvaluator::allHold($presence, $state)
              && !\Ichiloto\Engine\Core\WorldConditionEvaluator::allHold($first, $state)
              && !\Ichiloto\Engine\Core\WorldConditionEvaluator::allHold($second, $state)) { $expected = false; }
          }
        }
        expect(WorldConditionCoverage::proveCovered($presence, [$first, $second]))->toBe($expected);
      }
    }
  }
  $negate = false;
  $source = [[['type' => 'switch', 'name' => 'ref', 'negate' => &$negate]]];
  expect(WorldConditionCoverage::proveCovered([], $source))->toBeFalse()->and($negate)->toBeFalse();
});
