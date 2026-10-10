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
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
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

it('does not manufacture a legacy delay for timeline-only animation bindings', function () {
  $binding = Animation::fromArray(['id' => 1, 'name' => 'Effect binding', 'targetEffect' => 'target']);
  expect($binding->hasLegacyPresentation)->toBeFalse();
  $binding->setCell(1, 0, 0, '*');
  expect($binding->hasLegacyPresentation)->toBeTrue();
  $binding->setCell(1, 0, 0, ' ');
  expect($binding->hasLegacyPresentation)->toBeFalse();
  $binding->setCue(1, new AnimationCue(soundEffect: 'Cue'));
  expect($binding->hasLegacyPresentation)->toBeTrue()
    ->and(new Animation(2, 'Authored blank holds', maxFrames: 2)->hasLegacyPresentation)->toBeTrue();
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

it('preserves exact legacy cadence through the shared effect clock without rounding to integer fps', function (float $seconds) {
  $animation = new Animation(18, 'Legacy cadence', maxFrames: 6);
  $session = new AnimationPlaybackSession($animation, $seconds);
  expect($session->playback)->toBeInstanceOf(\Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession::class)
    ->and($session->playback->secondsPerFrame)->toBe($seconds)
    ->and($session->currentFrame)->toBe(1);
  for ($frame = 2; $frame <= 6; $frame++) {
    expect($session->update($seconds))->toBe([$frame])
      ->and($session->currentFrame)->toBe($frame)->and($session->isComplete)->toBeFalse();
  }
  expect($session->update($seconds))->toBe([])->and($session->isComplete)->toBeTrue()
    ->and($session->currentFrame)->toBe(6)->and($session->update(1000))->toBe([]);
})->with([.01, .12, .173, 300.0]);

it('keeps blank legacy frame slots and crosses every entered frame after a delayed update', function () {
  $animation = new Animation(19, 'Blank slots', maxFrames: 6);
  $animation->setCell(1, 0, 0, '*');
  $animation->setCell(6, 0, 0, '+');
  $animation->setCue(5, new AnimationCue(soundEffect: 'late'));
  $session = new AnimationPlaybackSession($animation, .12);
  expect($session->update(.36))->toBe([2, 3, 4])
    ->and($animation->getFrame($session->currentFrame)->getCells())->toBeEmpty()
    ->and($session->update(.36))->toBe([5, 6])
    ->and($session->isComplete)->toBeTrue()
    ->and($animation->getCue(5)?->soundEffect)->toBe('late');
});

it('shares legacy pause and cancellation with the effect playhead without resuming abandoned playback', function () {
  $session = new AnimationPlaybackSession(new Animation(20, 'Cancellation', maxFrames: 3), .12);
  $session->playback->pause();
  expect($session->update(30))->toBe([])->and($session->currentFrame)->toBe(1);
  $session->playback->resume();
  expect($session->update(.12))->toBe([2]);
  $session->cancel();
  $session->playback->resume();
  expect($session->isComplete)->toBeTrue()->and($session->update(30))->toBe([])
    ->and($session->currentFrame)->toBe(2);
});

it('rejects non-finite legacy cadence and elapsed time instead of hanging a compatibility consumer', function (float $value) {
  $animation = new Animation(21, 'Invalid time', maxFrames: 2);
  expect(fn() => new AnimationPlaybackSession($animation, $value))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new AnimationPlaybackSession($animation)->update($value))->toThrow(InvalidArgumentException::class);
})->with([INF, -INF, NAN]);

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

it('selects semantic defaults without depending on skill display names', function () {
  $magic = static fn(MagicEffectType $type): MagicSkill => new MagicSkill('Renamed magic', '', '', 0, 0, effectType: $type);
  expect(ActionAnimationResolver::getSkillRole($magic(MagicEffectType::RESTORATIVE)))->toBe('restorative')
    ->and(ActionAnimationResolver::getSkillRole($magic(MagicEffectType::BUFF)))->toBe('restorative')
    ->and(ActionAnimationResolver::getSkillRole($magic(MagicEffectType::DESTRUCTIVE)))->toBe('skill')
    ->and(ActionAnimationResolver::getSkillRole(new SpecialSkill('Renamed technique', '', '', 0, 0)))->toBe('skill')
    ->and(ActionAnimationResolver::getSkillRole(new BasicSkill('Renamed strike', '', '', 0, 0)))->toBe('attack');
  $animation = Animation::fromArray(['id' => 8, 'name' => 'Renamed', 'roles' => ['attack', 'skill']]);
  expect($animation->roles)->toBe(['attack', 'skill'])
    ->and(Animation::fromArray($animation->toArray())->roles)->toBe(['attack', 'skill']);
});

it('refuses invalid semantic animation role declarations', function ($roles) {
  expect(fn() => new Animation(8, 'Role test', roles: $roles))->toThrow(InvalidArgumentException::class);
})->with([
  'unknown' => [['invented']], 'not a list' => [['attack' => 'attack']],
  'duplicate' => [['attack', 'attack']], 'not a string' => [[3]],
]);

it('refuses an explicit null role list and snapshots external role references', function () {
  expect(fn() => Animation::fromArray(['id' => 8, 'roles' => null]))->toThrow(InvalidArgumentException::class);
  $role = 'attack';
  $animation = new Animation(8, 'Snapshot', roles: [&$role]);
  $role = 'invalid';
  expect($animation->roles)->toBe(['attack']);
});

it('refuses ambiguous or missing role defaults without hiding unrelated animations', function () {
  $previous = getcwd();
  $root = sys_get_temp_dir() . '/ichiloto-animation-roles-' . uniqid();
  mkdir($root . '/assets/Data', 0777, true);
  $logDirectory = new ReflectionProperty(Debug::class, 'logDirectory');
  $previousLogDirectory = $logDirectory->getValue();
  try {
    $logDirectory->setValue(null, $root . '/logs');
    chdir($root);
    file_put_contents($root . '/assets/Data/animations.php', "<?php return [
      ['id' => 1, 'name' => 'First', 'roles' => ['attack']],
      ['id' => 2, 'name' => 'Second', 'roles' => ['attack']],
      ['id' => 3, 'name' => 'Anything', 'roles' => ['restorative']]];");
    $library = new AnimationLibrary(cacheForBattle: true);
    expect($library->findByRole('attack'))->toBeNull()
      ->and($library->findByRole('skill'))->toBeNull()
      ->and($library->findByRole('restorative')?->id)->toBe(3)
      ->and($library->findById(1)?->id)->toBe(1);
    $log = file_get_contents($root . '/logs/warning.log');
    expect($log)->toContain('Animation role attack', 'found 2', 'Animation role skill', 'found 0', 'No name fallback');
    $library->findByRole('attack');
    expect(file_get_contents($root . '/logs/warning.log'))->toBe($log);
  } finally {
    chdir($previous);
    $logDirectory->setValue(null, $previousLogDirectory);
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
  }
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
    $actor = new \Ichiloto\Engine\Entities\Character('Actor', 1, new \Ichiloto\Engine\Entities\Stats());
    BattleCommandCatalog::beginBattle();
    expect($resolve->invoke($state, $action, $actor)?->name)->toBe('Before');
    file_put_contents($path, "<?php return [['id' => 7, 'name' => 'After']];");
    expect($resolve->invoke($state, $action, $actor)?->name)->toBe('Before');
    BattleCommandCatalog::endBattle();
    BattleCommandCatalog::beginBattle();
    expect($resolve->invoke($state, $action, $actor)?->name)->toBe('After');
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
