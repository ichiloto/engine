<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioPlayback;
use Ichiloto\Engine\Audio\Interfaces\AudioBackendInterface;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Messaging\Dialogue\DialoguePlayback;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

final class PauseTestGame extends Game
{
    public function __construct() {}
    public function __destruct() {}
}

final class PauseTestPlayer extends AudioPlayback
{
    public bool $running = true;
    public int $pauses = 0;
    public int $resumes = 0;
    public bool $supported = true;
    public bool $failPause = false;
    public bool $isRunning { get => $this->running; }
    public function __construct(array $command) { $this->command = $command; $this->pid = null; }
    public function canPause(): bool { return $this->supported; }
    public function pause(): void
    {
        if ($this->failPause) { throw new RuntimeException('Synthetic pause failure'); }
        ++$this->pauses;
        $this->isPaused = true;
    }
    public function resume(): void
    {
        if ($this->running && $this->isPaused) { ++$this->resumes; }
        $this->isPaused = false;
    }
    public function stop(): void { $this->running = false; $this->isPaused = false; }
}

final class PauseTestBackend implements AudioBackendInterface
{
    public function getExecutableName(): string { return 'silent-pause-fixture'; }
    public function isAvailable(): bool { return true; }
    public function supports(string $filePath): bool { return true; }
    public function supportsNativeLooping(): bool { return true; }
    public function supportsSeeking(): bool { return true; }
    public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array
    { return [$filePath, (string)$volume, $loop ? 'loop' : 'once', (string)$startAtSeconds]; }
}

final class PauseTestAudio extends AudioManager
{
    /** @var list<PauseTestPlayer> */
    public array $players = [];
    public function __construct() { parent::__construct(new PauseTestGame()); }
    protected function createBackends(): array { return [new PauseTestBackend()]; }
    protected function getProjectSetting(string $path, mixed $default = null): mixed
    {
        return match ($path) {
            'audio.music', 'audio.voice', 'audio.sfx' => true,
            'audio.master_volume', 'audio.music_volume', 'audio.voice_volume', 'audio.sfx_volume' => 100,
            default => $default,
        };
    }
    protected function spawn(array $command): ?AudioPlayback { return $this->players[] = new PauseTestPlayer($command); }
    public function getMusicStart(): float { return $this->bgmStartedAt; }
}

beforeEach(function () {
    $this->config = new ReflectionClass(ConfigStore::class)->getStaticProperties();
    ConfigStore::remove(ProjectConfig::class);
    $this->pauseRoot = createTestDirectory('playback-pause-');
    $this->sound = $this->pauseRoot . '/silent.wav';
    file_put_contents($this->sound, 'synthetic file; never decoded or played');
    $this->pauseAudio = new PauseTestAudio();
});

afterEach(function () {
    $this->pauseAudio->shutdown();
    foreach ($this->config as $name => $value) { new ReflectionProperty(ConfigStore::class, $name)->setValue(null, $value); }
});

it('freezes the existing music, speech, one-shot and loop until the final owner releases', function () {
    $audio = $this->pauseAudio;
    $audio->playBackgroundMusic($this->sound);
    $voice = $audio->playSpeech($this->sound);
    $audio->playSoundEffect($this->sound);
    $loop = $audio->startSoundEffectLoop($this->sound);
    $players = $audio->players;
    expect($players)->toHaveCount(4);
    $start = $audio->getMusicStart();
    $outer = $audio->pausePlayback();
    $inner = $audio->pausePlayback();
    foreach ($players as $player) { expect($player->isPaused)->toBeTrue()->and($player->pauses)->toBe(1); }
    // UI interaction sounds have their own lifetime and remain outside the paused world.
    $audio->playSoundEffect($this->sound);
    $menu = array_last($audio->players);
    expect($menu->isPaused)->toBeFalse()->and($audio->isSpeechPlaying($voice))->toBeTrue();
    $audio->update();
    expect($audio->players)->toHaveCount(5)->and($loop->isAudible)->toBeFalse();
    $outer->close();
    foreach ($players as $player) { expect($player->isPaused)->toBeTrue(); }
    new ReflectionProperty(AudioManager::class, 'pauseStartedAt')->setValue($audio, \Ichiloto\Engine\Core\Clocks\RuntimeClock::getSeconds() - 60);
    $inner->close();
    $inner->close();
    foreach ($players as $player) { expect($player->isPaused)->toBeFalse()->and($player->resumes)->toBe(1); }
    expect($audio->getMusicStart() - $start)->toEqualWithDelta(60, 0.1)
        ->and($audio->players)->toHaveCount(5)->and($voice->wasInterrupted)->toBeFalse()
        ->and($loop->isStopped)->toBeFalse()->and($loop->isAudible)->toBeTrue()->and($menu->resumes)->toBe(0);
});

it('qualifies all players before pausing and rolls back a partial process failure', function () {
    $audio = $this->pauseAudio;
    $audio->playBackgroundMusic($this->sound);
    $audio->playSpeech($this->sound);
    [$music, $voice] = $audio->players;
    $voice->supported = false;
    expect(fn() => $audio->pausePlayback())->toThrow(RuntimeException::class, 'cannot pause')
        ->and($music->pauses)->toBe(0);
    $voice->supported = true;
    $voice->failPause = true;
    expect(fn() => $audio->pausePlayback())->toThrow(RuntimeException::class, 'Synthetic pause failure')
        ->and($music->isPaused)->toBeFalse()->and($music->resumes)->toBe(1);
    $voice->failPause = false;
    $lease = $audio->pausePlayback();
    $audio->shutdown();
    $lease->close();
    foreach ($audio->players as $player) { expect($player->isRunning)->toBeFalse()->and($player->isPaused)->toBeFalse(); }
});

it('keeps auto-reading time while paused and excludes suspended time on resume', function () {
    $playback = new DialoguePlayback(auto: true);
    $playback->beginLine();
    $playback->beginPage('A short line');
    expect($playback->canAdvance(10, false, true))->toBeFalse();
    $playback->pause(10.25);
    $playback->pause(40);
    expect($playback->canAdvance(100, false, true))->toBeFalse();
    $playback->resume(110.25);
    $playback->resume(200);
    expect($playback->canAdvance(110.9, false, true))->toBeFalse()
        ->and($playback->canAdvance(111.1, false, true))->toBeTrue();
    $playback->pause(112);
    $playback->finishLine();
    $playback->beginLine();
    $playback->beginPage('New line');
    expect($playback->canAdvance(200, false, true))->toBeFalse()
        ->and($playback->canAdvance(202, false, true))->toBeTrue();
});

it('suspends and resumes an actual silent child without replacing its process or hanging cleanup', function () {
    if (PHP_OS_FAMILY === 'Windows' || !defined('SIGSTOP') || !defined('SIGCONT')) {
        test()->markTestSkipped('POSIX command-line process suspension is unavailable.');
    }
    $counter = $this->pauseRoot . '/counter';
    $child = AudioPlayback::start([PHP_BINARY, '-r',
        '$end=microtime(true)+5; $i=0; while(microtime(true)<$end) { file_put_contents($argv[1], (string)++$i); usleep(5000); }', $counter]);
    expect($child)->not->toBeNull();
    try {
        for ($i = 0; $i < 100 && !is_file($counter); ++$i) { usleep(5000); }
        expect(is_file($counter))->toBeTrue();
        $pid = $child->pid;
        $child->pause();
        // Let the child's pending stop signal take effect before observing its work.
        usleep(30000);
        $frozen = file_get_contents($counter);
        usleep(50000);
        expect(file_get_contents($counter))->toBe($frozen)->and($child->isRunning)->toBeTrue();
        $child->resume();
        for ($i = 0; $i < 100 && file_get_contents($counter) === $frozen; ++$i) { usleep(5000); }
        expect(file_get_contents($counter))->not->toBe($frozen)->and($child->pid)->toBe($pid);
        $child->pause();
        $start = microtime(true);
        $child->stop();
        expect(microtime(true) - $start)->toBeLessThan(1)->and($child->isRunning)->toBeFalse();
        if (function_exists('posix_kill')) { expect(posix_kill($pid, 0))->toBeFalse(); }
        $child->resume();
        $child->stop();
    } finally { $child->stop(); }
});


it('excludes a host-driven pause from the existing music handle without changing its process identity', function () {
    require_once __DIR__ . '/../Support/ManualRuntimeClock.php';
    $saved = new ReflectionClass(\Ichiloto\Engine\Core\Clocks\RuntimeClock::class)->getStaticProperties();
    $source = new \Tests\Support\ManualRuntimeClock();
    $clock = \Ichiloto\Engine\Core\Clocks\RuntimeClock::useClock($source);
    try {
        $audio = $this->pauseAudio;
        $audio->playBackgroundMusic($this->sound);
        $handle = $audio->players[0]; $start = $audio->getMusicStart();
        $source->seconds += 2;
        $pause = $audio->pausePlayback();
        $source->seconds += 60;
        $pause->close();
        expect($audio->getMusicStart() - $start)->toEqualWithDelta(60, 0.000001)
            ->and($audio->players)->toHaveCount(1)->and($audio->players[0])->toBe($handle)
            ->and($handle->resumes)->toBe(1);
    } finally {
        $clock->close();
        foreach ($saved as $name => $value) {
            new ReflectionProperty(\Ichiloto\Engine\Core\Clocks\RuntimeClock::class, $name)->setValue(null, $value);
        }
    }
});
