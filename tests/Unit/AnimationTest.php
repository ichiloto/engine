<?php

use Ichiloto\Engine\Animations\Animation;
use Ichiloto\Engine\Animations\AnimationCue;
use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Animations\AnimationPlaybackSession;
use Ichiloto\Engine\Animations\AnimationPlayer;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Animations\ActionAnimationResolver;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Battle\Engines\TurnBasedEngines\Traditional\States\ActionExecutionState;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Util\Debug;

it('hydrates animations from arrays and preserves frame cells and cues', function () {
  $animation = Animation::fromArray([
    'id' => 12,
    'name' => 'Hit Spark',
    'position' => 'center',
    'maxFrames' => 3,
    'frames' => [
      [
        'index' => 1,
        'cells' => [
          ['symbol' => '*', 'x' => -1, 'y' => 0, 'color' => 'yellow'],
        ],
      ],
    ],
    'cues' => [
      ['frame' => 1, 'soundEffect' => 'Blow3', 'flashColor' => 'white', 'flashDurationFrames' => 2],
    ],
  ]);

  expect($animation->id)->toBe(12)
    ->and($animation->name)->toBe('Hit Spark')
    ->and($animation->position)->toBe(AnimationTargetPosition::CENTER)
    ->and($animation->maxFrames)->toBe(3)
    ->and($animation->getFrame(1)->getCellAt(-1, 0)?->symbol)->toBe('*')
    ->and($animation->getCue(1)?->soundEffect)->toBe('Blow3')
    ->and($animation->getCue(1)?->flashColor)->toBe('white');
});

it('adds and removes cells and cues through the runtime model', function () {
  $animation = new Animation(1, 'Test Animation', maxFrames: 2);

  $animation->setCell(1, 0, 0, '*', 'red');
  $animation->setCue(1, new AnimationCue(soundEffect: 'Spark', flashColor: 'red', flashDurationFrames: 1));
  $animation->setCell(1, 0, 0, ' ');
  $animation->setCue(1, new AnimationCue());

  expect($animation->getFrame(1)->getCellAt(0, 0))->toBeNull()
    ->and($animation->getCue(1))->toBeNull();
});

it('advances reusable animations non-blockingly across every elapsed frame', function () {
  $animation = new Animation(2, 'Non-blocking Animation', maxFrames: 4);
  $session = new AnimationPlaybackSession($animation, 0.1);

  expect($session->update(0.05))->toBe([])
    ->and($session->currentFrame)->toBe(1)
    ->and($session->update(0.25))->toBe([2, 3, 4])
    ->and($session->currentFrame)->toBe(4)
    ->and($session->isComplete)->toBeFalse();

  expect($session->update(0.1))->toBe([])
    ->and($session->isComplete)->toBeTrue();
});

it('keeps the blocking animation player compatible with shared traversal', function () {
  $animation = new Animation(3, 'Blocking Animation', maxFrames: 3);
  $rendered = [];

  (new AnimationPlayer(0.01))->play(
    $animation,
    function (int $frameIndex) use (&$rendered): void {
      $rendered[] = $frameIndex;
    },
  );

  expect($rendered)->toBe([1, 2, 3]);
});

it('reduces cell animation motion to its final frame while firing all cues in order', function () {
  $animation = new Animation(4, 'Cued', maxFrames: 4);
  $animation->setCue(1, new AnimationCue(soundEffect: 'first'));
  $animation->setCue(3, new AnimationCue(flashColor: 'white', flashDurationFrames: 2));
  $rendered = $cues = [];
  (new AnimationPlayer(0.01))->play($animation,
    function (int $frame) use (&$rendered): void { $rendered[] = $frame; },
    function (AnimationCue $cue, int $frame) use (&$cues): void { $cues[] = $frame; },
    reducedMotion: true);
  expect($rendered)->toBe([4])->and($cues)->toBe([1, 3]);
});

it('refuses reduced-motion playback that would silently omit legacy renderer cues', function () {
  $animation = new Animation(6, 'Cued', maxFrames: 3);
  $animation->setCue(1, new AnimationCue(soundEffect: 'first'));

  expect(fn() => (new AnimationPlayer())->play($animation, static function (): void {}, reducedMotion: true))
    ->toThrow(InvalidArgumentException::class);
});

it('continues authored cell cues when a frame cannot render', function () {
  $animation = new Animation(5, 'Failing Frame', maxFrames: 3);
  $animation->setCue(1, new AnimationCue(soundEffect: 'first'));
  $animation->setCue(3, new AnimationCue(soundEffect: 'last'));
  $cues = [];
  expect(function () use ($animation, &$cues): void { (new AnimationPlayer(0.01))->play($animation,
    static function (): void { throw new RuntimeException('display offline'); },
    function (AnimationCue $cue, int $frame) use (&$cues): void { $cues[] = $frame; }); })
    ->toThrow(RuntimeException::class, 'display offline');
  expect($cues)->toBe([1, 3]);
});

it('does not consume a rendering type error as an optional animation failure', function () {
  $animation = new Animation(6, 'Invalid renderer', maxFrames: 3);
  $animation->setCue(3, new AnimationCue(soundEffect: 'later'));
  $cues = [];
  expect(function () use ($animation, &$cues): void {
    (new AnimationPlayer(0.01))->play($animation,
      static function (): void { throw new TypeError('renderer contract broken'); },
      function (AnimationCue $cue, int $frame) use (&$cues): void { $cues[] = $frame; });
  })->toThrow(TypeError::class, 'renderer contract broken');
  expect($cues)->toBe([]);
});

it('shares ordered legacy animation names with the editor', function () {
  expect(ActionAnimationResolver::getSkillCandidateNames('Cure', MagicEffectType::RESTORATIVE))->toBe(['Cure', 'Healing Aura'])
    ->and(ActionAnimationResolver::getSkillCandidateNames('Flare', MagicEffectType::DESTRUCTIVE))->toBe(['Flare', 'Hit Spark'])
    ->and(ActionAnimationResolver::getSkillCandidateNames('Slash', null))->toBe(['Slash', 'Hit Spark']);
});

it('replaces scene-owned animation assets between battles even when the action state survives', function () {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-battle-animation-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $path = $root . '/assets/Data/animations.php';
  try {
    chdir($root);
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'Before']];");
    $state = (new ReflectionClass(ActionExecutionState::class))->newInstanceWithoutConstructor();
    $resolve = new ReflectionMethod(ActionExecutionState::class, 'resolveActionAnimation');
    $action = new SkillBattleAction(new SpecialSkill('Strike', '', '', 0, 0, animationId: 7));
    BattleCommandCatalog::beginBattle();
    expect($resolve->invoke($state, $action)?->name)->toBe('Before');
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'After']];");
    expect($resolve->invoke($state, $action)?->name)->toBe('Before');
    BattleCommandCatalog::endBattle();
    BattleCommandCatalog::beginBattle();
    expect($resolve->invoke($state, $action)?->name)->toBe('After');
  } finally {
    BattleCommandCatalog::endBattle();
    chdir($previous);
    unlink($path);
    rmdir($root . '/assets/Data'); rmdir($root . '/assets'); rmdir($root);
  }
});

it('keeps an animation library stable for one battle and reloads in the next', function () {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-animation-cache-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $path = $root . '/assets/Data/animations.php';
  try {
    chdir($root);
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'First']];");
    $battle = new AnimationLibrary(cacheForBattle: true);
    expect($battle->findById(7)?->name)->toBe('First');
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'Second']];");
    expect($battle->findById(7)?->name)->toBe('First')
      ->and((new AnimationLibrary(cacheForBattle: true))->findById(7)?->name)->toBe('Second');
  } finally {
    chdir($previous); unlink($path); rmdir($root . '/assets/Data'); rmdir($root . '/assets'); rmdir($root);
  }
});

it('keeps valid animations available when one authored entry is malformed', function () {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-animation-invalid-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $path = $root . '/assets/Data/animations.php';
  try {
    chdir($root);
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'Valid'], ['id' => 8, 'frames' => 'bad'], 'bad entry'];");
    $library = new AnimationLibrary(cacheForBattle: true);
    expect(array_map(static fn(Animation $animation): int => $animation->id, $library->load()))->toBe([7])
      ->and($library->findById(7)?->name)->toBe('Valid');
  } finally {
    chdir($previous);
    unlink($path);
    foreach (glob($root . '/logs/*') ?: [] as $log) { unlink($log); }
    if (is_dir($root . '/logs')) { rmdir($root . '/logs'); }
    rmdir($root . '/assets/Data'); rmdir($root . '/assets'); rmdir($root);
  }
});

it('diagnoses malformed animation files and roots while retaining battle cache and live reload behavior', function () {
  foreach (["<?php return [", "<?php return 'not an array';"] as $source) {
    $previous = getcwd();
    $root = sys_get_temp_dir() . '/ichiloto-animation-root-' . uniqid();
    mkdir($root . '/assets/Data', 0777, true);
    $path = $root . '/assets/Data/animations.php';
    $logDirectory = new ReflectionProperty(Debug::class, 'logDirectory');
    $previousLogDirectory = $logDirectory->getValue();
    try {
      $logDirectory->setValue(null, $root . '/logs');
      chdir($root);
      file_put_contents($path, $source);
      $battle = new AnimationLibrary(cacheForBattle: true);
      $live = new AnimationLibrary();
      expect($battle->load())->toBe([])->and($live->load())->toBe([]);
      expect(file_get_contents($root . '/logs/warning.log'))->toContain('Animation library Data/animations.php');
      file_put_contents($path, "<?php return [['id' => 9, 'name' => 'Recovered']];");
      expect($battle->load())->toBe([])
        ->and($live->findById(9)?->name)->toBe('Recovered');
    } finally {
      $logDirectory->setValue(null, $previousLogDirectory);
      chdir($previous);
      unlink($path);
      foreach (glob($root . '/logs/*') ?: [] as $log) { unlink($log); }
      if (is_dir($root . '/logs')) { rmdir($root . '/logs'); }
      rmdir($root . '/assets/Data'); rmdir($root . '/assets'); rmdir($root);
    }
  }
});
