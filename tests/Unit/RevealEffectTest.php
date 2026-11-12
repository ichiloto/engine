<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\Insight\ElementalInsight;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Presentation\BattlerInsight;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\RevealSkillEffect;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillEffectExecutor;
use Ichiloto\Engine\Entities\Skills\SkillRecord;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeDepth;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\UI\Presentation\EnemyInsightSheet;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use function Tests\Support\Battle\createTargetExecutionFixture;

require_once __DIR__ . '/../Support/Battle/QueuedCommandFixture.php';

/** Answers every roll with one value, so a reveal's chance is decided by the test. */
final class FixedRevealRoll implements CombatRandomSource
{
  public array $rolls = [];

  public function __construct(private int $roll) {}

  public function nextInt(int $minimum, int $maximum): int
  {
    $this->rolls[] = [$minimum, $maximum];

    return max($minimum, min($maximum, $this->roll));
  }
}

/** A battle context that records what battles reveal into a knowledge service of the test's own. */
final class RevealKnowledgeContext extends TurnStateExecutionContext
{
  public ?KnowledgeProgressService $knowledge = null;

  public function getKnowledge(): ?KnowledgeProgressService { return $this->knowledge; }
}

function revealFoe(string $name = 'Synthetic Foe', array $affinities = [], ?string $subject = null): Enemy
{
  $enemy = new ReflectionClass(Enemy::class)->newInstanceWithoutConstructor();
  new ReflectionProperty(Enemy::class, 'name')->setValue($enemy, $name);
  new ReflectionProperty(Enemy::class, 'position')->setValue($enemy, new Vector2());
  new ReflectionProperty(Enemy::class, 'rewards')->setValue($enemy, new \Ichiloto\Engine\Battle\BattleRewards(0, 0, []));
  new ReflectionProperty(Enemy::class, 'stats')->setValue($enemy, new Stats(currentHp: 34, totalHp: 80, currentMp: 6,
    totalMp: 10, attack: 12, defence: 8, magicAttack: 5, magicDefence: 6, speed: 10, grace: 4, evasion: 3));
  new ReflectionProperty(Enemy::class, 'knowledgeSubjectId')->setValue($enemy, $subject);
  $enemy->setElementAffinities($affinities);

  return $enemy;
}

function revealSpell(array $facts, int $chance = 100, ItemScopeNumber $number = ItemScopeNumber::ONE, int $cost = 2): MagicSkill
{
  return new MagicSkill('Synthetic Read', '', '', $cost, 0,
    new ItemScope(ItemScopeSide::ENEMY, $number, ItemScopeStatus::ALIVE), Occasion::BATTLE_SCREEN,
    effects: [new RevealSkillEffect($facts, $chance)]);
}

function revealKnowledge(): KnowledgeProgressService
{
  return new KnowledgeProgressService(new KnowledgeCatalog([
    'recordTypes' => ['creature'],
    'subjects' => [
      // Authored field observations do not stop a battle from recording what it reveals.
      ['id' => 'creature.synthetic', 'recordType' => 'creature', 'displayName' => 'Synthetic Foe', 'quickCard' => 'Quick.',
        'deepCard' => 'Deep field research.', 'observations' => ['field-trace']],
    ],
  ]));
}

beforeEach(function () {
  $this->revealConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::remove(ProjectConfig::class);
});

afterEach(function () {
  ConfigStore::remove(ProjectConfig::class);
  if ($this->revealConfig !== null) { ConfigStore::put(ProjectConfig::class, $this->revealConfig); }
});

it('reveals every listed fact on a successful roll and keeps them for the battle', function () {
  $foe = revealFoe();
  $executor = new SkillEffectExecutor(random: new FixedRevealRoll(75));
  $caster = new Character('Caster', 1, new Stats(currentMp: 10, totalMp: 10));

  $result = $executor->execute(revealSpell(['hp'], 75), $caster, [$foe], 'skill.read', 'skill.read#1');

  expect($foe->isFactRevealed(RevealFact::HP))->toBeTrue()
    ->and($foe->isFactRevealed(RevealFact::STATS))->toBeFalse()
    ->and($result->targets[0]->secondaryOutcomes)->toBe([['type' => 'reveal', 'id' => 'hp', 'applied' => true]]);

  // A later failed read takes nothing back.
  new SkillEffectExecutor(random: new FixedRevealRoll(76))
    ->execute(revealSpell(['hp', 'stats'], 75), $caster, [$foe], 'skill.read', 'skill.read#2');
  expect($foe->isFactRevealed(RevealFact::HP))->toBeTrue()->and($foe->isFactRevealed(RevealFact::STATS))->toBeFalse();
});

it('reveals nothing on a failed roll while the spell still spends its MP', function () {
  $foe = revealFoe();
  $caster = new Character('Caster', 1, new Stats(currentHp: 10, totalHp: 10, currentMp: 10, totalMp: 10));
  $action = new SkillBattleAction(revealSpell(['hp', 'mp', 'stats'], 75, cost: 4), random: new FixedRevealRoll(76));

  $action->execute($caster, [$foe]);

  expect($foe->revealedFacts)->toBe([])
    ->and($caster->stats->currentMp)->toBe(6)
    ->and(array_column($action->lastResult->targets[0]->secondaryOutcomes, 'applied'))->toBe([false, false, false]);
});

it('rolls a guaranteed reveal never and reads every foe of a troop', function () {
  $foes = [revealFoe('Foe A'), revealFoe('Foe B'), revealFoe('Foe C')];
  $random = new FixedRevealRoll(100);

  new SkillEffectExecutor(random: $random)->execute(revealSpell(['hp', 'mp', 'stats'], number: ItemScopeNumber::ALL),
    new Character('Caster', 1, new Stats()), $foes, 'skill.archive', 'skill.archive#1');

  expect($random->rolls)->toBe([]);
  foreach ($foes as $foe) {
    expect($foe->isFactRevealed(RevealFact::HP))->toBeTrue()->and($foe->isFactRevealed(RevealFact::MP))->toBeTrue()
      ->and($foe->isFactRevealed(RevealFact::STATS))->toBeTrue();
  }
});

it('leaves a party member alone, whose facts the player always sees', function () {
  $ally = new Character('Ally', 1, new Stats());

  $result = new SkillEffectExecutor()->execute(revealSpell(['hp']), new Character('Caster', 1, new Stats()), [$ally],
    'skill.read', 'skill.read#1');

  expect($result->targets[0]->secondaryOutcomes)->toBe([]);
});

it('reads and writes a reveal effect record and refuses malformed ones', function () {
  $data = ['kind' => 'magic', 'name' => 'Read', 'description' => '', 'icon' => '', 'cost' => 2, 'cooldown' => 0,
    'occasion' => 'Battle Screen', 'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
    'invocation' => ['message' => '%2', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 10],
    'effects' => [['type' => 'reveal', 'facts' => ['hp', 'mp', 'stats'], 'chancePercent' => 75]], 'effectType' => 'buff'];
  $skill = SkillRecord::readSkill($data);
  $guaranteed = SkillRecord::readSkill([...$data, 'effects' => [['type' => 'reveal', 'facts' => ['affinities']]]]);

  expect(SkillRecord::writeSkill($skill)['effects'])->toBe($data['effects'])
    ->and(SkillRecord::writeSkill($guaranteed)['effects'])->toBe([['type' => 'reveal', 'facts' => ['affinities']]])
    ->and(SkillRecord::getEffectTypes())->toContain('reveal');
  foreach ([['facts' => []], ['facts' => ['hp', 'hp']], ['facts' => ['mood']], ['facts' => ['hp'], 'chancePercent' => 0],
    ['facts' => ['hp'], 'chancePercent' => 101], ['facts' => ['hp'], 'element' => 'Fire']] as $effect) {
    expect(fn() => SkillRecord::readSkill([...$data, 'effects' => [['type' => 'reveal', ...$effect]]]))
      ->toThrow(InvalidArgumentException::class);
  }
});

it('names the strongest elements against a foe, or that none beats ordinary damage', function () {
  $weak = revealFoe(affinities: ['Fire' => 2.0, 'Ice' => 1.5, 'Water' => 0.5]);
  $tied = revealFoe(affinities: ['Fire' => 2.0, 'Ice' => 2.0]);
  $sturdy = revealFoe(affinities: ['Fire' => 0.5, 'Earth' => 1.0]);

  expect(ElementalInsight::findStrongestElements($weak))->toBe(['Fire'])
    ->and(ElementalInsight::findStrongestElements($tied))->toBe(['Fire', 'Ice'])
    ->and(ElementalInsight::findStrongestElements($sturdy))->toBe([])
    ->and(BattlerInsight::formatBestElement(['Fire']))->toBe('Best element: Fire')
    ->and(BattlerInsight::formatBestElement([]))->toBe('No element beats ordinary damage');
});

it('shows only what has been revealed about a foe, as it is now', function () {
  $foe = revealFoe(affinities: ['Thunder' => 2.0]);

  expect(BattlerInsight::getInfo($foe))->toBe('')->and(BattlerInsight::getTargetLine($foe))->toBeNull();
  $foe->revealFacts(RevealFact::HP);
  expect(BattlerInsight::getTargetLine($foe))->toBe('HP 34/80')->and(BattlerInsight::getInfo($foe))->toBe('Synthetic Foe  HP 34/80');
  $foe->stats->currentHp = 20;
  $foe->revealFacts(RevealFact::MP, RevealFact::STATS, RevealFact::AFFINITIES);
  expect(BattlerInsight::getInfo($foe))->toBe(
    'Synthetic Foe  HP 20/80  MP 6/10  ATK 12  DEF 8  MAT 5  MDF 6  SPD 10  GRC 4  EVA 3  Best element: Thunder')
    ->and(BattlerInsight::getInfo(new Character('Ally', 1, new Stats())))->toBe('');
});

it('records what a battle reveals for the foe kind without opening its field research', function () {
  $knowledge = revealKnowledge();
  $foe = revealFoe(subject: 'creature.synthetic');

  expect($knowledge->recordEnemyReveal($foe, RevealFact::HP))->toBeTrue()
    ->and($knowledge->recordEnemyReveal($foe, RevealFact::HP))->toBeFalse()
    ->and($knowledge->isFactRecorded('creature.synthetic', RevealFact::HP))->toBeTrue()
    ->and($knowledge->isFactRecorded('creature.synthetic', RevealFact::STATS))->toBeFalse()
    ->and($knowledge->progress->depth('creature.synthetic'))->toBe(KnowledgeDepth::DISCOVERED)
    ->and($knowledge->recordEnemyReveal(revealFoe('Unrecorded'), RevealFact::HP))->toBeFalse();

  $subject = $knowledge->catalog->subject('creature.synthetic');
  $sheet = EnemyInsightSheet::createSections($knowledge, $subject, [revealFoe(affinities: ['Fire' => 2.0])]);
  expect(array_map(static fn($entry): string => $entry->label . '=' . $entry->text, $sheet[0]))->toBe(['=Analysis', 'HP=80']);

  $knowledge->recordEnemyReveal($foe, RevealFact::AFFINITIES);
  $sheet = EnemyInsightSheet::createSections($knowledge, $subject, [revealFoe(affinities: ['Fire' => 2.0])]);
  expect($sheet[0][2]->label)->toBe('Best element')
    ->and(EnemyInsightSheet::createSections(revealKnowledge(), $subject, [$foe]))->toBe([]);
});

it('shows a battle reveal over the foe and in the Info window and records it for the foe kind',
  function (bool $activeTime, bool $graphical) {
    $root = createTestDirectory('ichiloto-reveal-battle-');
    $cwd = getcwd();
    chdir($root);
    mkdir('assets/Data', 0700, true);
    file_put_contents('assets/Data/animations.php', '<?php return [];');
    file_put_contents('assets/Data/summons.php', '<?php return [];');
    try {
      $fixture = createTargetExecutionFixture($activeTime, $graphical, test()->createStub(AudioManager::class));
      [$engine, $context, $screen, $actor, , $enemies] = $fixture;
      $recording = new RevealKnowledgeContext($context->game, $context->party, $context->troop, $context->ui, []);
      $recording->knowledge = revealKnowledge();
      new ReflectionProperty($engine, 'turnStateExecutionContext')->setValue($engine, $recording);
      new ReflectionProperty(Enemy::class, 'knowledgeSubjectId')->setValue($enemies[1], 'creature.synthetic');
      $fixture[1] = $recording;
      $action = new SkillBattleAction(revealSpell(['hp', 'stats']));

      queueTargetExecutionOn($fixture, $action, [$enemies[1]]);
      for ($step = 0; $step < 400 && $engine->state === $engine->actionExecutionState; $step++) {
        new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.05);
        $engine->state->update($recording);
        if (str_starts_with($screen->announcement ?? '', 'Enemy B')) { $shown = $screen->announcement; }
      }

      $popup = array_find($screen->fieldWindow->results, static fn(array $result): bool => $result[0] === $enemies[1]);
      expect($enemies[1]->isFactRevealed(RevealFact::HP))->toBeTrue()
        ->and($shown ?? null)->toBe('Enemy B  HP 100/100  ATK 5  DEF 5  MAT 5  MDF 5  SPD 1  GRC 1  EVA 0')
        ->and($popup[1])->toContain(['text' => 'HP 100/100', 'color' => \Ichiloto\Engine\IO\Enumerations\Color::LIGHT_CYAN,
          'role' => BattleFeedbackRole::REVEAL])
        ->and($recording->knowledge->isFactRecorded('creature.synthetic', RevealFact::HP))->toBeTrue()
        ->and($recording->knowledge->isFactRecorded('creature.synthetic', RevealFact::STATS))->toBeTrue()
        ->and($enemies[0]->revealedFacts)->toBe([]);
    } finally {
      chdir($cwd);
    }
  })->with([false, true])->with([false, true]);

function queueTargetExecutionOn(array $fixture, SkillBattleAction $action, array $targets): void
{
  [$engine, $context, , $actor] = $fixture;
  if ($engine instanceof \Ichiloto\Engine\Battle\Engines\ActiveTime\ActiveTimeBattleEngine) {
    $engine->queueImmediateTurn($context, $actor, $action, $targets);
  } else {
    $turn = new \Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Turn($actor);
    $turn->action = $action;
    $turn->targets = $targets;
    $context->setTurns([$turn]);
    $engine->setState($engine->actionExecutionState);
  }
  new ReflectionProperty(Time::class, 'deltaTime')->setValue(null, 0.0);
  $engine->state->update($context);
}
