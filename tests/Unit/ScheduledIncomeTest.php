<?php

declare(strict_types=1);

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\Interfaces\PlayActivitySourceInterface;
use Ichiloto\Engine\Core\PlayActivity;
use Ichiloto\Engine\Core\Rect;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandCatalog;
use Ichiloto\Engine\Events\Interpreter\Commands\ScriptCommandReference;
use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Income\IncomeCatalog;
use Ichiloto\Engine\Income\IncomeLedger;
use Ichiloto\Engine\Income\IncomeSchedule;
use Ichiloto\Engine\Income\IncomeService;
use Ichiloto\Engine\Localization\EngineMessageTerms;
use Ichiloto\Engine\Scenes\Battle\BattleScene;
use Ichiloto\Engine\Scenes\Battle\States\BattlePauseState;
use Ichiloto\Engine\Scenes\Battle\States\BattleRunState;
use Ichiloto\Engine\Scenes\Battle\States\BattleVictoryState;
use Ichiloto\Engine\Scenes\Game\GameConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;

/** A synthetic schedule record; every test states only what it varies. */
function incomeSchedule(array $overrides = []): array
{
  return array_replace([
    'id' => 'wage',
    'name' => 'Wage',
    'cadence' => 'steps',
    'interval' => 10,
    'amount' => 100,
  ], $overrides);
}

/**
 * @param array<int, array<string, mixed>> $schedules
 * @return array{0: IncomeService, 1: Party, 2: GameState, 3: list<array{0: string, 1: string}>}
 */
function incomeFixture(array $schedules, ?IncomeLedger $ledger = null): array
{
  $party = new Party();
  $party->accountBalance = 0;
  $state = new GameState();
  $notices = new ArrayObject();
  $service = new IncomeService(
    new IncomeCatalog(['schedules' => $schedules]),
    $ledger ?? new IncomeLedger(),
    $state,
    $party,
    static function (string $title, string $text) use ($notices): void { $notices[] = [$title, $text]; },
  );
  return [$service, $party, $state, $notices];
}

describe('income schedule validation', function () {
  it('refuses records that cannot run, naming the record and the problem', function (array $record, string $message) {
    expect(fn() => new IncomeCatalog(['schedules' => [$record]], 'income.php'))
      ->toThrow(InvalidArgumentException::class, $message);
  })->with([
    'unknown field' => [incomeSchedule(['salary' => 1]), "income.php schedule [0] (wage) has unknown field 'salary'."],
    'unknown cadence' => [incomeSchedule(['cadence' => 'monthly']), 'cadence must be one of active_time, steps, battles, event.'],
    'counted cadence without interval' => [incomeSchedule(['interval' => null]), 'interval must be a positive whole number of steps.'],
    'interval on event cadence' => [incomeSchedule(['cadence' => 'event']), 'interval does not apply to the event cadence'],
    'idle timeout off active time' => [incomeSchedule(['idleTimeout' => 30]), 'idleTimeout applies only to the active_time cadence.'],
    'negative amount' => [incomeSchedule(['amount' => -1]), 'amount must be a whole number of at least 0.'],
    'unknown delivery' => [incomeSchedule(['delivery' => 'mail']), 'delivery must be one of deposit, hold.'],
    'rate without conditions' => [incomeSchedule(['rates' => [['amount' => 5]]]), 'rate [0] needs a non-empty conditions list'],
    'invalid condition' => [incomeSchedule(['conditions' => [['type' => 'weather', 'name' => 'rain']]]), 'unsupported world condition "weather"'],
    'missing name' => [incomeSchedule(['name' => ' ']), 'needs a non-empty name.'],
  ]);

  it('refuses a repeated id and unknown top-level fields', function () {
    expect(fn() => new IncomeCatalog(['schedules' => [incomeSchedule(), incomeSchedule()]]))
      ->toThrow(InvalidArgumentException::class, "repeats the id 'wage'")
      ->and(fn() => new IncomeCatalog(['payday' => []]))
      ->toThrow(InvalidArgumentException::class, "unknown field 'payday'");
  });

  it('is off for a project without an income file', function () {
    $catalog = IncomeCatalog::fromProject(sys_get_temp_dir() . '/no-such-project/assets/Data/income.php');

    expect($catalog->getSchedules())->toBe([]);
  });

  it('reads a project file and applies the documented defaults', function () {
    $file = tempnam(sys_get_temp_dir(), 'income') . '.php';
    file_put_contents($file, '<?php return ' . var_export(['schedules' => [
      incomeSchedule(['cadence' => 'active_time', 'interval' => 600]),
    ]], true) . ';');
    try {
      $schedule = IncomeCatalog::fromProject($file)->findSchedule('wage');
    } finally {
      unlink($file);
    }

    expect($schedule?->idleTimeout)->toBe(IncomeSchedule::DEFAULT_IDLE_TIMEOUT)
      ->and($schedule?->delivery->value)->toBe('deposit')
      ->and($schedule?->conditions)->toBe([]);
  });
});

describe('cadences', function () {
  it('pays a deposit schedule every interval of steps, keeping the remainder', function () {
    [$income, $party, , $notices] = incomeFixture([incomeSchedule()]);

    for ($step = 0; $step < 25; $step++) { $income->recordStep(); }

    expect($party->accountBalance)->toBe(200)
      ->and($income->ledger->getAccount('wage')->progress)->toBe(5.0)
      ->and(count($notices))->toBe(2)
      ->and($notices[0])->toBe(['Wage', '100 G received.']);
  });

  it('pays for won battles only through the battles cadence', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['cadence' => 'battles', 'interval' => 2])]);

    $income->recordStep();
    $income->recordBattleWon();
    expect($party->accountBalance)->toBe(0);
    $income->recordBattleWon();

    expect($party->accountBalance)->toBe(100);
  });

  it('counts active play while the player acts and stops once they go idle', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['cadence' => 'active_time', 'interval' => 10, 'idleTimeout' => 3])]);
    $account = $income->ledger->getAccount('wage');

    $income->advanceActivePlay(0.25, PlayActivity::PLAYING, 0.0);
    expect($account->progress)->toBe(0.25);

    // The idle timeout runs out a tenth of a second into this frame.
    $income->advanceActivePlay(0.25, PlayActivity::PLAYING, 3.15);
    expect($account->progress)->toEqualWithDelta(0.35, 1e-9);

    $income->advanceActivePlay(0.25, PlayActivity::PLAYING, 60.0);
    expect($account->progress)->toEqualWithDelta(0.35, 1e-9);

    // Presentation counts without input; menus and pauses never count.
    $income->advanceActivePlay(0.25, PlayActivity::PRESENTING, 60.0);
    $income->advanceActivePlay(0.25, PlayActivity::PAUSED, 0.0);
    expect($account->progress)->toEqualWithDelta(0.6, 1e-9)
      ->and($party->accountBalance)->toBe(0);
  });

  it('caps one frame so blocked or suspended time is not play', function () {
    [$income] = incomeFixture([incomeSchedule(['cadence' => 'active_time', 'interval' => 600])]);

    $income->advanceActivePlay(90.0, PlayActivity::PRESENTING, 90.0);

    expect($income->ledger->getAccount('wage')->progress)->toBe(PlayActivity::MAX_FRAME_SECONDS);
  });

  it('pays every completed period at once when one count crosses several', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['cadence' => 'active_time', 'interval' => 1, 'amount' => 7])]);

    for ($frame = 0; $frame < 9; $frame++) { $income->advanceActivePlay(0.5, PlayActivity::PLAYING, 0.0); }

    expect($party->accountBalance)->toBe(28)
      ->and($income->ledger->getAccount('wage')->progress)->toBe(0.5);
  });

  it('refuses an interval on the event cadence, even an empty one', function () {
    new IncomeCatalog(['schedules' => [incomeSchedule(['cadence' => 'event', 'interval' => null])]]);
  })->throws(InvalidArgumentException::class, 'interval does not apply to the event cadence');

  it('pays an event cadence only on payday', function () {
    $record = incomeSchedule(['cadence' => 'event']);
    unset($record['interval']);
    [$income, $party] = incomeFixture([$record]);

    $income->recordStep();
    $income->recordBattleWon();
    $income->advanceActivePlay(0.5, PlayActivity::PLAYING, 0.0);
    expect($party->accountBalance)->toBe(0)
      ->and($income->payday('wage'))->toBe(100)
      ->and($party->accountBalance)->toBe(100);
  });
});

describe('activation and pay tables', function () {
  it('counts and pays nothing until its conditions hold, then runs', function () {
    [$income, $party, $state] = incomeFixture([incomeSchedule([
      'interval' => 2,
      'conditions' => [['type' => 'switch', 'name' => 'employed']],
    ])]);

    $income->recordStep();
    $income->recordStep();
    expect($income->payday('wage'))->toBe(0)
      ->and($party->accountBalance)->toBe(0)
      ->and($income->ledger->getAccount('wage')->progress)->toBe(0.0);

    $state->setSwitch('employed');
    $income->recordStep();
    $income->recordStep();
    expect($party->accountBalance)->toBe(100);
  });

  it('pays the first rate whose conditions hold at payment time, else the amount', function () {
    $record = incomeSchedule(['cadence' => 'event', 'amount' => 50, 'rates' => [
      ['conditions' => [['type' => 'variable', 'name' => 'rank', 'value' => 'A']], 'amount' => 900],
      ['conditions' => [['type' => 'variable', 'name' => 'rank', 'op' => '!=', 'value' => '']], 'amount' => 300],
    ]]);
    unset($record['interval']);
    [$income, , $state] = incomeFixture([$record]);
    $state->setVariable('rank', '');

    expect($income->payday('wage'))->toBe(50);
    $state->setVariable('rank', 'C');
    expect($income->payday('wage'))->toBe(300);
    $state->setVariable('rank', 'A');
    expect($income->payday('wage'))->toBe(900);
  });
});

describe('held pay, collection and adjustments', function () {
  it('holds pay until collected, notifying the running total', function () {
    [$income, $party, , $notices] = incomeFixture([incomeSchedule(['interval' => 1, 'delivery' => 'hold'])]);

    $income->recordStep();
    $income->recordStep();
    expect($party->accountBalance)->toBe(0)
      ->and($income->getHeldPay('wage'))->toBe(200)
      ->and($notices[1])->toBe(['Wage', 'Pay waiting: 200 G.']);

    expect($income->collect('wage'))->toBe(200)
      ->and($party->accountBalance)->toBe(200)
      ->and($income->getHeldPay('wage'))->toBe(0)
      ->and($income->collect('wage'))->toBe(0)
      ->and($notices[2])->toBe(['Wage', 'Collected 200 G.']);
  });

  it('keeps what does not fit under the gold limit held', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['interval' => 1, 'delivery' => 'hold'])]);
    $income->recordStep();
    $party->accountBalance = Party::MAX_GOLD - 30;

    expect($income->collect('wage'))->toBe(30)
      ->and($party->accountBalance)->toBe(Party::MAX_GOLD)
      ->and($income->getHeldPay('wage'))->toBe(70);
  });

  it('collects earned pay after the schedule stops running', function () {
    [$income, $party, $state] = incomeFixture([incomeSchedule([
      'interval' => 1,
      'delivery' => 'hold',
      'conditions' => [['type' => 'switch', 'name' => 'employed']],
    ])]);
    $state->setSwitch('employed');
    $income->recordStep();
    $state->setSwitch('employed', false);

    expect($income->collect('wage'))->toBe(100);
  });

  it('folds a bonus into the next payment once', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['interval' => 1])]);
    $income->adjust('wage', 25);

    $income->recordStep();
    $income->recordStep();

    expect($party->accountBalance)->toBe(225);
  });

  it('carries a deduction larger than a payment forward and never debits gold', function () {
    [$income, $party] = incomeFixture([incomeSchedule(['interval' => 1])]);
    $party->accountBalance = 40;
    $income->adjust('wage', -250);

    $income->recordStep();
    expect($party->accountBalance)->toBe(40)
      ->and($income->ledger->getAccount('wage')->adjustment)->toBe(-150);
    $income->recordStep();
    expect($party->accountBalance)->toBe(40);
    $income->recordStep();
    expect($party->accountBalance)->toBe(90)
      ->and($income->ledger->getAccount('wage')->adjustment)->toBe(0);
  });

  it('refuses commands for a schedule the project does not declare', function () {
    [$income] = incomeFixture([incomeSchedule()]);

    expect(fn() => $income->collect('bonus'))
      ->toThrow(InvalidArgumentException::class, 'No income schedule has the id "bonus".');
  });
});

describe('notices', function () {
  it('declares its messages with defaults editors can list', function () {
    $terms = EngineMessageTerms::listTerms();

    expect($terms)->toHaveKeys(['income.paid', 'income.held', 'income.collected'])
      ->and($terms['income.paid']['placeholders'])->toHaveKeys(['%1', '%2', '%3', '%4']);
  });
});

describe('saves', function () {
  it('round-trips the ledger through a saved game config', function () {
    [$income] = incomeFixture([incomeSchedule(['interval' => 4, 'delivery' => 'hold'])]);
    for ($step = 0; $step < 6; $step++) { $income->recordStep(); }
    $income->adjust('wage', -10);

    $config = new GameConfig('fixture/map', new Party(), new Vector2(1, 1), new Rect(0, 0, 1, 1),
      MovementHeading::NORTH, income: $income->ledger->toArray());
    $restored = unserialize(serialize($config));
    [$reloaded] = incomeFixture([incomeSchedule(['interval' => 4, 'delivery' => 'hold'])],
      IncomeLedger::fromArray($restored->income));

    expect($reloaded->getHeldPay('wage'))->toBe(100)
      ->and($reloaded->ledger->toArray())->toBe($income->ledger->toArray())
      ->and($reloaded->ledger->toArray()['wage'])->toBe(['progress' => 2.0, 'held' => 100, 'adjustment' => -10]);
  });

  it('loads saves written before income existed as nothing counted or held', function () {
    $config = new GameConfig('fixture/map', new Party(), new Vector2(1, 1), new Rect(0, 0, 1, 1), MovementHeading::NORTH);
    $legacy = new ReflectionClass(GameConfig::class)->newInstanceWithoutConstructor();
    $data = $config->getSaveCompatibilityData();
    unset($data['income']);
    $legacy->__unserialize($data);

    expect($legacy->income)->toBe([])
      ->and(IncomeLedger::fromArray($legacy->income)->toArray())->toBe([]);
  });

  it('never grants pay from malformed saved values', function () {
    $ledger = IncomeLedger::fromArray(['wage' => ['progress' => INF, 'held' => '5000', 'adjustment' => 2.5], 7 => []]);

    expect($ledger->toArray())->toBe([]);
  });

  it('keeps accounts of schedules the project no longer declares', function () {
    $ledger = IncomeLedger::fromArray(['retired' => ['progress' => 0, 'held' => 40, 'adjustment' => 0]]);

    expect($ledger->toArray())->toBe(['retired' => ['progress' => 0.0, 'held' => 40, 'adjustment' => 0]]);
  });
});

describe('script commands', function () {
  it('declares payday, collect_income and adjust_income against income schedules', function () {
    $catalog = ScriptCommandCatalog::createEngineCatalog();

    foreach (['payday', 'collect_income', 'adjust_income'] as $type) {
      $definition = $catalog->findDefinition($type);
      $schedule = array_find($definition?->fields ?? [], static fn($field): bool => $field->key === 'schedule');
      expect($schedule?->reference)->toBe(ScriptCommandReference::INCOME_SCHEDULE)
        ->and($schedule?->required)->toBeTrue();
    }
    expect($catalog->findDefinition('adjust_income')?->findProblems(['type' => 'adjust_income', 'schedule' => 'wage']))
      ->not->toBe([]);
  });
});

describe('scenes', function () {
  it('reports battle commands as play, openings and results as presentation, and its pause as paused', function () {
    $scene = new ReflectionClass(BattleScene::class)->newInstanceWithoutConstructor();
    $state = new ReflectionProperty(BattleScene::class, 'state');
    $activity = static function (string $class) use ($scene, $state): PlayActivity {
      $state->setValue($scene, new ReflectionClass($class)->newInstanceWithoutConstructor());
      return $scene->getPlayActivity();
    };

    expect($activity(BattleRunState::class))->toBe(PlayActivity::PLAYING)
      ->and($activity(BattleVictoryState::class))->toBe(PlayActivity::PRESENTING)
      ->and($activity(BattlePauseState::class))->toBe(PlayActivity::PAUSED);
  });

  it('counts campaign play from whichever scene ran the frame, and only won battles', function () {
    [$income, $party] = incomeFixture([
      incomeSchedule(['id' => 'time', 'cadence' => 'active_time', 'interval' => 1, 'amount' => 3]),
      incomeSchedule(['id' => 'wins', 'cadence' => 'battles', 'interval' => 1, 'amount' => 40]),
    ]);
    $campaign = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(GameScene::class, 'income')->setValue($campaign, $income);
    $playing = $this->createMockForIntersectionOfInterfaces([SceneInterface::class, PlayActivitySourceInterface::class]);
    $playing->method('getPlayActivity')->willReturn(PlayActivity::PRESENTING);

    $campaign->advanceActivePlay(0.5, $playing);
    $campaign->advanceActivePlay(0.5, null);
    $campaign->advanceActivePlay(0.5, $playing);
    $campaign->concludeBattle(new BattleResult('Escaped'));
    $campaign->concludeBattle(new BattleResult('Defeat'));
    $campaign->concludeBattle(new BattleResult('Victory'));

    expect($party->accountBalance)->toBe(43);
  });
});
