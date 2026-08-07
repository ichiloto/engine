<?php

use Ichiloto\Engine\Audio\AudioManager;
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
    private bool $loopsNatively = true
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

  public function buildCommand(string $filePath, float $volume, bool $loop): array
  {
    return ['fake-player', sprintf('%.2F', $volume), $loop ? 'loop' : 'once', $filePath];
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
    return AudioPlayback::start(['sleep', '30']);
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
    mkdir(dirname($absolutePath), 0777, true);
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

it('restarts background music when the master volume changes', function () {
  $config = new AudioArrayConfigStub(['audio' => ['music' => true, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(1);

  $config->set('audio.master_volume', 40);
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(2)
    ->and($manager->spawnedCommands[1][1])->toBe('0.40');

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

it('skips files no available backend can decode', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true]]);
  $root = makeAudioAssetsRoot(['hit.mid']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(['ogg', 'wav'])]);
  $manager->playSoundEffect("$root/hit.mid");

  expect($manager->spawnedCommands)->toBeEmpty();

  $manager->shutdown();
});
