<?php

use Ichiloto\Engine\Battle\BattlePacing;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\TraditionalTurnBasedBattleEngine;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\UI\BattleCharacterNameWindow;
use Ichiloto\Engine\Battle\UI\BattleCharacterStatusWindow;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\States\State;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class TurnEndField extends BattleFieldWindow
{
  public array $lines = [];
  public float $hold = 0;
  public function __construct() {}
  public function showStatChangePopup(CharacterInterface $battler, array $lines, bool $clearExisting = true,
    float $durationSeconds = 0.0): void { $this->lines = $lines; $this->hold = $durationSeconds; }
  public function clearStatChangePopups(): void { $this->lines = []; }
  public function clearBattleFlash(): void {}
  public function clearSummonShake(): void {}
  public function clearMagicCastEffects(): void {}
  public function clearTargetIndicators(): void {}
}

final class TurnEndScreen extends BattleScreen
{
  public array $observed = [];
  public array $messages = [];
  public bool $failNextRedraw = false;
  public function __construct(private bool $graphical)
  {
    $this->fieldWindow = new TurnEndField();
    $this->characterStatusWindow = new class extends BattleCharacterStatusWindow {
      public function __construct() {}
      public function setCharacters(array $characters): void {}
    };
    $this->characterNameWindow = new class extends BattleCharacterNameWindow {
      public function __construct() {}
      public function setActiveSelection(int $index, bool $blink = false): void {}
    };
  }
  public function getPacing(): BattlePacing { return new BattlePacing(); }
  public function usesGraphicalField(): bool { return $this->graphical; }
  public function hideMessage(): void {}
  public function showMessage(string $text): void { $this->messages[] = $text; }
  public function alert(string $text): void { $this->messages[] = $text; }
  public function refreshField(): void
  {
    if ($this->failNextRedraw) {
      $this->failNextRedraw = false;
      throw new RuntimeException('Synthetic redraw failure');
    }
    $playback = $this->fieldWindow->getCommandPlayback();
    $this->observed[] = [$playback?->phase, $playback?->getPoseRole($playback->actor),
      $this->fieldWindow->lines];
  }
}

function createTurnEndFixture(bool $partyTick, bool $graphical): array
{
  $game = new class extends Game { public function __construct() {} public function __destruct() {} };
  $party = new Party();
  $actor = new Character('Actor', 1, new Stats(currentHp: $partyTick ? 5 : 100, totalHp: 100));
  $party->addMember($actor);
  $enemy = (new ReflectionClass(Enemy::class))->newInstanceWithoutConstructor();
  new ReflectionProperty($enemy, 'name')->setValue($enemy, 'Enemy without art');
  new ReflectionProperty($enemy, 'stats')->setValue($enemy,
    new Stats(currentHp: $partyTick ? 100 : 5, totalHp: 100));
  $screen = new TurnEndScreen($graphical);
  $context = new TurnStateExecutionContext($game, $party, new Troop('Synthetic', [$enemy]), $screen, []);
  $engine = new class($game) extends TraditionalTurnBasedBattleEngine {
    public array $transitions = [];
    public function setState(TurnState $state): void { $this->transitions[] = $state; }
  };
  $state = new class($engine) extends ActionExecutionState {
    public function endTurn(TurnStateExecutionContext $context, Turn $turn): void { $this->beginTurnEnd($context, $turn); }
    protected function playDamageFeedbackSound(TurnStateExecutionContext $context, CharacterInterface $target,
      int $previousHp, ?\Ichiloto\Engine\Battle\Resolution\CombatTargetResult $result = null): void {}
  };
  $turn = new Turn($partyTick ? $actor : $enemy);
  $turn->battler->addState(new State('poison', 'Poison', tickFormula: '-10'));
  $context->setTurns([$turn]);
  return [$state, $engine, $context, $screen, $turn];
}

it('ticks only the completing battler once and expires durations on its own turns', function () {
  $actor = new Character('Actor', 1, new Stats(currentHp: 100, totalHp: 100));
  $other = new Character('Other', 1, new Stats(currentHp: 100, totalHp: 100));
  foreach ([$actor, $other] as $battler) {
    $battler->addState(new State('poison', 'Poison', durationTurns: 2, tickFormula: '-10'));
    $battler->addState(new State('sleep', 'Sleep', durationTurns: 1, preventsAction: true));
  }
  $turn = new Turn($actor);
  expect($actor->getActionBlockingState()?->id)->toBe('sleep');
  $events = $turn->resolveEndStateTicks();
  expect($actor->stats->currentHp)->toBe(90)->and($other->stats->currentHp)->toBe(100)
    ->and($actor->getActionBlockingState())->toBeNull()->and($other->getActionBlockingState()?->id)->toBe('sleep')
    ->and($actor->hasState('poison'))->toBeTrue()->and($turn->isCompleted)->toBeTrue()
    ->and($turn->resolveEndStateTicks())->toBe($events)->and($actor->stats->currentHp)->toBe(90);
  new Turn($other)->resolveEndStateTicks();
  expect($actor->stats->currentHp)->toBe(90)->and($actor->hasState('poison'))->toBeTrue();
  new Turn($actor)->resolveEndStateTicks();
  expect($actor->stats->currentHp)->toBe(80)->and($actor->hasState('poison'))->toBeFalse();
});

it('does not tick a battler already knocked out before its turn', function () {
  $actor = new Character('KO', 1, new Stats(currentHp: 0, totalHp: 100));
  $actor->addState(new State('sleep', 'Sleep', durationTurns: 1, preventsAction: true));
  expect(new Turn($actor)->resolveEndStateTicks())->toBeEmpty()
    ->and($actor->hasState('sleep'))->toBeTrue()->and($actor->stats->currentHp)->toBe(0);
});

it('ticks and expires a sleeping battler after its consumed turn without ticking anyone else', function () {
  [$state, $engine, $context, $screen, $turn] = createTurnEndFixture(true, false);
  $turn->battler->stats->currentHp = 100;
  $turn->battler->addState(new State('sleep', 'Sleep', durationTurns: 1, preventsAction: true));
  $enemy = $context->getLivingTroopBattlers()[0];
  $enemy->addState(new State('other-poison', 'Other poison', durationTurns: 1, tickFormula: '-10'));
  $state->update($context);
  expect($turn->battler->stats->currentHp)->toBe(90)->and($turn->battler->hasState('sleep'))->toBeFalse()
    ->and($enemy->stats->currentHp)->toBe(100)->and($enemy->hasState('other-poison'))->toBeTrue()
    ->and($screen->messages)->toBe(['Actor is down with Sleep and cannot act!', 'Actor recovered from Sleep.'])
    ->and($screen->fieldWindow->getCommandPlayback()?->phase)->toBe('reaction')
    ->and($context->getCurrentTurn())->toBe($turn)->and($engine->transitions)->toBeEmpty();
  $state->exit($context);
});

it('keeps a failed initial result redraw observable without repeating the lethal state tick', function () {
  [$state, $engine, $context, $screen, $turn] = createTurnEndFixture(false, true);
  $screen->failNextRedraw = true;
  $state->endTurn($context, $turn);
  $playback = $screen->fieldWindow->getCommandPlayback();
  expect($turn->battler->stats->currentHp)->toBe(0)
    ->and($playback?->presentationFailure?->getMessage())->toBe('Synthetic redraw failure')
    ->and($engine->transitions)->toBeEmpty()->and($context->getCurrentTurn())->toBe($turn)
    ->and(array_column($screen->fieldWindow->lines, 'text'))->toBe(['-10 Poison', 'KO']);
  $screen->refreshField();
  expect($screen->observed[0][1])->toBe(BattlePoseRole::KNOCKOUT)
    ->and($turn->resolveEndStateTicks())->toHaveCount(1)->and($turn->battler->stats->currentHp)->toBe(0);
  $state->exit($context);
});

it('holds lethal own-turn damage and KO before victory or defeat in every presentation',
  function (bool $partyTick, bool $graphical, bool $reducedMotion) {
    $prior = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
    $delta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    try {
      [$state, $engine, $context, $screen, $turn] = createTurnEndFixture($partyTick, $graphical);
      $state->endTurn($context, $turn);
      $playback = $screen->fieldWindow->getCommandPlayback();
      expect($turn->battler->stats->currentHp)->toBe(0)->and($playback)->not->toBeNull()
        ->and($playback->phase)->toBe('reaction')->and($playback->getPoseRole($turn->battler))->toBe(BattlePoseRole::KNOCKOUT)
        ->and($playback->getAdvanceFraction())->toBe(0.0)->and($context->getCurrentTurn())->toBe($turn)
        ->and($engine->transitions)->toBeEmpty()->and(array_column($screen->fieldWindow->lines, 'text'))->toBe(['-10 Poison', 'KO'])
        ->and($screen->fieldWindow->hold)->toBeGreaterThan(0)
        ->and($playback->getActiveSegments($reducedMotion, !$graphical))->toBeEmpty();
      expect(new ReflectionMethod(BattleFieldWindow::class, 'isCommandAdvanced')->invoke($screen->fieldWindow, $turn->battler))
        ->toBeFalse();
      $playback->pause();
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
      $state->update($context);
      expect($context->getCurrentTurn())->toBe($turn)->and($engine->transitions)->toBeEmpty();
      $playback->resume();
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $screen->fieldWindow->hold / 2);
      $state->update($context);
      expect($engine->transitions)->toBeEmpty()->and($context->getCurrentTurn())->toBe($turn)
        ->and($screen->fieldWindow->lines)->not->toBeEmpty();
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
      $state->update($context);
      expect($engine->transitions)->toBe([$engine->turnResolutionState])->and($context->getCurrentTurn())->toBeNull()
        ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($screen->fieldWindow->lines)->toBeEmpty()
        ->and($screen->observed[0][1])->toBe(BattlePoseRole::KNOCKOUT);
    } finally {
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $delta);
      $prior === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $prior);
    }
  })->with([
    'enemy terminal' => [false, false, false], 'party terminal' => [true, false, false],
    'enemy graphical' => [false, true, false], 'party graphical' => [true, true, false],
    'enemy calm terminal' => [false, false, true], 'party calm terminal' => [true, false, true],
    'enemy calm graphical' => [false, true, true], 'party calm graphical' => [true, true, true],
  ]);
