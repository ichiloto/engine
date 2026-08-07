<?php

namespace Ichiloto\Engine\Audio;

use Assegai\Util\Path;
use Ichiloto\Engine\Audio\Backends\AfplayBackend;
use Ichiloto\Engine\Audio\Backends\AplayBackend;
use Ichiloto\Engine\Audio\Backends\FfplayBackend;
use Ichiloto\Engine\Audio\Backends\Mpg123Backend;
use Ichiloto\Engine\Audio\Backends\MpvBackend;
use Ichiloto\Engine\Audio\Backends\PaplayBackend;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\Interfaces\CanUpdate;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

/**
 * AudioManager plays background music and sound effects.
 *
 * Playback is delegated to whichever command line audio player is installed on
 * the host system (mpv, ffplay, paplay, mpg123, aplay on Linux and WSL; afplay
 * on macOS). Audio is strictly optional: when no player is installed, or a
 * file or format cannot be handled, every call degrades to a silent no-op so
 * games remain fully playable without sound.
 *
 * The manager honours the project audio settings exposed in the title options
 * menu (`audio.music`, `audio.sfx` and `audio.master_volume`) live: toggling
 * music off stops the current track, toggling it back on resumes it, and
 * volume changes restart the track at the new volume.
 *
 * Background music loops by default. Backends that cannot loop natively are
 * respawned by update() whenever the track ends, which is why update() must be
 * pumped from the game loop.
 *
 * @package Ichiloto\Engine\Audio
 */
class AudioManager implements CanUpdate
{
  /**
   * The project config path of the music toggle.
   */
  protected const string CONFIG_MUSIC_ENABLED = 'audio.music';
  /**
   * The project config path of the sound effect toggle.
   */
  protected const string CONFIG_SFX_ENABLED = 'audio.sfx';
  /**
   * The project config path of the master volume (0-100).
   */
  protected const string CONFIG_MASTER_VOLUME = 'audio.master_volume';
  /**
   * The master volume assumed when the project config does not specify one.
   */
  protected const int DEFAULT_MASTER_VOLUME = 75;
  /**
   * The maximum number of simultaneously playing sound effects. Requests
   * beyond the cap are dropped to avoid spawning a process storm.
   */
  protected const int MAX_CONCURRENT_SOUND_EFFECTS = 8;
  /**
   * How long a background music process must survive for its exit to be
   * treated as a normal end-of-track rather than a startup failure.
   */
  protected const float MIN_STABLE_PLAYBACK_SECONDS = 1.0;
  /**
   * How many rapid consecutive playback failures are tolerated before the
   * manager gives up on the current background music track.
   */
  protected const int MAX_RAPID_PLAYBACK_FAILURES = 3;
  /**
   * The conventional background music directory under assets/Audio.
   */
  protected const string BGM_DIRECTORY = 'BGM';
  /**
   * The conventional sound effect directory under assets/Audio.
   */
  protected const string SFX_DIRECTORY = 'SFX';
  /**
   * The extensions tried, in order, when a track is referenced without one.
   */
  protected const array GUESSABLE_EXTENSIONS = ['ogg', 'wav', 'mp3', 'flac', 'm4a', 'opus', 'aiff'];

  /**
   * The instance of this singleton.
   *
   * @var AudioManager|null
   */
  protected static ?AudioManager $instance = null;

  /**
   * The available playback backends, in order of preference.
   *
   * @var AudioBackendInterface[]
   */
  protected array $backends;

  /**
   * The handle of the running background music process, if any.
   *
   * @var AudioPlayback|null
   */
  protected ?AudioPlayback $bgmPlayback = null;

  /**
   * The resolved path of the current background music track. Remains set while
   * music is disabled in the options so re-enabling music resumes the track.
   *
   * @var string|null
   */
  protected ?string $bgmPath = null;

  /**
   * Whether the current background music track should loop.
   *
   * @var bool
   */
  protected bool $bgmLoops = true;

  /**
   * The normalized volume the current background music was spawned with.
   *
   * @var float
   */
  protected float $bgmVolume = 0.0;

  /**
   * When the current background music process was spawned.
   *
   * @var float
   */
  protected float $bgmStartedAt = 0.0;

  /**
   * How many times in a row background music playback died shortly after
   * spawning.
   *
   * @var int
   */
  protected int $bgmRapidFailureCount = 0;

  /**
   * The handles of running sound effect processes.
   *
   * @var AudioPlayback[]
   */
  protected array $sfxPlaybacks = [];

  /**
   * The warning keys that have already been logged, to avoid log spam from
   * per-frame or repeated calls.
   *
   * @var array<string, true>
   */
  protected array $loggedWarnings = [];

  /**
   * Whether at least one audio player is available on this system.
   *
   * @var bool
   */
  public bool $isSupported {
    get {
      return $this->backends !== [];
    }
  }

  /**
   * AudioManager constructor.
   *
   * @param Game $game The game.
   */
  protected function __construct(protected Game $game)
  {
    $this->backends = $this->createBackends();
  }

  /**
   * Returns the instance of the audio manager.
   *
   * @param Game $game The game.
   * @return AudioManager The audio manager.
   */
  public static function getInstance(Game $game): AudioManager
  {
    if (self::$instance === null) {
      self::$instance = new AudioManager($game);
    }

    return self::$instance;
  }

  /**
   * Plays the given background music track, replacing the current one.
   *
   * The path may be absolute, relative to the assets directory, or relative to
   * the conventional assets/Audio/BGM directory; the file extension may be
   * omitted. Calling this with the track that is already playing is a no-op.
   * When music is disabled in the options the track is recorded as pending and
   * starts as soon as music is re-enabled.
   *
   * @param string $path The path of the track.
   * @param bool $loop Whether the track should loop. Defaults to true.
   * @return void
   */
  public function playBackgroundMusic(string $path, bool $loop = true): void
  {
    if (! $this->isSupported) {
      $this->warnOnce('unsupported', 'No supported audio player found. Audio is disabled.');
      return;
    }

    $resolvedPath = $this->resolveAudioPath($path, self::BGM_DIRECTORY);

    if ($resolvedPath === null) {
      $this->warnOnce("bgm-missing:$path", "Background music file not found: $path");
      return;
    }

    if ($resolvedPath === $this->bgmPath && $this->bgmPlayback?->isRunning) {
      return;
    }

    $this->stopBgmPlayback();
    $this->bgmPath = $resolvedPath;
    $this->bgmLoops = $loop;
    $this->bgmRapidFailureCount = 0;

    if ($this->isMusicEnabled()) {
      $this->startBgmPlayback();
    }
  }

  /**
   * Stops the current background music track, if any.
   *
   * @return void
   */
  public function stopBackgroundMusic(): void
  {
    $this->bgmPath = null;
    $this->stopBgmPlayback();
  }

  /**
   * Plays the given sound effect once, without blocking the game loop.
   *
   * The path may be absolute, relative to the assets directory, or relative to
   * the conventional assets/Audio/SFX directory; the file extension may be
   * omitted.
   *
   * @param string $path The path of the sound effect.
   * @return void
   */
  public function playSoundEffect(string $path): void
  {
    if (! $this->isSupported || ! $this->areSoundEffectsEnabled()) {
      return;
    }

    $resolvedPath = $this->resolveAudioPath($path, self::SFX_DIRECTORY);

    if ($resolvedPath === null) {
      $this->warnOnce("sfx-missing:$path", "Sound effect file not found: $path");
      return;
    }

    $this->reapFinishedSoundEffects();

    if (count($this->sfxPlaybacks) >= self::MAX_CONCURRENT_SOUND_EFFECTS) {
      return;
    }

    $backend = $this->selectBackend($resolvedPath);

    if ($backend === null) {
      $this->warnOnce("sfx-format:$resolvedPath", "No available audio player supports: $resolvedPath");
      return;
    }

    $playback = $this->spawn($backend->buildCommand($resolvedPath, $this->getMasterVolume(), false));

    if ($playback !== null) {
      $this->sfxPlaybacks[] = $playback;
    }
  }

  /**
   * @inheritDoc
   */
  public function update(): void
  {
    $this->reapFinishedSoundEffects();
    $this->updateBackgroundMusic();
  }

  /**
   * Stops all audio playback and releases every process handle.
   *
   * @return void
   */
  public function shutdown(): void
  {
    $this->stopBackgroundMusic();

    foreach ($this->sfxPlaybacks as $playback) {
      $playback->stop();
    }

    $this->sfxPlaybacks = [];
  }

  /**
   * Creates the playback backends available on this system, in order of
   * preference for the current platform.
   *
   * @return AudioBackendInterface[] The available backends.
   */
  protected function createBackends(): array
  {
    $candidates = PHP_OS_FAMILY === 'Darwin'
      ? [new AfplayBackend(), new MpvBackend(), new FfplayBackend()]
      : [new MpvBackend(), new FfplayBackend(), new PaplayBackend(), new Mpg123Backend(), new AplayBackend()];

    return array_values(array_filter(
      $candidates,
      fn(AudioBackendInterface $backend) => $backend->isAvailable()
    ));
  }

  /**
   * Spawns an audio player process for the given argv list.
   *
   * @param string[] $command The argv list, executable first.
   * @return AudioPlayback|null The playback handle, or null when the process
   *   could not be started.
   */
  protected function spawn(array $command): ?AudioPlayback
  {
    return AudioPlayback::start($command);
  }

  /**
   * Keeps background music in sync with the project settings and emulates
   * looping for backends that cannot loop natively.
   *
   * @return void
   */
  protected function updateBackgroundMusic(): void
  {
    if ($this->bgmPath === null) {
      return;
    }

    if (! $this->isMusicEnabled()) {
      // Music was disabled in the options. Keep the track pending so that
      // re-enabling music resumes it.
      $this->stopBgmPlayback();
      return;
    }

    if ($this->bgmPlayback?->isRunning) {
      $currentVolume = $this->getMasterVolume();

      if (abs($currentVolume - $this->bgmVolume) > PHP_FLOAT_EPSILON) {
        // The player process volume is fixed at spawn time, so a master
        // volume change requires restarting the track.
        $this->stopBgmPlayback();
        $this->startBgmPlayback();
      }

      return;
    }

    if ($this->bgmPlayback !== null) {
      // The process ended on its own.
      $ranFor = microtime(true) - $this->bgmStartedAt;
      $this->stopBgmPlayback();

      if (! $this->bgmLoops) {
        $this->bgmPath = null;
        return;
      }

      if ($ranFor < self::MIN_STABLE_PLAYBACK_SECONDS) {
        $this->bgmRapidFailureCount++;

        if ($this->bgmRapidFailureCount >= self::MAX_RAPID_PLAYBACK_FAILURES) {
          $this->warnOnce(
            "bgm-failing:$this->bgmPath",
            "Giving up on background music after repeated playback failures: $this->bgmPath"
          );
          $this->bgmPath = null;
          return;
        }
      } else {
        $this->bgmRapidFailureCount = 0;
      }
    }

    $this->startBgmPlayback();
  }

  /**
   * Spawns the background music player for the current track.
   *
   * @return void
   */
  protected function startBgmPlayback(): void
  {
    if ($this->bgmPath === null) {
      return;
    }

    $backend = $this->selectBackend($this->bgmPath);

    if ($backend === null) {
      $this->warnOnce("bgm-format:$this->bgmPath", "No available audio player supports: $this->bgmPath");
      $this->bgmPath = null;
      return;
    }

    $volume = $this->getMasterVolume();
    $loopNatively = $this->bgmLoops && $backend->supportsNativeLooping();
    $playback = $this->spawn($backend->buildCommand($this->bgmPath, $volume, $loopNatively));

    if ($playback === null) {
      $this->warnOnce("bgm-spawn:$this->bgmPath", "Failed to start the audio player for: $this->bgmPath");
      $this->bgmPath = null;
      return;
    }

    $this->bgmPlayback = $playback;
    $this->bgmVolume = $volume;
    $this->bgmStartedAt = microtime(true);
  }

  /**
   * Stops and releases the background music process, if any. The current
   * track path is left untouched.
   *
   * @return void
   */
  protected function stopBgmPlayback(): void
  {
    $this->bgmPlayback?->stop();
    $this->bgmPlayback = null;
  }

  /**
   * Releases the handles of sound effects that have finished playing.
   *
   * @return void
   */
  protected function reapFinishedSoundEffects(): void
  {
    if ($this->sfxPlaybacks === []) {
      return;
    }

    foreach ($this->sfxPlaybacks as $index => $playback) {
      if (! $playback->isRunning) {
        $playback->stop();
        unset($this->sfxPlaybacks[$index]);
      }
    }

    $this->sfxPlaybacks = array_values($this->sfxPlaybacks);
  }

  /**
   * Selects the first available backend that supports the given file.
   *
   * @param string $filePath The path of the audio file.
   * @return AudioBackendInterface|null The backend, or null when no available
   *   backend supports the file format.
   */
  protected function selectBackend(string $filePath): ?AudioBackendInterface
  {
    foreach ($this->backends as $backend) {
      if ($backend->supports($filePath)) {
        return $backend;
      }
    }

    return null;
  }

  /**
   * Resolves an audio reference to an existing file.
   *
   * @param string $path The audio reference.
   * @param string $conventionalDirectory The conventional directory under
   *   assets/Audio to search, e.g. "BGM" or "SFX".
   * @return string|null The resolved path, or null when no candidate exists.
   */
  protected function resolveAudioPath(string $path, string $conventionalDirectory): ?string
  {
    foreach ($this->getCandidatePaths($path, $conventionalDirectory) as $candidate) {
      if (is_file($candidate)) {
        return $candidate;
      }
    }

    return null;
  }

  /**
   * Builds the candidate filenames for an audio reference.
   *
   * @param string $path The audio reference.
   * @param string $conventionalDirectory The conventional directory under
   *   assets/Audio to search.
   * @return string[] The candidate filenames, in resolution order.
   */
  protected function getCandidatePaths(string $path, string $conventionalDirectory): array
  {
    if (Path::isAbsolute($path)) {
      $basePaths = [$path];
    } else {
      $assetsPath = Path::join(Path::getCurrentWorkingDirectory(), 'assets');
      $basePaths = [
        Path::join($assetsPath, $path),
        Path::join($assetsPath, 'Audio', $conventionalDirectory, $path),
      ];
    }

    $hasExtension = pathinfo($path, PATHINFO_EXTENSION) !== '';
    $candidates = [];

    foreach ($basePaths as $basePath) {
      $candidates[] = $basePath;

      if (! $hasExtension) {
        foreach (self::GUESSABLE_EXTENSIONS as $extension) {
          $candidates[] = "$basePath.$extension";
        }
      }
    }

    return $candidates;
  }

  /**
   * Whether background music is enabled in the project settings.
   *
   * @return bool True when music is enabled.
   */
  protected function isMusicEnabled(): bool
  {
    return boolval($this->getProjectSetting(self::CONFIG_MUSIC_ENABLED, false));
  }

  /**
   * Whether sound effects are enabled in the project settings.
   *
   * @return bool True when sound effects are enabled.
   */
  protected function areSoundEffectsEnabled(): bool
  {
    return boolval($this->getProjectSetting(self::CONFIG_SFX_ENABLED, false));
  }

  /**
   * Returns the normalized master volume.
   *
   * @return float The master volume between 0.0 and 1.0.
   */
  protected function getMasterVolume(): float
  {
    $volume = intval($this->getProjectSetting(self::CONFIG_MASTER_VOLUME, self::DEFAULT_MASTER_VOLUME));

    return clamp($volume, 0, 100) / 100;
  }

  /**
   * Reads a project setting, falling back to the default when the project
   * config is not registered (e.g. in unit tests or tooling contexts).
   *
   * @param string $path The config path.
   * @param mixed $default The default value.
   * @return mixed The setting value.
   */
  protected function getProjectSetting(string $path, mixed $default): mixed
  {
    if (ConfigStore::doesntHave(ProjectConfig::class)) {
      return $default;
    }

    return ConfigStore::get(ProjectConfig::class)->get($path, $default);
  }

  /**
   * Logs a warning once per key, so repeated or per-frame calls cannot spam
   * the debug log.
   *
   * @param string $key The deduplication key.
   * @param string $message The warning message.
   * @return void
   */
  protected function warnOnce(string $key, string $message): void
  {
    if (isset($this->loggedWarnings[$key])) {
      return;
    }

    $this->loggedWarnings[$key] = true;
    Debug::warn($message);
  }
}
