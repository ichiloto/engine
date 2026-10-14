<?php

use Ichiloto\Engine\Battle\BattlePartyRoster;
use Ichiloto\Engine\Battle\ReservePolicy;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleConfig;
use Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\TurnBasedBattleConfig;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattleDefeatState;
use Ichiloto\Engine\Scenes\Battle\States\BattleEndState;
use Ichiloto\Engine\Scenes\Battle\States\BattleSceneState;
use Ichiloto\Engine\Scenes\SceneManager;
use Ichiloto\Engine\Scenes\SceneStateContext;

function createReservePolicyParty(int $count = 7): Party
{
  $party = new Party();
  for ($index = 0; $index < $count; $index++) {
    $party->addMember(new Character('Member' . $index, 1, new Stats(currentHp: 100, totalHp: 100)));
  }
  return $party;
}

final class ReservePolicyScreen extends BattleScreen
{
  public function __construct(BattleScene $scene, private bool $graphical)
  {
    $this->battleScene = $scene;
    $this->characterNameWindow = new class extends BattleCharacterNameWindow {
      public array $shown = [];
      public function __construct() {}
      public function setNames(array $names): void { $this->shown = $names; }
    };
    $this->characterStatusWindow = new class extends BattleCharacterStatusWindow {
      public array $shown = [];
      public function __construct() {}
      public function setCharacters(array $characters): void { $this->shown = $characters; }
      public function setAtbPercentages(array $percentages): void {}
    };
    $this->fieldWindow = new class extends BattleFieldWindow {
      public function __construct() {}
      public function clearTargetIndicators(): void {}
    };
  }
  public function refreshField(): void {}
  public function usesGraphicalField(): bool { return $this->graphical; }
}

function createReservePolicyBattle(bool $atb, bool $graphical, array $settings = []): array
{
  $party = createReservePolicyParty();
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  new ReflectionProperty($enemy, 'name')->setValue($enemy, 'Synthetic enemy');
  new ReflectionProperty($enemy, 'stats')->setValue($enemy, new Stats(currentHp: 100));
  $config = new BattleConfig($party, new Troop('Synthetic encounter', [$enemy]), settings: $settings);
  $game = new class extends Game {
    public function __construct() {
      $this->sceneManager = new class extends SceneManager {
        public function __construct() {}
      };
    }
    public function __destruct() {}
  };
  $scene = new class($config) extends BattleScene {
    public function __construct(BattleConfig $config) {
      $this->config = $config;
      $context = new SceneStateContext($this);
      $this->defeatState = new BattleDefeatState($context);
      $this->endState = new BattleEndState($context);
    }
    public function setState(BattleSceneState $state): void { $this->state = $state; }
  };
  $game->sceneManager->currentScene = $scene;
  $screen = new ReservePolicyScreen($scene, $graphical);
  if ($atb) {
    $engine = new class($game) extends ActiveTimeBattleEngine {
      public function setState(TurnState $state): void { $this->state = $state; }
      public function getTurnContext(): TurnStateExecutionContext { return $this->turnStateExecutionContext; }
      public function setReadyGauge(object $member): void { $this->gaugeValues[spl_object_id($member)] = 100; $this->enqueueReadyBattler($member); }
    };
    $engine->configure(new ActiveTimeBattleConfig($party, $config->troop, $screen,
      settings: ['firstStrike' => 'normal', ...$settings], partyRoster: $config->partyRoster));
  } else {
    $engine = new class($game) extends TraditionalTurnBasedBattleEngine {
      public function setState(TurnState $state): void { $this->state = $state; }
      public function getTurnContext(): TurnStateExecutionContext { return $this->turnStateExecutionContext; }
    };
    $engine->configure(new TurnBasedBattleConfig($party, $config->troop, $screen,
      settings: $settings, partyRoster: $config->partyRoster));
  }
  $engine->start();
  return [$config, $engine, $engine->getTurnContext(), $scene, $screen, $enemy];
}

it('defaults to no replacement and rejects malformed reserve policies at configuration boundaries', function () {
  $party = createReservePolicyParty();
  expect(new BattleConfig($party, new Troop('Test'))->getReservePolicy())->toBe(ReservePolicy::NONE);
  foreach (['', 'automatic', true, false, [], 1] as $value) {
    expect(fn() => new BattleConfig($party, new Troop('Test'), settings: ['reservePolicy' => $value]))
      ->toThrow(InvalidArgumentException::class, 'reservePolicy');
  }
  expect(ReservePolicy::resolve(ReservePolicy::REPLACE_AFTER_WIPEOUT))->toBe(ReservePolicy::REPLACE_AFTER_WIPEOUT);
});

it('keeps each wave stable through partial KO revival and redraws without altering party order', function () {
  $party = createReservePolicyParty();
  $members = $party->members->toArray();
  $roster = new BattlePartyRoster($party, ReservePolicy::REPLACE_AFTER_WIPEOUT);
  $members[0]->stats->currentHp = 0;
  expect($roster->promoteReservesAfterWipeout())->toBeFalse()->and($roster->battlers)->toBe(array_slice($members, 0, 3));
  $members[0]->stats->currentHp = 100;
  foreach ($roster->battlers as $member) { $member->stats->currentHp = 0; }
  expect($roster->battlers)->toBe(array_slice($members, 0, 3))
    ->and($roster->promoteReservesAfterWipeout())->toBeTrue()
    ->and($roster->battlers)->toBe(array_slice($members, 3, 3));
  $members[0]->stats->currentHp = 100;
  expect($roster->battlers)->toBe(array_slice($members, 3, 3))
    ->and($roster->promoteReservesAfterWipeout())->toBeFalse()
    ->and($party->members->toArray())->toBe($members);
});

it('shares one roster across engine outcome targeting and UI and defeats a wiped active party by default',
  function (bool $atb, bool $graphical) {
    [$config, $engine, $context, $scene, $screen] = createReservePolicyBattle($atb, $graphical);
    $frontline = $config->partyRoster->battlers;
    foreach ($frontline as $member) { $member->stats->currentHp = 0; }
    expect($context->partyRoster)->toBe($config->partyRoster)
      ->and($engine->battleConfig->partyRoster)->toBe($config->partyRoster)
      ->and($screen->partyBattlers)->toBe($frontline)->and($context->getLivingPartyBattlers())->toBeEmpty();
    $engine->turnResolutionState->update($context);
    expect($scene->state)->toBe($scene->defeatState)->and($scene->result?->outcome())->toBe('defeat')
      ->and($scene->continuesAfterDefeat())->toBeFalse()
      ->and($context->partyRoster->battlers)->toBe($frontline)
      ->and($config->party->members[3]->isKnockedOut)->toBeFalse();
  })->with([false, true])->with([false, true]);

it('replaces the active wave only for the opted-in battle and defeats it when no reserves remain',
  function (bool $atb, bool $graphical) {
    [$config, $engine, $context, $scene, $screen, $enemy] = createReservePolicyBattle($atb, $graphical,
      ['reservePolicy' => 'replace_after_wipeout']);
    $members = $config->party->members->toArray();
    if ($atb) {
      $engine->setReadyGauge($members[0]);
      $engine->setReadyGauge($enemy);
    }
    foreach ([array_slice($members, 3, 3), [$members[6]]] as $nextWave) {
      foreach ($config->partyRoster->battlers as $member) { $member->stats->currentHp = 0; }
      $engine->turnResolutionState->update($context);
      expect($scene->result)->toBeNull()->and($engine->state)->toBe($engine->turnInitState)
        ->and($context->getLivingPartyBattlers())->toBe($nextWave)
        ->and($screen->partyBattlers)->toBe($nextWave)
        ->and($screen->characterStatusWindow->shown)->toBe($nextWave)
        ->and($screen->characterNameWindow->shown)->toBe(array_column($nextWave, 'name'))
        ->and($context->getLivingOpponents($enemy))->toBe($nextWave);
      if ($atb) {
        expect($engine->getGaugePercentage($nextWave[0]))->toBe(0.0)
          ->and($engine->getGaugePercentage($enemy))->toBe(1.0);
      }
    }
    if ($atb) { expect($engine->claimNextReadyBattler())->toBe($enemy); }
    $members[6]->stats->currentHp = 0;
    $engine->turnResolutionState->update($context);
    expect($scene->result?->outcome())->toBe('defeat')->and($scene->state)->toBe($scene->defeatState)
      ->and($config->partyRoster->battlers)->toBe([$members[6]])
      ->and($config->party->members->toArray())->toBe($members);
  })->with([false, true])->with([false, true]);

it('does not serialize runtime waves or leak reserve permission into later battles', function () {
  $party = createReservePolicyParty();
  $first = new BattleConfig($party, new Troop('First'), settings: ['reservePolicy' => 'replace_after_wipeout']);
  foreach ($first->partyRoster->battlers as $member) { $member->stats->currentHp = 0; }
  $first->partyRoster->promoteReservesAfterWipeout();
  $saved = unserialize(serialize($first));
  expect($saved->getReservePolicy())->toBe(ReservePolicy::REPLACE_AFTER_WIPEOUT)
    ->and(array_column($saved->partyRoster->battlers, 'name'))->toBe(['Member0', 'Member1', 'Member2']);
  $second = new BattleConfig($party, new Troop('Second'));
  expect($second->getReservePolicy())->toBe(ReservePolicy::NONE)
    ->and($second->partyRoster->isDefeated)->toBeTrue()
    ->and($second->partyRoster->promoteReservesAfterWipeout())->toBeFalse();
  expect(fn() => new BattleConfig($party, new Troop('Wrong policy'), partyRoster: $first->partyRoster))
    ->toThrow(InvalidArgumentException::class);
  expect(fn() => new BattleConfig(createReservePolicyParty(), new Troop('Wrong party'),
    settings: $first->settings, partyRoster: $first->partyRoster))->toThrow(InvalidArgumentException::class);
});

it('keeps explicit continue-after-defeat separate from reserve replacement and entry membership', function () {
  [$config, $engine, $context, $scene] = createReservePolicyBattle(false, false, ['event_defeat_policy' => 'continue']);
  foreach ($config->partyRoster->battlers as $member) { $member->stats->currentHp = 0; }
  $engine->turnResolutionState->update($context);
  expect($scene->result?->outcome())->toBe('defeat')->and($scene->continuesAfterDefeat())->toBeTrue()
    ->and($config->getReservePolicy())->toBe(ReservePolicy::NONE);
  $entry = $config->entryContext(new GameState());
  expect(array_map(static fn($actor) => $actor->character->name, $entry->activeActors))->toBe(['Member0', 'Member1', 'Member2'])
    ->and($entry->reserveActors)->toHaveCount(4);
});
