<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
use Ichiloto\Engine\Battle\Presentation\BattleAnnouncement;
use Ichiloto\Engine\Battle\Resolution\SeededCombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Inventory\Items\ItemScope;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use function Tests\Support\Battle\createTargetExecutionFixture;
use function Tests\Support\Battle\queueTargetExecution;
use function Tests\Support\Battle\writeQueuedAttackEffects;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

it('honours authored silence, actor callouts and action names without changing resolution',
  function (bool $activeTime, bool $graphical, bool $reduced, string $kind) {
    $priorConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
    $priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
    $priorCwd = getcwd();
    $root = createTestDirectory('battle-action-announcement-');
    writeQueuedAttackEffects($root);
    chdir($root);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced],
      'messages' => ['battle' => ['announcements' => ['attack' => '', 'item' => '%1 drinks %2']]]]));
    try {
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createStub(AudioManager::class));
      [$engine, $context, $screen, $actor, , $enemies] = $fixture;
      $primary = new BasicSkill('Opening cut', '', '', 0, 0, invocation: new SkillInvocation(message: '', accuracy: 100),
        effects: [new HPDamageSkillEffect('7', variance: 0)]);
      new ReflectionProperty(Character::class, 'attackSkill')->setValue($actor, $primary);
      $skill = match ($kind) {
        'primary' => clone $primary,
        'technique' => new BasicSkill('Feint', '', '', 0, 0),
        'magic' => new MagicSkill('Ember', '', '', 0, 0),
        'authored primary' => new BasicSkill('Opening cut', '', '', 0, 0,
          invocation: new SkillInvocation(message: '%1 uses %2!')),
        'silent technique' => new SpecialSkill('Hidden technique', '', '', 0, 0,
          invocation: new SkillInvocation(message: '')),
        // The word Attack alone must not silence a named special action.
        'special' => new SpecialSkill('Attack', '', '', 0, 0),
        default => null,
      };
      $action = match ($kind) {
        'fallback' => new AttackAction('Renamed strike', random: new SeededCombatRandomSource(1)),
        'guard' => new GuardAction('Defend'),
        'item' => new ItemBattleAction(new Item('Tonic', '', '', 1, 1, scope: new ItemScope(ItemScopeSide::ENEMY))),
        default => new SkillBattleAction($skill, random: new SeededCombatRandomSource(1)),
      };
      $expected = match ($kind) {
        'fallback', 'primary', 'silent technique' => null,
        'authored primary' => $actor->name . ' uses Opening cut!',
        'item' => $actor->name . ' drinks Tonic',
        default => $action->name,
      };
      queueTargetExecution($fixture, $action, [$enemies[0]]);
      $playback = $screen->fieldWindow->getCommandPlayback();
      $announcement = $playback->plan->phases['announce'];
      expect($announcement['length'] === 0)->toBe($expected === null)
        ->and($playback->plan->phases['source']['start'])
        ->toBe($announcement['start'] + $announcement['length']);
      $seen = [];
      $result = null;
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 1 / BattleCommandTimeline::FPS);
      for ($frame = 0; $frame < 1800 && $context->getCurrentTurn() !== null; $frame++) {
        if ($screen->announcement !== null) { $seen[$screen->announcement] = true; }
        $engine->actionExecutionState->update($context);
        $result ??= $action->lastResult;
      }
      expect(array_keys($seen))->toBe($expected === null ? [] : [$expected])
        ->and($playback->presentationFailure)->toBeNull()
        ->and($context->getCurrentTurn())->toBeNull()
        ->and($screen->fieldWindow->getCommandPlayback())->toBeNull()
        ->and($screen->announcement)->toBeNull();
      if ($kind === 'primary') {
        expect($enemies[0]->stats->currentHp)->toBe(93)
          ->and($result)->not->toBeNull()->and($action->lastResult)->toBe($result)
          ->and($result->targets)->toHaveCount(1);
      }
    } finally {
      chdir($priorCwd);
      new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, $priorDelta);
      $priorConfig === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $priorConfig);
    }
  })->with([false, true])->with([false, true])->with([false, true])
    ->with(['fallback', 'primary', 'technique', 'magic', 'special', 'guard', 'item', 'authored primary', 'silent technique']);

it('formats actor and action tokens literally once and preserves unrelated tokens', function () {
  expect(BattleAnnouncement::format('%1 casts %2! / $1 casts $2!', 'Mage $2', 'Spell %1'))
    ->toBe('Mage $2 casts Spell %1! / Mage $2 casts Spell %1!')
    ->and(BattleAnnouncement::format('$10 %20 %3 $3', 'Actor', 'Action'))->toBe('$10 %20 %3 $3')
    ->and(BattleAnnouncement::format('  ', 'Actor', 'Action'))->toBe('');
});

it('allows project-defined and explicitly silent localized callouts for non-skill actions', function () {
  $prior = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['locale' => 'fr', 'messages' => [
    'battle' => ['announcements' => ['attack' => '%1 uses %2!']],
    'fr' => ['battle' => ['announcements' => ['attack' => '']]],
  ]]));
  try {
    expect(BattleAnnouncement::formatForAction(new AttackAction('Strike'), 'Actor', 'Strike'))->toBe('');
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['messages' => ['battle' => ['announcements' => ['attack' => '$1 uses $2!']]]]));
    expect(BattleAnnouncement::formatForAction(new AttackAction('Strike'), 'Actor', 'Strike'))->toBe('Actor uses Strike!');
  } finally {
    $prior === null ? ConfigStore::remove(ProjectConfig::class) : ConfigStore::put(ProjectConfig::class, $prior);
  }
});
