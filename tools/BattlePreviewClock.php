<?php

declare(strict_types=1);

/** Inspection time belongs to the preview driver, never to gameplay or rendering. */
final class BattlePreviewClock
{
    private float $seconds = 0.0;
    private float $pending = 0.0;
    private bool $playing = false;

    public function __construct(private readonly float $duration) {}

    public bool $isCompleted { get => $this->seconds >= $this->duration; }
    public bool $isPlaying { get => $this->playing; }
    public float $elapsedSeconds { get => $this->seconds; }

    /** These controls belong only to the developer inspector, not battle input. */
    public function applyKey(string $key): bool
    {
        $command = match ($key) {
            'space' => $this->playing ? 'pause' : 'play',
            'right' => 'step 1',
            'down' => 'step 0.1',
            'escape' => 'quit',
            default => null,
        };
        if ($command === null) { return false; }
        $this->applyCommand($command);
        return true;
    }

    public function applyCommand(string $command): void
    {
        $command = trim($command);
        if ($command === 'play') { $this->playing = true; return; }
        if ($command === 'pause') { $this->playing = false; return; }
        if ($command === 'quit') { throw new RuntimeException('Inspection ended before verification.'); }
        if (preg_match('/^step\s+(\d+(?:\.\d+)?)$/D', $command, $match) === 1) {
            $seconds = (float) $match[1];
            if (is_finite($seconds) && $seconds > 0 && $seconds <= $this->duration) {
                $this->playing = false;
                $this->pending = min($this->duration - $this->seconds, $this->pending + $seconds);
                return;
            }
        }
        throw new InvalidArgumentException('Use play, pause, step SECONDS (positive, within duration), or quit.');
    }

    /** Stepping visits every simulation frame, so it cannot skip outcomes or reactions. */
    public function advanceTime(float $wallDelta): array
    {
        if (!is_finite($wallDelta) || $wallDelta < 0) {
            throw new InvalidArgumentException('Inspection wall time must be finite and monotonic.');
        }
        $amount = $this->pending + ($this->playing ? $wallDelta : 0.0);
        $this->pending = 0.0;
        $end = min($this->duration, $this->seconds + $amount);
        $frames = [];
        while ($this->seconds < $end) {
            $this->seconds = min($end, $this->seconds + 1 / 60);
            $frames[] = $this->seconds;
        }
        return $frames;
    }
}
