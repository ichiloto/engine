<?php

use Ichiloto\Engine\Entities\Actors\ActorDefinition;
use Ichiloto\Engine\Exceptions\UnresolvedSaveReferenceException;
use Ichiloto\Engine\Util\Stores\ActorStore;

function foundationActorDefinition(): ActorDefinition
{
  $store = new ActorStore(dirname(__DIR__) . '/Fixtures/Actors');

  return $store->require('actor.hero', 'loading the project-backed actor test fixture');
}

it('reconstructs fixed actor naturals from project data and defaults old saves to the project variant', function () {
  $definition = foundationActorDefinition();
  $restored = $definition->createCharacter([
    'name' => 'Hero',
    'currentExp' => 12,
    'stats' => [
      'currentHp' => 37,
      'currentMp' => 4,
      'currentAp' => 2,
      'totalHp' => 100,
      'totalMp' => 10,
      'totalAp' => 3,
      'attack' => 8,
      'defence' => 7,
      'magicAttack' => 6,
      'magicDefence' => 5,
      'speed' => 4,
      'grace' => 3,
      'evasion' => 2,
    ],
    'role' => 'Hero',
    'equipment' => [],
    'summons' => [],
    'abilities' => [],
    'magic' => [],
    'states' => [],
    'permanentGrowth' => [],
  ], '/tmp/old-v4.iedata');

  expect($restored->naturalVariantId)->toBe('standard')
    ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5])
    ->and($restored->stats->currentHp)->toBe(37)
    ->and($restored->stats->currentMp)->toBe(4)
    ->and($restored->toArray())->not->toHaveKey('actorNaturalAdjustments')
    ->and($restored->toArray()['naturalVariantId'])->toBe('standard');
});

it('round trips a runtime-selected natural variant by identity without saving its scalars', function () {
  $definition = foundationActorDefinition();
  $selected = $definition->createCharacter(['naturalVariantId' => 'alternate'], '/tmp/current-v4.iedata');
  $saved = $selected->toArray();
  $restored = $definition->createCharacter($saved, '/tmp/current-v4.iedata');

  expect($saved['naturalVariantId'])->toBe('alternate')
    ->and($saved)->not->toHaveKey('actorNaturalAdjustments')
    ->and($restored->naturalVariantId)->toBe('alternate')
    ->and($restored->actorNaturalAdjustments)->toBe(['maxHp' => 5, 'attack' => 3]);
});

it('fails actor variant compatibility with actor variant and save context', function () {
  expect(fn() => foundationActorDefinition()->createCharacter(
    ['naturalVariantId' => 'removed-variant'],
    '/saves/file-03.iedata',
  ))->toThrow(UnresolvedSaveReferenceException::class, 'Actor "actor.hero" references unknown natural variant "removed-variant" while loading save /saves/file-03.iedata');
});

it('fails missing actor definitions with actor variant and save context', function () {
  $store = (new ReflectionClass(ActorStore::class))->newInstanceWithoutConstructor();
  $context = 'loading actor "actor.removed" with natural variant "variant.saved" from save /saves/file-04.iedata';

  expect(fn() => $store->require('actor.removed', $context))->toThrow(
    UnresolvedSaveReferenceException::class,
    'Actor definition "actor.removed" cannot be resolved while ' . $context,
  );
});
