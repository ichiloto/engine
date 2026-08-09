<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\Backends\AfplayBackend;
use Ichiloto\Engine\Audio\Backends\FfplayBackend;
use Ichiloto\Engine\Audio\Backends\Mpg123Backend;
use Ichiloto\Engine\Audio\Backends\MpvBackend;
use Ichiloto\Engine\Audio\Backends\PaplayBackend;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

/**
 * A ProjectConfig stand-in backed by a plain array.
 */
class AudioArrayConfigStub implements ConfigInterface
{
  public function __construct(private array $values = [])
  {
  }

  public function get(string $path, mixed $default = null): mixed
  {
    $segments = explode('.', $path);
    $value = $this->values;

    foreach ($segments as $segment) {
      if (! is_array($value) || ! array_key_exists($segment, $value)) {
        return $default;
      }

      $value = $value[$segment];
    }

    return $value;
  }

  public function set(string $path, mixed $value): void
  {
    $segments = explode('.', $path);
    $target = &$this->values;

    foreach ($segments as $segment) {
      if (! isset($target[$segment]) || ! is_array($target[$segment])) {
        $target[$segment] = [];
      }

      $target = &$target[$segment];
    }

    $target = $value;
  }

  public function has(string $path): bool
  {
    $sentinel = new stdClass();

    return $this->get($path, $sentinel) !== $sentinel;
  }

  public function persist(): void
  {
  }
}

/**
 * A backend that accepts every file and records nothing platform-specific.
 */
class FakeAudioBackend implements AudioBackendInterface
{
  public function __construct(
    private array $extensions = ['ogg', 'wav', 'mp3'],
    private bool $loopsNatively = true,
    private bool $seekable = false
  )
  {
  }

  public function getExecutableName(): string
  {
    return 'fake-player';
  }

  public function isAvailable(): bool
  {
    return true;
  }

  public function supportsNativeLooping(): bool
  {
    return $this->loopsNatively;
  }

  public function supports(string $filePath): bool
  {
    $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

    return in_array($extension, $this->extensions, true);
  }

  public function supportsSeeking(): bool
  {
    return $this->seekable;
  }

  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
  {
    $command = ['fake-player', sprintf('%.2F', $volume), $loop ? 'loop' : 'once'];

    if ($startAtSeconds > 0) {
      $command[] = sprintf('start=%.2F', $startAtSeconds);
    }

    $command[] = $filePath;

    return $command;
  }
}

/**
 * An AudioManager whose backends are injected and whose spawns are recorded
 * instead of launching real audio players.
 */
class TestableAudioManager extends AudioManager
{
  /**
   * @var array<int, string[]> Every argv list passed to spawn().
   */
  public array $spawnedCommands = [];

  /**
   * @var float|null The track duration reported instead of probing ffprobe.
   */
  public ?float $fakeTrackDuration = null;

  /**
   * Rewinds the current playback's spawn timestamp so elapsed-position logic
   * can be exercised without sleeping in tests.
   */
  public function pretendBgmHasPlayedFor(float $seconds): void
  {
    $this->bgmStartedAt = microtime(true) - $seconds;
  }

  protected function probeTrackDuration(?string $filePath): ?float
  {
    return $this->fakeTrackDuration;
  }

  /**
   * @var AudioPlayback[] Every playback handle returned by spawn().
   */
  public array $spawnedPlaybacks = [];

  /**
   * @param AudioBackendInterface[] $testBackends
   */
  public function __construct(Game $game, protected array $testBackends)
  {
    parent::__construct($game);
  }

  protected function createBackends(): array
  {
    return $this->testBackends;
  }

  protected function spawn(array $command): ?AudioPlayback
  {
    $this->spawnedCommands[] = $command;

    // A real, harmless process so handle bookkeeping is exercised for real.
    $playback = AudioPlayback::start(['sleep', '30']);

    if ($playback !== null) {
      $this->spawnedPlaybacks[] = $playback;
    }

    return $playback;
  }
}

/**
 * Creates a Game instance without running its heavyweight constructor.
 */
function makeAudioTestGame(): Game
{
  return (new ReflectionClass(Game::class))->newInstanceWithoutConstructor();
}

/**
 * Creates a temporary assets tree containing the given audio files and returns
 * its root directory.
 *
 * @param string[] $relativePaths
 */
function makeAudioAssetsRoot(array $relativePaths): string
{
  $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-audio-test-', true);

  foreach ($relativePaths as $relativePath) {
    $absolutePath = $root . DIRECTORY_SEPARATOR . $relativePath;

    if (! is_dir(dirname($absolutePath))) {
      mkdir(dirname($absolutePath), 0777, true);
    }

    file_put_contents($absolutePath, 'stub');
  }

  return $root;
}

function putAudioProjectConfig(array $values): void
{
  ConfigStore::put(ProjectConfig::class, new AudioArrayConfigStub($values));
}

afterEach(function () {
  putAudioProjectConfig([]);
});

/* Backend command building */

it('builds an mpv command with volume and native looping', function () {
  $backend = new MpvBackend();

  expect($backend->buildCommand('/tmp/theme.ogg', 0.75, true))
    ->toBe(['mpv', '--no-video', '--no-terminal', '--really-quiet', '--volume=75', '--loop-file=inf', '--', '/tmp/theme.ogg'])
    ->and($backend->buildCommand('/tmp/hit.wav', 1.0, false))
    ->toBe(['mpv', '--no-video', '--no-terminal', '--really-quiet', '--volume=100', '--', '/tmp/hit.wav']);
});

it('builds an ffplay command with volume and native looping', function () {
  $backend = new FfplayBackend();

  expect($backend->buildCommand('/tmp/theme.ogg', 0.5, true))
    ->toBe(['ffplay', '-nodisp', '-autoexit', '-loglevel', 'quiet', '-volume', '50', '-loop', '0', '/tmp/theme.ogg']);
});

it('builds a paplay command with pulse volume units', function () {
  $backend = new PaplayBackend();

  expect($backend->buildCommand('/tmp/hit.wav', 0.5, false))
    ->toBe(['paplay', '--volume=32768', '/tmp/hit.wav']);
});

it('builds an afplay command with normalized volume', function () {
  $backend = new AfplayBackend();

  expect($backend->buildCommand('/tmp/hit.wav', 0.25, false))
    ->toBe(['afplay', '-v', '0.25', '/tmp/hit.wav']);
});

it('builds an mpg123 command with scale-factor volume and looping', function () {
  $backend = new Mpg123Backend();

  expect($backend->buildCommand('/tmp/theme.mp3', 1.0, true))
    ->toBe(['mpg123', '-q', '-f', '32768', '--loop', '-1', '/tmp/theme.mp3']);
});

it('clamps out-of-range volumes when building commands', function () {
  $backend = new MpvBackend();

  expect($backend->buildCommand('/tmp/a.ogg', 1.7, false)[4])->toBe('--volume=100')
    ->and($backend->buildCommand('/tmp/a.ogg', -0.3, false)[4])->toBe('--volume=0');
});

it('reports format support from the file extension', function () {
  $mpg123 = new Mpg123Backend();
  $paplay = new PaplayBackend();

  expect($mpg123->supports('/tmp/theme.MP3'))->toBeTrue()
    ->and($mpg123->supports('/tmp/theme.ogg'))->toBeFalse()
    ->and($paplay->supports('/tmp/theme.ogg'))->toBeTrue()
    ->and($paplay->supports('/tmp/theme.mp3'))->toBeFalse();
});

/* AudioPlayback process control */

it('tracks and terminates a spawned process', function () {
  $playback = AudioPlayback::start(['sleep', '30']);

  expect($playback)->not->toBeNull()
    ->and($playback->isRunning)->toBeTrue();

  $playback->stop();

  expect($playback->isRunning)->toBeFalse();

  // stop() must be idempotent.
  $playback->stop();
});

it('tracks the process ID of a spawned player', function () {
  $playback = AudioPlayback::start(['sleep', '30']);

  expect($playback)->not->toBeNull()
    ->and($playback->pid)->toBeInt()
    ->and($playback->pid)->toBeGreaterThan(0);

  if (function_exists('posix_kill')) {
    expect(posix_kill($playback->pid, 0))->toBeTrue();
  }

  $playback->stop();

  if (function_exists('posix_kill')) {
    // stop() reaps the child, so its pid must no longer exist.
    expect(posix_kill($playback->pid, 0))->toBeFalse();
  }
});

it('reports a finished process as not running', function () {
  $playback = AudioPlayback::start(['true']);

  expect($playback)->not->toBeNull();

  // Give the trivial process a moment to exit.
  usleep(50_000);

  expect($playback->isRunning)->toBeFalse();

  $playback->stop();
});

/* AudioManager behavior */

it('plays a sound effect when sound effects are enabled', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 50]]);
  $root = makeAudioAssetsRoot(['hit.wav']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playSoundEffect("$root/hit.wav");

  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedCommands[0])->toBe(['fake-player', '0.50', 'once', "$root/hit.wav"]);

  $manager->shutdown();
});

it('does not play sound effects when they are disabled', function () {
  putAudioProjectConfig(['audio' => ['sfx' => false]]);
  $root = makeAudioAssetsRoot(['hit.wav']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playSoundEffect("$root/hit.wav");

  expect($manager->spawnedCommands)->toBeEmpty();

  $manager->shutdown();
});

it('caps the number of concurrent sound effects', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true]]);
  $root = makeAudioAssetsRoot(['hit.wav']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);

  for ($i = 0; $i < 12; $i++) {
    $manager->playSoundEffect("$root/hit.wav");
  }

  expect($manager->spawnedCommands)->toHaveCount(8);

  $manager->shutdown();
});

it('starts looping background music when music is enabled', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'master_volume' => 100]]);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");

  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedCommands[0])->toBe(['fake-player', '1.00', 'loop', "$root/theme.ogg"]);

  // Requesting the same track again must not restart it.
  $manager->playBackgroundMusic("$root/theme.ogg");

  expect($manager->spawnedCommands)->toHaveCount(1);

  $manager->shutdown();
});

it('keeps a track pending while music is disabled and starts it once enabled', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => false]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");

  expect($manager->spawnedCommands)->toBeEmpty();

  $config->set('audio.music', true);
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(1);

  $manager->shutdown();
});

it('stops background music when music is disabled mid-play', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");

  expect($manager->spawnedCommands)->toHaveCount(1);

  $config->set('audio.music', false);
  $manager->update();

  // Re-enabling resumes the pending track.
  $config->set('audio.music', true);
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(2);

  $manager->shutdown();
});

it('defers a volume change on backends that cannot seek instead of restarting the track', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: false, seekable: false)]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(1);

  $config->set('audio.master_volume', 40);
  $manager->update();
  $manager->update();

  // The running track is left untouched; the new volume applies on the next
  // natural respawn.
  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedPlaybacks[0]->isRunning)->toBeTrue();

  $manager->shutdown();
});

it('resumes at the current track position when the volume changes on a seek-capable backend', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: false, seekable: true)]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->update();
  $manager->pretendBgmHasPlayedFor(42.0);

  $config->set('audio.master_volume', 40);
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(2)
    ->and($manager->spawnedCommands[1][1])->toBe('0.40')
    ->and($manager->spawnedCommands[1][3])->toStartWith('start=42');

  $manager->shutdown();
});

it('folds the resume position back into the track for natively looping players', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: true, seekable: true)]);
  $manager->fakeTrackDuration = 60.0;
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->update();
  $manager->pretendBgmHasPlayedFor(150.0);

  $config->set('audio.master_volume', 40);
  $manager->update();

  // 150s into a 60s loop is 30s into the current iteration.
  expect($manager->spawnedCommands)->toHaveCount(2)
    ->and($manager->spawnedCommands[1][3])->toStartWith('start=30');

  $manager->shutdown();
});

it('defers a volume change for natively looping players when the track duration is unknown', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: true, seekable: true)]);
  $manager->fakeTrackDuration = null;
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->update();
  $manager->pretendBgmHasPlayedFor(150.0);

  $config->set('audio.master_volume', 40);
  $manager->update();
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedPlaybacks[0]->isRunning)->toBeTrue();

  $manager->shutdown();
});

it('resolves tracks from the conventional assets/Audio directories without an extension', function () {
  putAudioProjectConfig(['audio' => ['music' => true]]);
  $root = makeAudioAssetsRoot(['assets/Audio/BGM/overworld.ogg']);
  $originalCwd = getcwd();
  chdir($root);

  try {
    $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
    $manager->playBackgroundMusic('overworld');

    expect($manager->spawnedCommands)->toHaveCount(1)
      ->and($manager->spawnedCommands[0][3])->toEndWith('overworld.ogg');

    $manager->shutdown();
  } finally {
    chdir($originalCwd);
  }
});

it('silently ignores playback requests when no player is available', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'sfx' => true]]);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), []);

  expect($manager->isSupported)->toBeFalse();

  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->playSoundEffect("$root/theme.ogg");
  $manager->update();

  expect($manager->spawnedCommands)->toBeEmpty();

  $manager->shutdown();
});

it('silently ignores missing audio files', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'sfx' => true]]);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic('does-not-exist');
  $manager->playSoundEffect('does-not-exist');
  $manager->update();

  expect($manager->spawnedCommands)->toBeEmpty();

  $manager->shutdown();
});

it('terminates every tracked player process on shutdown', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'sfx' => true]]);
  $root = makeAudioAssetsRoot(['theme.ogg', 'hit.wav']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->playSoundEffect("$root/hit.wav");
  $manager->playSoundEffect("$root/hit.wav");

  expect($manager->spawnedPlaybacks)->toHaveCount(3);

  foreach ($manager->spawnedPlaybacks as $playback) {
    expect($playback->isRunning)->toBeTrue();
  }

  $manager->shutdown();

  foreach ($manager->spawnedPlaybacks as $playback) {
    expect($playback->isRunning)->toBeFalse();

    if (function_exists('posix_kill')) {
      expect(posix_kill($playback->pid, 0))->toBeFalse();
    }
  }
});

it('terminates a replaced background music player when the track switches', function () {
  putAudioProjectConfig(['audio' => ['music' => true]]);
  $root = makeAudioAssetsRoot(['theme.ogg', 'battle.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->playBackgroundMusic("$root/battle.ogg");

  expect($manager->spawnedPlaybacks)->toHaveCount(2)
    ->and($manager->spawnedPlaybacks[0]->isRunning)->toBeFalse()
    ->and($manager->spawnedPlaybacks[1]->isRunning)->toBeTrue();

  $manager->shutdown();
});

it('stops the players of every constructed manager via shutdownAll', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'sfx' => true]]);
  $root = makeAudioAssetsRoot(['theme.ogg', 'hit.wav']);

  $first = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $second = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $first->playBackgroundMusic("$root/theme.ogg");
  $second->playSoundEffect("$root/hit.wav");

  AudioManager::shutdownAll();

  foreach ([...$first->spawnedPlaybacks, ...$second->spawnedPlaybacks] as $playback) {
    expect($playback->isRunning)->toBeFalse();
  }
});

it('skips files no available backend can decode', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true]]);
  $root = makeAudioAssetsRoot(['hit.mid']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(['ogg', 'wav'])]);
  $manager->playSoundEffect("$root/hit.mid");

  expect($manager->spawnedCommands)->toBeEmpty();

  $manager->shutdown();
});

/* System sounds */

it('plays a system sound through its configured track', function () {
  $root = makeAudioAssetsRoot(['level-up.wav']);
  putAudioProjectConfig([
    'audio' => [
      'sfx' => true,
      'master_volume' => 50,
      'sounds' => [
        'level_up' => "$root/level-up.wav",
      ],
    ],
  ]);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playSystemSound(SystemSound::LEVEL_UP);

  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedCommands[0])->toBe(['fake-player', '0.50', 'once', "$root/level-up.wav"]);

  $manager->shutdown();
});

it('stays silent for a system sound the project has not configured', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 50]]);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playSystemSound(SystemSound::NOTIFICATION);

  expect($manager->spawnedCommands)->toBe([]);

  $manager->shutdown();
});
