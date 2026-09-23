<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleTurnTimings;
use Ichiloto\Engine\Battle\Presentation\BattleFeedbackRole;
use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\CombatTargetResult;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Resolution\ResolutionKind;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnResolutionState;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\TurnStateExecutionContext;
use Ichiloto\Engine\Battle\UI\BattleFieldWindow;
use Ichiloto\Engine\Battle\UI\BattleScreen;
use Ichiloto\Engine\Cutscenes\Summons\SummonCompiledCutscene;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class ActionEffectTrace
{
  public array $events = [];
  public array $frames = [];

  public function __construct(public string $renderMode) {}
}

final class ActionEffectField extends BattleFieldWindow
{
  public function __construct(private ActionEffectTrace $trace) {}
  public function erase(?int $x = null, ?int $y = null): void {}
  public function render(?int $x = null, ?int $y = null): void {}
  public function clearTargetIndicators(): void {}
  public function clearMagicCastEffects(): void {}
  public function clearStatChangePopups(): void {}
  public function clearBattleFlash(): void
  {
    if ($this->trace->renderMode === 'cleanup-fail') {
      $this->trace->events[] = 'cleanup-failed';
      throw new RuntimeException('cleanup render failed');
    }
  }
  public function clearSummonShake(): void {}
  public function showSummonCutsceneFrame(SummonCompiledCutscene $cutscene, int $frameIndex): void
  {
    if ($this->trace->renderMode === 'none') { return; }
    $this->trace->frames[] = $frameIndex;
    $this->trace->events[] = 'render:' . $frameIndex;
    if ($this->trace->renderMode === 'fail' && $frameIndex === 0) {
      throw new RuntimeException('display offline');
    }
  }
}

final class ActionEffectScreen extends BattleScreen
{
  public function __construct(private ActionEffectTrace $trace)
  {
    $this->fieldWindow = new ActionEffectField($trace);
  }
  public function hideMessage(): void {}
  public function hideControls(): void {}
  public function showControls(): void {}
  public function refreshField(): void {}
  public function showMessage(string $text): void { $this->trace->events[] = $text; }
}

/** Exercise the action state's real presentation-to-resolution boundary without audio or a native window. */
function runActionEffectPlayback(
  array $timing,
  string $renderMode,
  bool $reducedMotion,
  ?ActionEffectTrace $trace = null,
  ?callable $afterResolution = null,
): array
{
  $previousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['accessibility' => ['reducedMotion' => $reducedMotion]]));
  try {
    $trace ??= new ActionEffectTrace($renderMode);
    $context = (new ReflectionClass(TurnStateExecutionContext::class))->newInstanceWithoutConstructor();
    new ReflectionProperty(TurnStateExecutionContext::class, 'ui')->setValue($context, new ActionEffectScreen($trace));
    $actor = new Character('Caster', 0, new Stats());
    $target = new Character('Target', 0, new Stats(currentHp: 100, totalHp: 100));
    $cutscene = new SummonCompiledCutscene('', 'fixture', fps: 1000,
      cueSchedule: [
        ['id' => 'before', 'frame' => 1, 'type' => 'showMessage', 'payload' => ['text' => 'before']],
        ['id' => 'impact', 'frame' => 2, 'type' => 'applyEffect'],
        ['id' => 'after', 'frame' => 3, 'type' => 'showMessage', 'payload' => ['text' => 'after']],
      ],
      defaults: ['name' => '', 'lengthFrames' => 4, 'effectTiming' => $timing]);
    $resolutions = 0;
    new ReflectionMethod(ActionExecutionState::class, 'resolvePresentedAction')->invoke(
      makeActionExecutionStateForTest(), $context, $actor, $target, null,
      new BattleTurnTimings(0, 0, 0, 0, 0, 0, 0), $cutscene, null,
      function () use ($target, $trace, $afterResolution, &$resolutions): void {
        $resolutions++;
        $target->stats->currentHp -= 7;
        $trace->events[] = 'resolve';
        if ($afterResolution !== null) { $afterResolution(); }
      },
    );
    return ['events' => $trace->events, 'frames' => $trace->frames,
      'resolutions' => $resolutions, 'hp' => $target->stats->currentHp];
  } finally {
    $previousConfig === null ? ConfigStore::remove(ProjectConfig::class)
      : ConfigStore::put(ProjectConfig::class, $previousConfig);
  }
}

it('resolves an authored summon once at cue, frame or end through the action state', function ($timing, $renderMode, $reducedMotion, $expectedFrames) {
  $result = runActionEffectPlayback($timing, $renderMode, $reducedMotion);
  $events = $result['events'];
  $resolution = array_search('resolve', $events, true);
  $before = array_search('before', $events, true);
  $after = array_search('after', $events, true);

  expect($result['resolutions'])->toBe(1)
    ->and($result['hp'])->toBe(93)
    ->and($result['frames'])->toBe($expectedFrames)
    ->and($before)->toBeInt()
    ->and($after)->toBeInt()
    ->and($resolution)->toBeInt()
    ->and($resolution > $before)->toBeTrue()
    ->and($timing['mode'] === 'end' ? $resolution > $after : $resolution < $after)->toBeTrue();
})->with([
  'cue' => [['mode' => 'cue', 'cueId' => 'impact'], 'normal', false, [0, 1, 2, 3]],
  'frame' => [['mode' => 'frame', 'frame' => 2], 'normal', false, [0, 1, 2, 3]],
  'end' => [['mode' => 'end'], 'normal', false, [0, 1, 2, 3]],
  'cue after render failure' => [['mode' => 'cue', 'cueId' => 'impact'], 'fail', false, [0]],
  'frame after render failure' => [['mode' => 'frame', 'frame' => 2], 'fail', false, [0]],
  'end after render failure' => [['mode' => 'end'], 'fail', false, [0]],
  'cue without renderer drawing' => [['mode' => 'cue', 'cueId' => 'impact'], 'none', false, []],
  'cue reduced motion' => [['mode' => 'cue', 'cueId' => 'impact'], 'normal', true, [3]],
  'frame reduced motion' => [['mode' => 'frame', 'frame' => 2], 'normal', true, [3]],
  'end reduced motion' => [['mode' => 'end'], 'normal', true, [3]],
]);

it('preserves a gameplay failure when summon cleanup also fails', function () {
  $trace = new ActionEffectTrace('cleanup-fail');
  $attempts = 0;
  expect(function () use ($trace, &$attempts): void {
    runActionEffectPlayback(['mode' => 'cue', 'cueId' => 'impact'], 'cleanup-fail', false,
      $trace, function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('gameplay failed');
      });
  })->toThrow(RuntimeException::class, 'gameplay failed');
  expect($attempts)->toBe(1)
    ->and(array_count_values($trace->events)['resolve'] ?? 0)->toBe(1)
    ->and($trace->events)->toContain('cleanup-failed');
});

it('builds floating damage and knockout popup lines for defeated targets', function () {
  $state = makeActionExecutionStateForTest();
  $target = new Character('Liora', 0, new Stats(currentHp: 0, totalHp: 100, currentMp: 12, totalMp: 20));

  $lines = invokeActionExecutionPopupBuilder($state, $target, 48, 12);

  expect($lines)->toBe([
    ['text' => '48', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE],
    ['text' => 'KO', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::KO],
  ]);
});

it('builds a miss popup when no visible stat changes occur', function () {
  $state = makeActionExecutionStateForTest();
  $target = new Character('Kaelion', 0, new Stats(currentHp: 75, totalHp: 100, currentMp: 18, totalMp: 20));

  $lines = invokeActionExecutionPopupBuilder($state, $target, 75, 18);

  expect($lines)->toBe([
    ['text' => 'MISS', 'color' => Color::WHITE, 'role' => BattleFeedbackRole::MISS],
  ]);
});

it('builds damage Critical and elemental feedback from typed results', function () {
  $state = makeActionExecutionStateForTest();
  $target = new Character('Kaelion', 0, new Stats(currentHp: 70, totalHp: 100));
  $hit = new CombatHitResult(
    'attack',
    'attack:1',
    'Enemy',
    'Kaelion',
    true,
    '',
    20,
    ResolutionKind::PHYSICAL_DAMAGE,
    20,
    0,
    0,
    0.0,
    true,
    1,
    true,
    1.5,
    false,
    'Fire',
    ElementalOutcome::WEAK,
    2.0,
    100,
    -30,
    30,
    0,
    0,
    70,
    1,
    100,
  );

  $lines = invokeActionExecutionPopupBuilder(
    $state,
    $target,
    100,
    10,
    new CombatTargetResult('Kaelion', [$hit]),
  );

  expect($lines)->toBe([
    ['text' => 'WEAK!', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::WEAK],
    ['text' => 'CRITICAL', 'color' => Color::YELLOW, 'role' => BattleFeedbackRole::CRITICAL],
    ['text' => '30', 'color' => Color::LIGHT_RED, 'role' => BattleFeedbackRole::DAMAGE],
  ]);
});

it('maps restorative magic to a green cast effect', function () {
  $state = makeActionExecutionStateForTest();
  $skill = new MagicSkill(
    'Cure',
    'Restores HP.',
    '*',
    3,
    0,
    new ItemScope(),
    Occasion::ALWAYS,
    effects: [
      new HPRecoverSkillEffect('20'),
    ],
    effectType: MagicEffectType::RESTORATIVE,
  );

  $color = invokeMagicCastEffectColorResolver($state, new SkillBattleAction($skill));

  expect($color)->toBe(Color::GREEN);
});

it('maps battle actions to optional project-configured presentation sounds', function () {
  $state = makeActionExecutionStateForTest();
  $scope = new ItemScope();
  $physicalSkill = new SpecialSkill(
    'Dual Slash',
    'Strike twice.',
    'DSL',
    0,
    0,
    $scope,
    effects: [new HPDamageSkillEffect('10')],
  );
  $utilitySkill = new SpecialSkill('Observe', 'Study the field.', 'OBS', 0, 0, $scope);
  $destructiveMagic = new MagicSkill(
    'Fire',
    'Deals fire damage.',
    '*',
    4,
    0,
    $scope,
    effectType: MagicEffectType::DESTRUCTIVE,
  );
  $supportMagic = new MagicSkill(
    'Barrier',
    'Protects an ally.',
    '*',
    4,
    0,
    $scope,
    effectType: MagicEffectType::BUFF,
  );

  expect(invokeActionPresentationSoundResolver($state, new AttackAction('Attack')))
    ->toBe(SystemSound::BATTLE_ATTACK)
    ->and(invokeActionPresentationSoundResolver($state, new SkillBattleAction($physicalSkill)))
    ->toBe(SystemSound::BATTLE_SKILL)
    ->and(invokeActionPresentationSoundResolver($state, new SkillBattleAction($destructiveMagic)))
    ->toBe(SystemSound::BATTLE_MAGIC_DESTRUCTIVE)
    ->and(invokeActionPresentationSoundResolver($state, new SkillBattleAction($supportMagic)))
    ->toBe(SystemSound::BATTLE_MAGIC_SUPPORT)
    ->and(invokeActionPresentationSoundResolver($state, new SkillBattleAction($utilitySkill)))
    ->toBeNull();
});

it('lets authored animation and summon audio override generic battle cues', function () {
  $state = makeActionExecutionStateForTest();
  $action = new AttackAction('Attack');
  $animation = new Animation(1, 'Authored Hit');
  $animation->setCue(1, new AnimationCue(soundEffect: 'custom-hit'));

  expect(invokeActionPresentationSoundResolver($state, $action, $animation))->toBeNull()
    ->and(invokeActionPresentationSoundResolver($state, $action, isSummonAction: true))->toBeNull();
});

it('resolves explicit skill and item animation ids without falling back by name', function () {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-action-animation-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $path = $root . '/assets/Data/animations.php';
  try {
    chdir($root);
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'Named Skill'], ['id' => 8, 'name' => 'Selected']];");
    $resolve = new ReflectionMethod(ActionExecutionState::class, 'resolveActionAnimation');
    $state = makeActionExecutionStateForTest();
    $selectedSkill = new SpecialSkill('Named Skill', '', '*', 0, 0, animationId: 8);
    $missingSkill = new SpecialSkill('Named Skill', '', '*', 0, 0, animationId: 999);
    $legacySkill = new SpecialSkill('Named Skill', '', '*', 0, 0);
    $item = new Item('Potion', '', '*', 0, animationId: 8);
    $legacyItem = new Item('Potion', '', '*', 0);
    expect($resolve->invoke($state, new SkillBattleAction($selectedSkill))?->id)->toBe(8)
      ->and($resolve->invoke($state, new ItemBattleAction($item))?->id)->toBe(8)
      ->and($resolve->invoke($state, new SkillBattleAction($missingSkill)))->toBeNull()
      ->and($resolve->invoke($state, new SkillBattleAction($legacySkill))?->id)->toBe(7)
      ->and($resolve->invoke($state, new ItemBattleAction($legacyItem)))->toBeNull();
  } finally {
    chdir($previous);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
});

it('treats battle as concluded when a side has no living battlers', function () {
  $state = makeActionExecutionStateForTest();
  $party = new Party();
  $troop = new Troop('Test Troop');

  $party->addMember(new Character('Kaelion', 0, new Stats(currentHp: 100, totalHp: 100, currentMp: 20, totalMp: 20)));
  $troop->addMember(new Character('Slime', 0, new Stats(currentHp: 0, totalHp: 50, currentMp: 0, totalMp: 0)));

  $context = makeActionExecutionContextForTest($party, $troop);

  expect(invokeBattleConclusionChecker($state, $context))->toBeTrue();
});

it('builds typed popup lines for damaging and restorative state ticks', function () {
  $state = (new ReflectionClass(TurnResolutionState::class))->newInstanceWithoutConstructor();
  $battler = new Character('Kaelion', 0, new Stats(currentHp: 100, totalHp: 100));
  $poison = Ichiloto\Engine\Entities\States\State::fromArray([
    'id' => 'poison',
    'name' => 'Poison',
    'tickFormula' => '-4',
  ]);
  $regen = Ichiloto\Engine\Entities\States\State::fromArray([
    'id' => 'regen',
    'name' => 'Regen',
    'tickFormula' => '2',
  ]);
  $battler->addState($poison);
  $battler->addState($regen);
  $events = $battler->tickStates();
  $lines = invokeStateTickPopupBuilder($state, $events);

  expect($lines)->toBe([
    ['text' => '-4 Poison', 'color' => Color::LIGHT_RED],
    ['text' => '+2 Regen', 'color' => Color::LIGHT_GREEN],
  ]);
});

it('rejects malformed stat popup lines at the battlefield boundary', function () {
  $window = (new ReflectionClass(BattleFieldWindow::class))->newInstanceWithoutConstructor();
  $method = new ReflectionMethod(BattleFieldWindow::class, 'normalizeStatChangePopupLines');

  expect($method->invoke($window, [['text' => ''], ['text' => '7', 'color' => Color::LIGHT_RED]]))->toBe([
    ['text' => '7', 'color' => Color::LIGHT_RED],
  ])
    ->and(fn() => $method->invoke($window, ['-4 Poison']))
    ->toThrow(InvalidArgumentException::class, 'must be an array containing text');
});

/**
 * Creates a lightweight action execution state for popup-line tests.
 *
 * @return ActionExecutionState
 */
function makeActionExecutionStateForTest(): ActionExecutionState
{
  return (new ReflectionClass(ActionExecutionState::class))->newInstanceWithoutConstructor();
}

/**
 * Invokes the protected popup builder on the action execution state.
 *
 * @param ActionExecutionState $state The action execution state under test.
 * @param Character $target The target to inspect.
 * @param int $previousHp The target HP before the action.
 * @param int $previousMp The target MP before the action.
 * @param CombatTargetResult|null $result Typed target resolution.
 * @return array<int, array{text: string, color: Color}>
 */
function invokeActionExecutionPopupBuilder(
  ActionExecutionState $state,
  Character $target,
  int $previousHp,
  int $previousMp,
  ?CombatTargetResult $result = null,
): array
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'buildStatChangePopupLines');

  return $method->invoke($state, $target, $previousHp, $previousMp, $result);
}

/**
 * Invokes the protected magic effect color resolver on the action execution state.
 *
 * @param ActionExecutionState $state The action execution state under test.
 * @param SkillBattleAction $action The magic battle action.
 * @return Color
 */
function invokeMagicCastEffectColorResolver(ActionExecutionState $state, SkillBattleAction $action): Color
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'resolveMagicCastEffectColor');

  return $method->invoke($state, $action->skill);
}

/**
 * Invokes the generic action-presentation sound resolver.
 */
function invokeActionPresentationSoundResolver(
  ActionExecutionState $state,
  ?BattleAction $action,
  ?Animation $animation = null,
  bool $isSummonAction = false,
): ?SystemSound
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'resolveActionPresentationSound');

  return $method->invoke($state, $action, $animation, $isSummonAction);
}

/**
 * Creates a lightweight turn-state execution context for battle-end checks.
 *
 * @param Party $party The test party.
 * @param Troop $troop The test troop.
 * @return TurnStateExecutionContext
 */
function makeActionExecutionContextForTest(Party $party, Troop $troop): TurnStateExecutionContext
{
  $context = (new ReflectionClass(TurnStateExecutionContext::class))->newInstanceWithoutConstructor();

  foreach (['party' => $party, 'troop' => $troop] as $property => $value) {
    $reflectionProperty = new ReflectionProperty(TurnStateExecutionContext::class, $property);
    $reflectionProperty->setValue($context, $value);
  }

  return $context;
}

/**
 * Invokes the protected battle-conclusion helper on the action execution state.
 *
 * @param ActionExecutionState $state The action execution state under test.
 * @param TurnStateExecutionContext $context The execution context to inspect.
 * @return bool
 */
function invokeBattleConclusionChecker(
  ActionExecutionState $state,
  TurnStateExecutionContext $context
): bool
{
  $method = new ReflectionMethod(ActionExecutionState::class, 'battleHasConcluded');

  return $method->invoke($state, $context);
}

/**
 * Produces the same popup payload used by turn resolution from real tick events.
 *
 * @param TurnResolutionState $state The turn-resolution state.
 * @param array<int, array{state: object, hpDelta: int, expired: bool}> $events State tick events.
 * @return array<int, array{text: string, color: Color}>
 */
function invokeStateTickPopupBuilder(TurnResolutionState $state, array $events): array
{
  $method = new ReflectionMethod(TurnResolutionState::class, 'buildStateTickPopupLines');

  return $method->invoke($state, $events);
}
