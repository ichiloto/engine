<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Util\Debug;
use Throwable;

/**
 * Work scheduled to run later, pumped by the game loop.
 *
 * The alternative is `usleep()`, which stops the world: music stops looping,
 * notifications freeze mid-slide, and engine time jumps when the sleep ends.
 * A timer lets a caller wait without any of that, either by scheduling a
 * callback or by handing the loop back through {@see Timers::wait()}.
 *
 * @package Ichiloto\Engine\Core
 */
class Timers
{
  /**
   * @var array<int, array{due: float, interval: float|null, callback: callable}> The pending timers.
   */
  protected static array $timers = [];
  /**
   * @var int The handle of the last timer scheduled.
   */
  protected static int $lastHandle = 0;
  /**
   * @var callable|null Pumps one frame of the world while a caller waits.
   */
  protected static mixed $frameTick = null;
  /** @var callable|null Presents the completed frame after optional caller drawing. */
  protected static mixed $framePresent = null;

  /**
   * Runs a callback once, after the given delay.
   *
   * @param float $seconds How long to wait.
   * @param callable $callback The work to run.
   * @return int The handle, for cancelling.
   */
  public static function after(float $seconds, callable $callback): int
  {
    return self::schedule($seconds, null, $callback);
  }

  /**
   * Runs a callback repeatedly, on an interval.
   *
   * @param float $seconds The interval.
   * @param callable $callback The work to run.
   * @return int The handle, for cancelling.
   */
  public static function every(float $seconds, callable $callback): int
  {
    return self::schedule($seconds, max(0.001, $seconds), $callback);
  }

  /**
   * Cancels a scheduled timer.
   *
   * @param int $handle The handle returned when it was scheduled.
   * @return bool True when a pending timer was cancelled.
   */
  public static function cancel(int $handle): bool
  {
    if (! isset(self::$timers[$handle])) {
      return false;
    }

    unset(self::$timers[$handle]);

    return true;
  }

  /**
   * Drops every pending timer.
   *
   * @return void
   */
  public static function clear(): void
  {
    self::$timers = [];
  }

  /**
   * Returns how many timers are pending.
   *
   * @return int The pending timer count.
   */
  public static function count(): int
  {
    return count(self::$timers);
  }

  /**
   * Registers how to pump one frame of the world.
   *
   * The game sets this at boot; without it {@see Timers::wait()} falls back to
   * sleeping, so the engine still works in tooling contexts with no loop.
   *
   * @param callable|null $frameTick The frame pump.
   * @param callable|null $framePresent Presents after the caller's optional draw callback.
   * @return void
   */
  public static function setFrameTick(?callable $frameTick, ?callable $framePresent = null): void
  {
    self::$frameTick = $frameTick;
    self::$framePresent = $framePresent;
  }

  /**
   * Runs every timer that has come due.
   *
   * @return void
   */
  public static function update(): void
  {
    $now = Time::getTime();

    foreach (self::$timers as $handle => $timer) {
      if ($now < $timer['due']) {
        continue;
      }

      if ($timer['interval'] === null) {
        unset(self::$timers[$handle]);
      } else {
        self::$timers[$handle]['due'] = $now + $timer['interval'];
      }

      try {
        ($timer['callback'])();
      } catch (Throwable $exception) {
        Debug::warn(sprintf('Timer failed: %s', $exception->getMessage()));
      }
    }
  }

  /**
   * Waits, keeping the world running.
   *
   * Music keeps playing, notifications keep animating, and engine time keeps
   * advancing, which a bare `usleep()` would freeze. An optional callback runs
   * each frame, for a caller animating something while it waits.
   *
   * @param float $seconds How long to wait.
   * @param callable|null $onFrame Called once per frame with the elapsed
   * fraction, from 0.0 to 1.0.
   * @return void
   */
  public static function wait(float $seconds, ?callable $onFrame = null): void
  {
    if ($seconds <= 0) {
      return;
    }

    $frameTick = self::$frameTick;
    $framePresent = self::$framePresent;
    $frameLength = (int)(1_000_000 / DEFAULT_FPS);
    $startedAt = microtime(true);
    $endsAt = $startedAt + $seconds;

    while (($now = microtime(true)) < $endsAt) {
      if ($frameTick !== null) {
        $frameTick();
      }

      if ($onFrame !== null) {
        $onFrame(min(1.0, ($now - $startedAt) / $seconds));
      }

      if ($framePresent !== null) {
        $framePresent();
      }

      // Short authored holds must not be inflated to a whole engine frame.
      $remaining = (int)(($endsAt - microtime(true)) * 1_000_000);
      if ($remaining > 0) {
        usleep(min($frameLength, $remaining));
      }
    }

    if ($onFrame !== null) {
      $onFrame(1.0);
      if ($framePresent !== null) {
        $framePresent();
      }
    }
  }

  /**
   * Schedules a timer.
   *
   * @param float $seconds The delay.
   * @param float|null $interval The repeat interval, or null to run once.
   * @param callable $callback The work to run.
   * @return int The handle.
   */
  protected static function schedule(float $seconds, ?float $interval, callable $callback): int
  {
    $handle = ++self::$lastHandle;

    self::$timers[$handle] = [
      'due' => Time::getTime() + max(0.0, $seconds),
      'interval' => $interval,
      'callback' => $callback,
    ];

    return $handle;
  }
}
