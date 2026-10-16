<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Presentation\BattleCommandTimeline;
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

it('silences each primary attack and shows only other action names without changing resolution',
  function (bool $activeTime, bool $graphical, bool $reduced, string $kind) {
    $priorConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
    $priorDelta = new ReflectionProperty(Time::class, 'deltaTime')->getValue();
    $priorCwd = getcwd();
    $root = createTestDirectory('battle-action-announcement-');
    writeQueuedAttackEffects($root);
    chdir($root);
    ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reduced]]));
    try {
      $fixture = createTargetExecutionFixture($activeTime, $graphical, $this->createStub(AudioManager::class));
      [$engine, $context, $screen, $actor, , $enemies] = $fixture;
      $primary = new BasicSkill('Opening cut', '', '', 0, 0, invocation: new SkillInvocation(accuracy: 100),
        effects: [new HPDamageSkillEffect('7', variance: 0)]);
      new ReflectionProperty(Character::class, 'attackSkill')->setValue($actor, $primary);
      $skill = match ($kind) {
        'primary' => clone $primary,
        'technique' => new BasicSkill('Feint', '', '', 0, 0),
        'magic' => new MagicSkill('Ember', '', '', 0, 0),
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
      $expected = in_array($kind, ['fallback', 'primary'], true) ? null : $action->name;
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
    ->with(['fallback', 'primary', 'technique', 'magic', 'special', 'guard', 'item']);
