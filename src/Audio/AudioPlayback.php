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
      proc_terminate($this->process);
    }

    proc_close($this->process);
    $this->isClosed = true;
  }
}
