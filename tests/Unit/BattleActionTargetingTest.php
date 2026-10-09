<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattleCanvasLayout;
use Ichiloto\Engine\Battle\Presentation\BattleEffectDirection;
use Ichiloto\Engine\Battle\Presentation\BattlePoseRole;
use Ichiloto\Engine\Battle\Presentation\GraphicalBattleEffects;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Effects\HPDamageEffect;
use Ichiloto\Engine\Entities\Effects\BaseEffect;
use Ichiloto\Engine\Entities\Effects\MPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDrainSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPRecoverySkillEffect;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\ValueBasis;
use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope as InventoryScope;
use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\createQueuedBattleFixture;
use function Tests\Support\Battle\queueTargetExecution;
use function Tests\Support\Battle\writeQueuedAttackEffects;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

beforeEach(function () {
  $this->targetConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  $this->targetDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
});

afterEach(function () {
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $this->targetDelta);
  $this->targetConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->targetConfig);
});

it('accepts supplied party and troop instances through shared queued combat setup', function (bool $activeTime) {
  $synthetic = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [, $original, , $actor, $ally, $enemies] = $synthetic;
  $fixture = createQueuedBattleFixture($activeTime, false, $this->createMock(AudioManager::class),
    $original->party, $original->troop);
  [$engine, $context, $screen] = $fixture;
  expect($fixture)->toHaveCount(3)->and($context->party)->toBe($original->party)
    ->and($context->troop)->toBe($original->troop)->and($context->ui)->toBe($screen);
  $item = new Item('Revival', '', '', 10, 2,
    scope: new InventoryScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD),
    effects: [new ResurrectionEffect('Revive', '', 25, 1, ValueBasis::ACTUAL)]);
  $turn = queueTargetExecution($fixture, new ItemBattleAction($item), [$ally], $actor);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->state->update($context);
  expect($turn->isCompleted)->toBeTrue()->and($ally->stats->currentHp)->toBe(25)
    ->and($item->quantity)->toBe(1)->and($enemies[0]->stats->currentHp)->toBe(100)
    ->and($screen->fieldWindow->results[0][0])->toBe($ally);
})->with([false, true]);

it('executes a queued revival on its fallen ally through impact, reaction and cleanup',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
    $item = new Item('Revival', '', '', 10, 2,
      scope: new InventoryScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD),
      effects: [new ResurrectionEffect('Revive', '', 25, 1, ValueBasis::ACTUAL)]);
    $turn = queueTargetExecution($fixture, new ItemBattleAction($item), [$ally]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($ally)])
      ->and(array_map(spl_object_id(...), $playback?->targets ?? []))->toBe([spl_object_id($ally)]);
    $impact = array_find($playback->plan->timeline->cueSchedule,
      static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, ($impact - 1) / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(0)->and($item->quantity)->toBe(2);
    $playback->pause();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(0)->and($item->quantity)->toBe(2);
    $playback->resume();
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($ally->stats->currentHp)->toBe(25)->and($item->quantity)->toBe(1)
      ->and($actor->stats->currentHp)->toBe(80)->and($enemies[0]->stats->currentHp)->toBe(100)
      ->and($enemies[1]->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(50)
      ->and($screen->fieldWindow->results[0][0])->toBe($ally);
    $reaction = $playback->plan->phases['reaction']['start'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, max(0, $reaction - $impact) / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($playback->getPoseRole($ally))->toBe(BattlePoseRole::HEAL);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($item->quantity)->toBe(1)
      ->and($turn->isCompleted)->toBeTrue()->and($playback->getPoseRole($ally))->toBe(BattlePoseRole::IDLE)
      ->and($playback->getAdvanceFraction())->toBe(0.0);
  })->with([false, true])->with([false, true])->with([false, true]);

it('keeps a queued support action within its ally scope when its chosen target falls', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $skill = new MagicSkill('Recover', '', '', 4, 0, new ItemScope(ItemScopeSide::ALLY),
    effects: [new HPRecoverSkillEffect('20', variance: 0)]);
  $turn = queueTargetExecution($fixture, new SkillBattleAction($skill), [$ally]);
  expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($actor)]);
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
  $engine->state->update($context);
  expect($actor->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(46)
    ->and($enemies[0]->stats->currentHp)->toBe(100)->and($enemies[1]->stats->currentHp)->toBe(100);
})->with([false, true]);

it('shows both sides of an MP drain even when the gain exactly offsets the command cost',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $actor->stats->currentMp = 20;
    $action = new SkillBattleAction(new MagicSkill('Drain', '', '', 4, 0,
      effects: [new MPDrainSkillEffect('4', variance: 0)]));
    $turn = queueTargetExecution($fixture, $action, [$enemies[0]]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    $reaction = $playback->plan->phases['reaction']['start'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $reaction / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($actor->stats->currentMp)->toBe(20)->and($enemies[0]->stats->currentMp)->toBe(46)
      ->and(array_map(static fn(array $result) => $result[0], $screen->fieldWindow->results))->toBe([$enemies[0], $actor])
      ->and(array_column($screen->fieldWindow->results[0][1], 'text'))->toBe(['-4 MP'])
      ->and(array_column($screen->fieldWindow->results[1][1], 'text'))->toBe(['+4 MP'])
      ->and($playback->getPoseRole($enemies[0]))->toBe(BattlePoseRole::DAMAGE)
      ->and($playback->getPoseRole($actor))->toBe(BattlePoseRole::HEAL);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($turn->isCompleted)->toBeTrue()->and($actor->stats->currentMp)->toBe(20)
      ->and($screen->fieldWindow->results)->toHaveCount(2)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with([false, true])->with([false, true])->with([false, true]);

it('preserves opposing MP effects instead of a net zero and does not present the caster cost as damage',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $action = new SkillBattleAction(new MagicSkill('Exchange', '', '', 4, 0,
      effects: [new MPDamageSkillEffect('10', variance: 0), new MPRecoverySkillEffect('10', variance: 0)]));
    $turn = queueTargetExecution($fixture, $action, [$enemies[0]]);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($actor->stats->currentMp)->toBe(46)->and($enemies[0]->stats->currentMp)->toBe(50)
      ->and($screen->fieldWindow->results)->toHaveCount(1)
      ->and($screen->fieldWindow->results[0][0])->toBe($enemies[0])
      ->and(array_column($screen->fieldWindow->results[0][1], 'text'))->toBe(['-10 MP', '+10 MP'])
      ->and($turn->isCompleted)->toBeTrue();
  })->with([false, true])->with([false, true])->with([false, true]);

it('preserves opposing item effects and reports bounded recovery through the same result path',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor] = $fixture;
    $remove = new class('MP loss', '', 10, 1, ValueBasis::ACTUAL) extends BaseEffect {
      public function apply(CharacterInterface $target): void { $target->stats->currentMp -= $this->value; }
    };
    $item = new Item('Exchange item', '', '', 10, 2,
      scope: new InventoryScope(ItemScopeSide::ALLY),
      effects: [$remove, new MPRecoveryEffect('Restore', '', 20, 1, ValueBasis::ACTUAL)]);
    $action = new ItemBattleAction($item);
    $turn = queueTargetExecution($fixture, $action, [$actor]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    $reaction = $playback->plan->phases['reaction']['start'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $reaction / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect($actor->stats->currentMp)->toBe(50)->and($item->quantity)->toBe(1)
      ->and(array_column($screen->fieldWindow->results[0][1], 'text'))->toBe(['-10 MP', '+10 MP'])
      ->and($playback->getPoseRole($actor))->toBe(BattlePoseRole::DAMAGE);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($turn->isCompleted)->toBeTrue()->and($item->quantity)->toBe(1)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with([false, true])->with([false, true])->with([false, true]);

it('shows full resolved damage through queued attacks skills items repeats and groups while drain remains bounded',
  function (string $kind, bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    foreach ([800, $kind === 'repeat' ? 250 : 200] as $remainingHp) {
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class),
        actorStats: new Stats(currentHp: 20, totalHp: 1000, currentMp: 50, totalMp: 50, attack: 400));
      [$engine, $context, $screen, $actor, , $enemies] = $fixture;
      $targets = $kind === 'group' ? $enemies : [$enemies[0]];
      foreach ($targets as $index => $target) {
        $target->stats->totalHp = 800;
        $target->stats->currentHp = $index === 0 ? $remainingHp : 200;
        $target->stats->defence = 0;
        $target->stats->magicDefence = 0;
      }
      $random = new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return max($minimum, min($maximum, 95)); }
      };
      $item = new Item('Damage item', '', '', 10, 2, scope: new InventoryScope(ItemScopeSide::ENEMY),
        effects: [new HPDamageEffect('Damage', '', 400, 1, ValueBasis::ACTUAL)]);
      $action = match ($kind) {
        'attack' => new AttackAction('Attack', random: $random),
        'item' => new ItemBattleAction($item),
        'physical', 'repeat' => new SkillBattleAction(new BasicSkill('Physical', '', '', 4, 0,
          invocation: new SkillInvocation(repeat: $kind === 'repeat' ? 2 : 1),
          effects: [new HPDamageSkillEffect($kind === 'repeat' ? '200' : '400', variance: 0,
            resolutionKind: ResolutionKind::PHYSICAL_DAMAGE)]), random: $random),
        default => new SkillBattleAction(new MagicSkill('Magic', '', '', 4, 0,
          scope: new ItemScope(ItemScopeSide::ENEMY, $kind === 'group' ? ItemScopeNumber::ALL : ItemScopeNumber::ONE),
          effects: [$kind === 'drain' ? new HPDrainSkillEffect('400', variance: 0)
            : new HPDamageSkillEffect('400', variance: 0)]), random: $random),
      };
      $turn = queueTargetExecution($fixture, $action, [$targets[0]]);
      $playback = $screen->fieldWindow->getCommandPlayback();
      $reaction = $playback->plan->phases['reaction']['start'];
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $reaction / BattleCommandTimeline::FPS);
      $engine->state->update($context);
      foreach ($targets as $index => $target) {
        $hp = $index === 0 ? $remainingHp : 200;
        expect($screen->fieldWindow->results[$index][0])->toBe($target)
          ->and(array_column($screen->fieldWindow->results[$index][1], 'text'))
          ->toBe($hp <= 400 ? ['400', 'KO'] : ['400'])
          ->and($target->stats->currentHp)->toBe(max(0, $hp - 400))
          ->and($action->lastResult->targets[$index]->getResolvedHpDamage())->toBe(400)
          ->and($action->lastResult->targets[$index]->actualHpLost())->toBe(min($hp, 400))
          ->and($playback->getPoseRole($target))->toBe($hp <= 400 ? BattlePoseRole::KNOCKOUT : BattlePoseRole::DAMAGE);
      }
      $drain = $kind === 'drain' ? min($remainingHp, 400) : 0;
      expect($actor->stats->currentHp)->toBe(20 + $drain)
        ->and($actor->stats->currentMp)->toBe(in_array($kind, ['attack', 'item'], true) ? 50 : 46)
        ->and($action->lastResult->hitCount())->toBe($kind === 'repeat' || $kind === 'drain' ? 2 : count($targets));
      if ($drain > 0) {
        expect($screen->fieldWindow->results[1][0])->toBe($actor)
          ->and(array_column($screen->fieldWindow->results[1][1], 'text'))->toBe(['+' . $drain]);
      }
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
      $engine->state->update($context);
      expect($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
        ->and($screen->fieldWindow->results)->toHaveCount(count($targets) + ($drain > 0 ? 1 : 0))
        ->and($item->quantity)->toBe($kind === 'item' ? 1 : 2)
        ->and($actor->stats->currentHp)->toBe(20 + $drain)->and($playback->getAdvanceFraction())->toBe(0.0);
    }
  })->with(['attack', 'physical', 'magic', 'item', 'drain', 'repeat', 'group'])
    ->with([false, true])->with([false, true])->with([false, true]);

it('retargets one attack to one living opponent, never the entire opposing side', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $turn = queueTargetExecution($fixture, new AttackAction('Attack'), [$ally]);
  expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($enemies[0])])
    ->and(array_map(spl_object_id(...), $screen->fieldWindow->getCommandPlayback()?->targets ?? []))
    ->toBe([spl_object_id($enemies[0])]);
})->with([false, true]);

it('does not spend a revival item when no fallen ally remains eligible', function (bool $activeTime) {
  $fixture = createTargetExecutionFixture($activeTime, false, $this->createMock(AudioManager::class));
  [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
  $ally->stats->currentHp = 100;
  $item = new Item('Revival', '', '', 10, 2,
    scope: new InventoryScope(ItemScopeSide::ALLY, status: ItemScopeStatus::DEAD),
    effects: [new ResurrectionEffect('Revive', '', 25, 1, ValueBasis::ACTUAL)]);
  $turn = queueTargetExecution($fixture, new ItemBattleAction($item), [$ally]);
  expect($item->quantity)->toBe(2)->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
    ->and($turn->isCompleted)->toBeTrue()->and($enemies[0]->stats->currentHp)->toBe(100);
})->with([false, true]);

it('resolves an all-ally command once and shows a result for each legal recipient',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, $ally, $enemies] = $fixture;
    $ally->stats->currentHp = 20;
    $skill = new MagicSkill('Synthetic group heal', '', '', 4, 0,
      new ItemScope(ItemScopeSide::ALLY, ItemScopeNumber::ALL), effects: [new HPRecoverSkillEffect('20', variance: 0)]);
    $action = new SkillBattleAction($skill);
    $turn = queueTargetExecution($fixture, $action, [$ally]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    expect(array_map(spl_object_id(...), $turn->targets))->toBe([spl_object_id($actor), spl_object_id($ally)])
      ->and(array_map(spl_object_id(...), $playback?->targets ?? []))->toBe(array_map(spl_object_id(...), $turn->targets));
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($actor->stats->currentHp)->toBe(100)->and($ally->stats->currentHp)->toBe(40)
      ->and($actor->stats->currentMp)->toBe(46)->and($action->lastResult?->targetCount())->toBe(2)
      ->and(array_map(static fn(array $result): int => spl_object_id($result[0]), $screen->fieldWindow->results))
      ->toBe([spl_object_id($actor), spl_object_id($ally)])
      ->and($enemies[0]->stats->currentHp)->toBe(100)->and($enemies[1]->stats->currentHp)->toBe(100)
      ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($turn->isCompleted)->toBeTrue();
  })->with([false, true])->with([false, true])->with([false, true]);

it('never replays a reused action result when its queued command cannot execute',
  function (bool $item, bool $activeTime, bool $graphical, bool $reducedMotion) {
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
    $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
    [$engine, $context, $screen, $actor, , $enemies] = $fixture;
    $target = $enemies[0];
    $target->stats->currentMp = 20;
    $action = $item
      ? new ItemBattleAction(new Item('Single use', '', '', 10, 1,
        scope: new InventoryScope(ItemScopeSide::ENEMY),
        effects: [new MPRecoveryEffect('Restore', '', 5, 1, ValueBasis::ACTUAL)]))
      : new SkillBattleAction(new MagicSkill('Drain', '', '', 4, 0,
        effects: [new MPDrainSkillEffect('4', variance: 0)]));
    $action->execute($actor, [$target]);
    $previousResult = $action->lastResult;
    expect($previousResult)->not->toBeNull();
    $actor->stats->currentMp = 0;
    $before = [$actor->stats->currentHp, $actor->stats->currentMp, $target->stats->currentHp, $target->stats->currentMp];
    $turn = queueTargetExecution($fixture, $action, [$target]);
    $playback = $screen->fieldWindow->getCommandPlayback();
    $reaction = $playback->plan->phases['reaction']['start'];
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $reaction / BattleCommandTimeline::FPS);
    $engine->state->update($context);
    expect([$actor->stats->currentHp, $actor->stats->currentMp, $target->stats->currentHp, $target->stats->currentMp])
      ->toBe($before)->and($action->lastResult)->toBe($previousResult)
      ->and($screen->fieldWindow->results)->toBeEmpty()
      ->and($screen->announcement)->toContain('cannot')
      ->and($playback->plan->resultsOnly)->toBeTrue()
      ->and($playback->getPoseRole($target))->toBe(BattlePoseRole::IDLE);
    new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
    $engine->state->update($context);
    expect($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
  })->with([false, true])->with([false, true])->with([false, true])->with([false, true]);

it('preserves turn-owned legacy effect cadence through the actual queued command',
  function (bool $activeTime, bool $graphical, bool $reducedMotion) {
    $root = sys_get_temp_dir() . '/ichiloto-paced-cells-' . bin2hex(random_bytes(5));
    $previous = getcwd();
    $catalogState = [];
    foreach (['battleAnimations', 'battleSummons', 'missingAnimationIds'] as $name) {
      $catalogState[$name] = new ReflectionProperty(BattleCommandCatalog::class, $name)->getValue();
    }
    try {
      mkdir($root . '/assets/Data', 0777, true);
      file_put_contents($root . '/assets/Data/summons.php', '<?php return [];');
      file_put_contents($root . '/assets/Data/animations.php', '<?php return ' . var_export([
        ['id' => 1, 'name' => 'Paced cells', 'maxFrames' => 5, 'frames' => array_map(
          static fn(int $frame): array => ['index' => $frame, 'cells' => [['symbol' => (string)$frame, 'x' => 0, 'y' => 0]]],
          range(1, 5))],
      ], true) . ';');
      chdir($root);
      ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
      BattleCommandCatalog::beginBattle();
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
      [$engine, $context, $screen, $actor, , $enemies] = $fixture;
      $skill = new BasicSkill('No guessed rate', '', '', 4, 0,
        effects: [new HPDamageSkillEffect('10', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE)], animationId: 1);
      $action = new SkillBattleAction($skill, random: new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return $maximum; }
      });
      $turn = queueTargetExecution($fixture, $action, [$enemies[0]]);
      $playback = $screen->fieldWindow->getCommandPlayback();
      $seconds = $screen->getPacing()->getTurnTimings($action)->effectAnimation;
      $boundary = static fn(int $frame): int => (int)ceil($frame * $seconds / 5 * BattleCommandTimeline::FPS - 1e-9);
      $start = $playback->plan->phases['target']['start'];
      expect($playback->plan->phases['target']['length'])->toBe($boundary(5));
      foreach (range(0, 4) as $frame) {
        $segments = $playback->session->getActiveSegments($start + $boundary($frame));
        expect($segments)->toHaveCount(1)->and($segments[0]['drawCommands'][0]['content'])->toBe((string)($frame + 1));
      }
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, ($start + $boundary(5) - 1) / BattleCommandTimeline::FPS);
      $engine->state->update($context);
      expect($enemies[0]->stats->currentHp)->toBe(100)->and($actor->stats->currentMp)->toBe(50);
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
      $engine->state->update($context);
      expect($enemies[0]->stats->currentHp)->toBe(90)->and($actor->stats->currentMp)->toBe(46);
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
      $engine->state->update($context);
      expect($turn->isCompleted)->toBeTrue()->and($screen->fieldWindow->getCommandPlayback())->toBeNull();
    } finally {
      chdir($previous);
      foreach ($catalogState as $name => $value) {
        new ReflectionProperty(BattleCommandCatalog::class, $name)->setValue(null, $value);
      }
      foreach (['animations.php', 'summons.php'] as $file) { unlink($root . '/assets/Data/' . $file); }
      rmdir($root . '/assets/Data');
      rmdir($root . '/assets');
      rmdir($root);
    }
  })->with([false, true])->with([false, true])->with([false, true]);

it('runs equipped and explicit attack choreography through queued combat, pause, results and cleanup',
  function (?WeaponType $weaponType, bool $double, bool $enemy, bool $activeTime, bool $graphical, bool $east, bool $reducedMotion) {
    $root = sys_get_temp_dir() . '/ichiloto-queued-effects-' . bin2hex(random_bytes(5));
    $previous = getcwd();
    $catalogState = [];
    foreach (['battleAnimations', 'battleSummons', 'missingAnimationIds'] as $name) {
      $catalogState[$name] = new ReflectionProperty(BattleCommandCatalog::class, $name)->getValue();
    }
    try {
      writeQueuedAttackEffects($root);
      chdir($root);
      ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
      BattleCommandCatalog::beginBattle();
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createMock(AudioManager::class));
      [$engine, $context, $screen, $hero, , $enemies] = $fixture;
      $actor = $enemy ? $enemies[0] : $hero;
      $recipient = $enemy ? $hero : $enemies[0];
      $recipient->stats->currentHp = 100;
      new ReflectionProperty($context, 'effectTimelines')->setValue($context, new EffectTimelineLibrary($root . '/assets'));
      if ($weaponType !== null) {
        $slots = $actor->equipment;
        $slots[0]->equipment = new Weapon('Not a presentation identity', '', '', 1, equipmentType: $weaponType);
      }
      $random = new class implements CombatRandomSource {
        public function nextInt(int $minimum, int $maximum): int { return $maximum; }
      };
      $skill = new BasicSkill('Renamed action', '', '', 4, 0, invocation: new SkillInvocation(repeat: $double ? 2 : 1),
        effects: [new HPDamageSkillEffect('10', variance: 0, resolutionKind: ResolutionKind::TRUE_DAMAGE)],
        animationId: $double ? 3 : null);
      $action = new SkillBattleAction($skill, random: $random);
      $turn = queueTargetExecution($fixture, $action, [$recipient], $actor);
      $playback = $screen->fieldWindow->getCommandPlayback();
      $blade = $double || $weaponType === WeaponType::SWORD;
      $expected = $double ? 'double' : ($blade ? 'blade' : 'impact');
      $bounds = [spl_object_id($actor) => new CanvasRectangle(450, 200, 40, 80),
        spl_object_id($recipient) => new CanvasRectangle($east ? 750 : 150, 200, 40, 80)];
      $layout = new BattleCanvasLayout(1440, 840);
      $seen = $reactions = [];
      $impact = array_find($playback->plan->timeline->cueSchedule,
        static fn(array $cue): bool => $cue['type'] === 'commandImpact')['frame'];
      $playback->pause();
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 10.0);
      $engine->state->update($context);
      expect($actor->stats->currentMp)->toBe(50)->and($recipient->stats->currentHp)->toBe(100);
      $playback->resume();
      for ($frame = 0; $frame <= $playback->session->totalFrames; $frame++) {
        if ($playback->phase === 'target' || $playback->phase === 'source') {
          if ($graphical) {
            $layers = GraphicalBattleEffects::compose($playback, $layout, $bounds, $root . '/assets', $reducedMotion)->images;
            foreach ($layers as $image) {
              $seen[$image->id] = $image->asset;
              if (str_contains($image->id, 'image-' . $expected . '-')) {
                $reverseStroke = str_contains($image->id, '-1-');
                expect($image->flipX)->toBe($reverseStroke !== ($blade && !$east))
                  ->and($image->sourceRect->x)->toBe(4);
              }
            }
          } else {
            foreach ($playback->getActiveSegments($reducedMotion, true) as $segment) {
              foreach ($segment['drawCommands'] as $command) {
                $oriented = BattleEffectDirection::orientCommand($command, 450, $east ? 750 : 150);
                $seen[$command['trackId']] = $oriented['content'];
                if (str_starts_with($command['trackId'], $expected . '-')) {
                  $reverseStroke = str_ends_with($command['trackId'], '-1');
                  expect($oriented['content'])->toBe($blade ? ($reverseStroke !== !$east ? '\\' : '/') : '*');
                }
              }
            }
          }
        }
        if ($playback->phase === 'reaction') { $reactions[] = $playback->getPoseRole($recipient); }
        if ($frame < $impact) {
          expect($actor->stats->currentMp)->toBe(50)->and($recipient->stats->currentHp)->toBe(100);
        }
        new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
        $engine->state->update($context);
        if ($screen->fieldWindow->getCommandPlayback() === null) { break; }
      }
      expect($playback->presentationFailure)->toBeNull()->and($playback->isCompleted)->toBeTrue()
        ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()->and($turn->isCompleted)->toBeTrue()
        ->and($actor->stats->currentMp)->toBe(46)->and($recipient->stats->currentHp)->toBe($double ? 80 : 90)
        ->and($enemies[1]->stats->currentHp)->toBe(100)->and($action->lastResult->hits())->toHaveCount($double ? 2 : 1)
        ->and($screen->fieldWindow->results)->toHaveCount(1)->and($reactions)->toContain(BattlePoseRole::DAMAGE)
        ->and($playback->getAdvanceFraction())->toBe(0.0);
      $expectedViews = $double && !$reducedMotion ? 3 : 2;
      expect($seen)->toHaveCount($expectedViews);
      if ($graphical) { expect(array_unique(array_values($seen)))->toContain('windup.png', $expected . '.png'); }
    } finally {
      chdir($previous);
      foreach ($catalogState as $name => $value) {
        new ReflectionProperty(BattleCommandCatalog::class, $name)->setValue(null, $value);
      }
      $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
      foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
      rmdir($root);
    }
  })->with([
    'blade' => [WeaponType::SWORD, false, false],
    'staff' => [WeaponType::STAFF, false, false],
    'flail' => [WeaponType::FLAIL, false, false],
    'gloves' => [WeaponType::GLOVE, false, false],
    'fists' => [null, false, false],
    'explicit double slash overrides staff' => [WeaponType::STAFF, true, false],
    'enemy default impact' => [null, false, true],
    'enemy explicit double slash' => [null, true, true],
  ])->with([false, true])->with([false, true])->with([false, true])->with([false, true]);
