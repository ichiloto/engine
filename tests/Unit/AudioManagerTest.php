<?php

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\CinematicMusicRequest;
use Ichiloto\Engine\Audio\Backends\AfplayBackend;
use Ichiloto\Engine\Audio\Backends\FfplayBackend;
use Ichiloto\Engine\Audio\Backends\Mpg123Backend;
use Ichiloto\Engine\Audio\Backends\MpvBackend;
use Ichiloto\Engine\Audio\Backends\PaplayBackend;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Tests\Support\PassiveGame;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;

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

it('prefers native looping playback for background music when available', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'master_volume' => 100]]);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [
    new FakeAudioBackend(loopsNatively: false),
    new FakeAudioBackend(loopsNatively: true),
  ]);
  $manager->playBackgroundMusic("$root/theme.ogg");

  expect($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->spawnedCommands[0])->toBe(['fake-player', '1.00', 'loop', "$root/theme.ogg"]);

  $manager->shutdown();
});

it('respawns looping background music when only a one-shot backend is available', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'master_volume' => 100]]);
  $root = makeAudioAssetsRoot(['theme.ogg']);

  $manager = new TestableAudioManager(makeAudioTestGame(), [
    new FakeAudioBackend(loopsNatively: false),
  ]);
  $manager->playBackgroundMusic("$root/theme.ogg");
  $manager->pretendBgmHasPlayedFor(2.0);
  $manager->spawnedPlaybacks[0]->stop();
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(2)
    ->and($manager->spawnedCommands[0])->toBe(['fake-player', '1.00', 'once', "$root/theme.ogg"])
    ->and($manager->spawnedCommands[1])->toBe(['fake-player', '1.00', 'once', "$root/theme.ogg"]);

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

it('switches and restores cinematic music through non-blocking authored fades', function () {
  putAudioProjectConfig(['audio' => ['music' => true, 'master_volume' => 100]]);
  $root = makeAudioAssetsRoot(['field.ogg', 'cinematic.ogg']);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/field.ogg", true);
  $session = $manager->beginCinematicMusic(new CinematicMusicRequest(
    track: "$root/cinematic.ogg",
    loop: false,
    fadeIn: 0.2,
    fadeOut: 0.2,
    completionBehavior: 'restore_previous',
  ));

  expect($session->isReady)->toBeFalse()
    ->and($manager->spawnedCommands)->toHaveCount(1)
    ->and($manager->captureBackgroundMusicState()->track)->toBe("$root/field.ogg");

  expect($session->update(0.1))->toBeFalse()
    ->and($manager->spawnedCommands)->toHaveCount(1);

  expect($session->update(0.2))->toBeFalse()
    ->and($manager->captureBackgroundMusicState()->track)->toBe("$root/cinematic.ogg")
    ->and($manager->spawnedCommands[1][2])->toBe('once');

  expect($session->update(0.1))->toBeTrue()
    ->and($session->isReady)->toBeTrue();

  $manager->finalizeCinematicMusic('complete');
  expect($session->updateFinalization(0.1))->toBeFalse()
    ->and($manager->captureBackgroundMusicState()->track)->toBe("$root/cinematic.ogg");

  expect($session->updateFinalization(0.1))->toBeTrue()
    ->and($manager->captureBackgroundMusicState()->track)->toBe("$root/field.ogg")
    ->and($manager->spawnedCommands[2][2])->toBe('loop');

  $manager->finalizeCinematicMusic('complete');
  expect($manager->spawnedCommands)->toHaveCount(3);
  $manager->shutdown();
});

it('preserves deterministic cinematic audio final state for continue and stop policies', function () {
  putAudioProjectConfig(['audio' => ['music' => true]]);
  $root = makeAudioAssetsRoot(['field.ogg', 'continue.ogg', 'stop.ogg']);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playBackgroundMusic("$root/field.ogg");

  $continue = $manager->beginCinematicMusic(new CinematicMusicRequest(
    "$root/continue.ogg",
    loop: false,
    completionBehavior: 'continue',
  ));
  expect($continue->isReady)->toBeTrue();
  $manager->finalizeCinematicMusic('complete');
  expect($continue->isFinalized)->toBeTrue()
    ->and($manager->captureBackgroundMusicState()->track)->toBe("$root/continue.ogg");

  $stop = $manager->beginCinematicMusic(new CinematicMusicRequest(
    "$root/stop.ogg",
    loop: false,
    completionBehavior: 'stop',
  ));
  expect($stop->isReady)->toBeTrue();
  $manager->finalizeCinematicMusic('failure');
  expect($stop->isFinalized)->toBeTrue()
    ->and($manager->captureBackgroundMusicState()->track)->toBeNull();

  $manager->shutdown();
});

it('stays silent for a system sound the project has not configured', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 50]]);

  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);
  $manager->playSystemSound(SystemSound::NOTIFICATION);

  expect($manager->spawnedCommands)->toBe([]);

  $manager->shutdown();
});

/* System sound volume and looping sound effects */

it('plays a system sound at its authored volume under the master volume', function () {
  $root = makeAudioAssetsRoot(['cursor.wav']);
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 50,
    'sounds' => ['cursor' => "$root/cursor.wav"], 'sound_volumes' => ['cursor' => 40]]]);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);

  $manager->playSystemSound(SystemSound::CURSOR);

  expect($manager->spawnedCommands)->toBe([['fake-player', '0.20', 'once', "$root/cursor.wav"]])
    ->and($manager->getSystemSoundVolume(SystemSound::CONFIRM))->toBe(1.0);
  $manager->shutdown();
});

it('loops a system sound natively at its authored volume until the handle stops it', function () {
  $root = makeAudioAssetsRoot(['tick.wav']);
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 80,
    'sounds' => ['counter' => "$root/tick.wav"], 'sound_volumes' => ['counter' => 50]]]);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: false), new FakeAudioBackend()]);

  $loop = $manager->startSystemSoundLoop(SystemSound::COUNTER);
  $manager->update();

  expect($loop)->not->toBeNull()->and($loop->isAudible)->toBeTrue()
    ->and($manager->spawnedCommands)->toBe([['fake-player', '0.40', 'loop', "$root/tick.wav"]]);
  $player = $manager->spawnedPlaybacks[0];
  $loop->stop();
  $loop->stop();
  $manager->update();
  expect($loop->isStopped)->toBeTrue()->and($player->isRunning)->toBeFalse()
    ->and($manager->spawnedCommands)->toHaveCount(1);
});

it('respawns a loop on a player that cannot loop by itself, and stays silent after a failure', function () {
  $root = makeAudioAssetsRoot(['tick.wav']);
  putAudioProjectConfig(['audio' => ['sfx' => true, 'master_volume' => 100]]);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend(loopsNatively: false)]);
  $manager->playerCommand = ['true'];

  $loop = $manager->startSoundEffectLoop("$root/tick.wav");
  usleep(100_000);
  $manager->update();

  expect($manager->spawnedCommands)->toBe([['fake-player', '1.00', 'once', "$root/tick.wav"],
    ['fake-player', '1.00', 'once', "$root/tick.wav"]]);

  $manager->playerCommand = ['false'];
  usleep(100_000);
  $manager->update();
  usleep(100_000);
  $manager->update();
  $manager->update();
  expect($manager->spawnedCommands)->toHaveCount(3)->and($loop->isAudible)->toBeFalse()
    ->and($loop->isStopped)->toBeFalse();
  $loop->stop();
});

it('keeps a loop silent while sound effects are off or the volume is zero, and resumes it live', function () {
  $root = makeAudioAssetsRoot(['tick.wav']);
  $config = new AudioArrayConfigStub(['audio' => ['sfx' => false, 'master_volume' => 100]]);
  ConfigStore::put(ProjectConfig::class, $config);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);

  $loop = $manager->startSoundEffectLoop("$root/tick.wav");
  $manager->update();
  expect($manager->spawnedCommands)->toBe([])->and($loop->isAudible)->toBeFalse();

  $config->set('audio.sfx', true);
  $manager->update();
  expect($manager->spawnedCommands)->toHaveCount(1)->and($loop->isAudible)->toBeTrue();

  $player = $manager->spawnedPlaybacks[0];
  $config->set('audio.master_volume', 0);
  $manager->update();
  expect($player->isRunning)->toBeFalse()->and($loop->isAudible)->toBeFalse()
    ->and($manager->spawnedCommands)->toHaveCount(1);

  $config->set('audio.master_volume', 60);
  $manager->update();
  expect($manager->spawnedCommands[1])->toBe(['fake-player', '0.60', 'loop', "$root/tick.wav"]);
  $manager->shutdown();
  expect($loop->isStopped)->toBeTrue()->and($manager->spawnedPlaybacks[1]->isRunning)->toBeFalse();
});

it('has no loop for an unconfigured or missing sound', function () {
  putAudioProjectConfig(['audio' => ['sfx' => true, 'sounds' => ['counter' => '/nowhere/tick.wav']]]);
  $manager = new TestableAudioManager(makeAudioTestGame(), [new FakeAudioBackend()]);

  expect($manager->startSystemSoundLoop(SystemSound::COUNTER))->toBeNull()
    ->and($manager->startSystemSoundLoop(SystemSound::CURSOR))->toBeNull()
    ->and($manager->spawnedCommands)->toBe([]);
});

/* The counter tick */

it('plays one counter tick for overlapping counts and stops when the last count releases it', function () {
  $manager = makeCounterTickManager();
  $tick = $manager->counterSound;
  [$experience, $points] = [new stdClass(), new stdClass()];

  $tick->holdTick($experience);
  $tick->holdTick($points);
  $manager->update();
  $tick->holdTick($experience);
  $tick->holdTick($points);
  $manager->update();

  expect($manager->spawnedCommands)->toHaveCount(1)->and($tick->isPlaying)->toBeTrue();
  $tick->releaseTick($experience);
  expect($tick->isPlaying)->toBeTrue();
  $tick->releaseTick($points);
  expect($tick->isPlaying)->toBeFalse()->and($manager->spawnedPlaybacks[0]->isRunning)->toBeFalse();

  $tick->holdTick($points);
  expect($manager->spawnedCommands)->toHaveCount(2);
  $manager->shutdown();
  expect($tick->isPlaying)->toBeFalse()->and($tick->isHeld)->toBeFalse();
});

it('lapses a counter tick hold that is not renewed, so a frozen or discarded count cannot keep it playing', function () {
  $manager = makeCounterTickManager();
  $tick = $manager->counterSound;
  $count = new stdClass();

  $tick->holdTick($count);
  $manager->update();
  expect($tick->isPlaying)->toBeTrue();
  $manager->update();
  expect($tick->isPlaying)->toBeFalse()->and($manager->spawnedPlaybacks[0]->isRunning)->toBeFalse();

  $tick->holdTick($count);
  unset($count);
  $manager->update();
  expect($tick->isHeld)->toBeFalse()->and($tick->isPlaying)->toBeFalse();
});

it('plays no counter tick while sound effects are muted', function () {
  $manager = makeCounterTickManager(['sfx' => false, 'master_volume' => 100]);
  $count = new stdClass();

  $manager->counterSound->holdTick($count);
  $manager->update();
  $manager->counterSound->holdTick($count);
  $manager->update();

  expect($manager->spawnedCommands)->toBe([]);
  $manager->shutdown();
});
