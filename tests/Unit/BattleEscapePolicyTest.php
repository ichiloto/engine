<?php

use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\EscapePolicy;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

it('keeps unauthored battles escapable', function () {
  $party = new Party();
  $character = new Character('Hero', 0, new Stats(currentHp: 100));
  $party->addMember($character);
  $config = new BattleConfig($party, new Troop('Random Encounter'));

  $commands = BattleCommandCatalog::buildCommands(
    $character,
    $party,
    escapePolicy: $config->getEscapePolicy(),
  );

  expect($config->getEscapePolicy())->toBe(EscapePolicy::ALLOWED)
    ->and(array_map(static fn($action): string => $action->name, $commands))
    ->toContain(BattleCommandType::ESCAPE->label());
});

it('removes escape from forbidden battle commands', function () {
  $party = new Party();
  $character = new Character('Hero', 0, new Stats(currentHp: 100));
  $party->addMember($character);
  $config = new BattleConfig(
    $party,
    new Troop('Mandatory Encounter', escapePolicy: EscapePolicy::FORBIDDEN),
    settings: ['escapePolicy' => 'forbidden'],
  );

  $commands = BattleCommandCatalog::buildCommands(
    $character,
    $party,
    escapePolicy: $config->getEscapePolicy(),
  );

  expect(array_map(static fn($action): string => $action->name, $commands))
    ->not->toContain(BattleCommandType::ESCAPE->label());
});

it('fails closed for malformed escape policies', function (mixed $value) {
  expect(fn() => EscapePolicy::resolve($value))->toThrow(InvalidArgumentException::class);
})->with([
  'unknown string' => ['sometimes'],
  'empty string' => [''],
  'integer' => [1],
  'array' => [[]],
  'boolean' => [true],
]);
