<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Events\Interpreter\Commands\PartyParticipationCommand;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandContext;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\EventExecutionScope;

it('authors selection through actor references and refuses excessive capacities and selections', function () {
  $catalog = ScriptCommandCatalog::createEngineCatalog();
  foreach (['set_party_size', 'select_party', 'regroup_party'] as $type) {
    expect($catalog->findDefinition($type)->runsIn(EventExecutionScope::FIELD))->toBeTrue()
      ->and($catalog->findDefinition($type)->runsIn(EventExecutionScope::BATTLE))->toBeFalse();
  }
  $size = $catalog->findDefinition('set_party_size')->fields[0];
  expect($size->findProblems(['size' => 8]))->toBe([])
    ->and($size->findProblems(['size' => 9]))->not->toBe([]);
  $members = $catalog->findDefinition('select_party')->fields[0];
  expect($members->findProblems(['members' => array_fill(0, 9, ['actor' => 'actor'])]))->not->toBe([]);
});

it('uses the same party across story split, capacity changes and repeated regroup', function () {
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $party = new Party();
  $first = new Character('First', 0, new Stats());
  $last = new Character('Last', 0, new Stats());
  $party->addMember($first); $party->addMember($last);
  new ReflectionProperty(GameScene::class, 'party')->setValue($scene, $party);
  $context = new ScriptCommandContext($scene);
  $command = new PartyParticipationCommand();
  $command->execute($context, ['type' => 'set_party_size', 'size' => 8]);
  $command->execute($context, ['type' => 'select_party', 'members' => [['actor' => 'Last']]]);
  expect($scene->party)->toBe($party)->and($party->leader)->toBe($last)->and($party->battlers->count())->toBe(1);
  $command->execute($context, ['type' => 'regroup_party']);
  $command->execute($context, ['type' => 'regroup_party']);
  expect($party->participants->toArray())->toBe([$first, $last])->and($party->battleSize)->toBe(8);
  $battle = test()->createStub(\Ichiloto\Engine\Battle\Events\BattleEventControlInterface::class);
  expect(fn() => $command->execute(new ScriptCommandContext($scene, battle: $battle), ['type' => 'set_party_size', 'size' => 1]))
    ->toThrow(RuntimeException::class, 'only in field scripts');
});
