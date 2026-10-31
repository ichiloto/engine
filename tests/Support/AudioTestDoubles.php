<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use Tests\Support\PassiveGame;

/*
 * Audio test doubles shared by every test that drives a real AudioManager.
 * Nothing here plays sound: spawns run a harmless stand-in process.
 */

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
   * @var string[] The silent stand-in each spawn runs: a long-lived player by
   *   default; `true` or `false` stand for a player that ends or fails.
   */
  public array $playerCommand = ['sleep', '30'];

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
    $playback = AudioPlayback::start($this->playerCommand);

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
  return new PassiveGame();
}

/**
 * Creates a temporary assets tree containing the given audio files and returns
 * its root directory.
 *
 * @param string[] $relativePaths
 */
function makeAudioAssetsRoot(array $relativePaths): string
{
  $root = createTestDirectory('ichiloto-audio-test-');

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

/**
 * A manager whose project binds a synthetic counter tick (`tick.wav`) and,
 * when asked, a synthetic EXP tally (`tally.wav`), with the given audio
 * settings. `$backends` stands in for the players a host has installed; by
 * default one fake player that loops natively.
 *
 * @param AudioBackendInterface[]|null $backends
 */
function makeCounterTickManager(array $audio = ['sfx' => true, 'master_volume' => 100], bool $bindsExpTally = false,
  ?array $backends = null): TestableAudioManager
{
  $root = makeAudioAssetsRoot(['tick.wav', 'tally.wav']);
  $sounds = ['counter' => "$root/tick.wav", ...($bindsExpTally ? ['exp_tally' => "$root/tally.wav"] : [])];
  putAudioProjectConfig(['audio' => [...$audio, 'sounds' => [...$sounds, ...($audio['sounds'] ?? [])]]]);
  return new TestableAudioManager(makeAudioTestGame(), $backends ?? [new FakeAudioBackend()]);
}

/**
 * The file names each spawned player was asked to play, in spawn order.
 *
 * @return list<string>
 */
function listSpawnedSoundFiles(TestableAudioManager $manager): array
{
  return array_map(static fn(array $command): string => basename((string)end($command)), $manager->spawnedCommands);
}
