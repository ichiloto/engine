<?php

namespace Ichiloto\Engine\Audio;

/**
 * A handle to a spawned audio player process.
 *
 * The handle owns the underlying process resource: it can report whether the
 * player is still running and terminate it. Callers must invoke stop() when
 * they are done with a handle — including handles whose process has already
 * exited — so the child process is reaped and never left as a zombie.
 *
 * @package Ichiloto\Engine\Audio
 */
class AudioPlayback
{
  /**
   * The process resource returned by proc_open().
   *
   * @var resource
   */
  protected $process;

  /**
   * The operating system process ID of the player, or null when it could not
   * be determined.
   *
   * @var int|null
   */
  public readonly ?int $pid;

  /**
   * Whether the process handle has been closed.
   *
   * @var bool
   */
  protected bool $isClosed = false;
  protected(set) bool $isPaused = false;
  /** The child's exit status, retained before its process handle is released. */
  protected(set) ?int $exitCode = null;
  protected(set) bool $wasInterrupted = false;

  /**
   * Whether the player process is still running.
   *
   * @var bool
   */
  public bool $isRunning {
    get {
      if ($this->isClosed) {
        return false;
      }

      $status = proc_get_status($this->process);

      if ($status !== false && ! $status['running'] && $status['exitcode'] >= 0) {
        $this->exitCode = $status['exitcode'];
      }

      return $status !== false && ($status['running'] ?? false);
    }
  }

  /**
   * AudioPlayback constructor.
   *
   * @param resource $process The process resource returned by proc_open().
   * @param string[] $command The argv list the process was spawned with.
   */
  protected function __construct($process, public readonly array $command)
  {
    $this->process = $process;

    $status = proc_get_status($process);
    $this->pid = $status !== false ? ($status['pid'] ?? null) : null;
  }

  /**
   * Spawns the player process described by the given argv list.
   *
   * The process is fully detached from the terminal: stdin reads from
   * /dev/null and stdout/stderr are discarded so the player can never corrupt
   * the rendered screen.
   *
   * @param string[] $command The argv list, executable first.
   * @return self|null The playback handle, or null when the process could not
   *   be started.
   */
  public static function start(array $command): ?self
  {
    $descriptors = [
      0 => ['file', '/dev/null', 'r'],
      1 => ['file', '/dev/null', 'w'],
      2 => ['file', '/dev/null', 'w'],
    ];

    $process = @proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
      return null;
    }

    return new self($process, $command);
  }

  /** Pause this owned player in place without restarting its process. */
  public function pause(): void
  {
    if ($this->isClosed || $this->isPaused || !$this->isRunning) { return; }
    if (!$this->canPause()) {
      throw new \RuntimeException('The command-line audio player requires POSIX process suspension to pause.');
    }
    if (!proc_terminate($this->process, SIGSTOP)) {
      throw new \RuntimeException('Could not pause the owned audio player.');
    }
    $this->isPaused = true;
  }

  /** Reports capability before a group changes any player's state. */
  public function canPause(): bool
  {
    return PHP_OS_FAMILY !== 'Windows' && defined('SIGSTOP') && defined('SIGCONT');
  }

  public function resume(): void
  {
    if ($this->isClosed || !$this->isPaused) { return; }
    if ($this->isRunning && !proc_terminate($this->process, SIGCONT)) {
      throw new \RuntimeException('Could not resume the owned audio player.');
    }
    $this->isPaused = false;
  }

  /**
   * Terminates the process if it is still running and releases the handle.
   *
   * Safe to call multiple times and safe to call on processes that have
   * already exited (closing the handle reaps the child).
   *
   * @return void
   */
  public function stop(): void
  {
    if ($this->isClosed) {
      return;
    }

    if ($this->isRunning) {
      $this->wasInterrupted = true;
      // A stopped process cannot handle termination until it is continued.
      // Kill only this owned child if continuation fails; never block reaping it.
      if ($this->isPaused && !proc_terminate($this->process, SIGCONT)) {
        proc_terminate($this->process, SIGKILL);
      } else {
        proc_terminate($this->process);
      }
    }

    proc_close($this->process);
    $this->isClosed = true;
    $this->isPaused = false;
  }
}
