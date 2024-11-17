<?php

namespace Ichiloto\Engine\Core;

use Ichiloto\Engine\Core\Enumerations\ChronoUnit;
use Ichiloto\Engine\Events\Enumerations\GameEventType;
use Ichiloto\Engine\Events\GameEvent;
use Ichiloto\Engine\Events\Interfaces\EventInterface;
use Ichiloto\Engine\Events\Interfaces\StaticObserverInterface;
use InvalidArgumentException;

/**
 * The Time class is used to manage time in the game.
 *
 * @package Ichiloto\Engine\Core
 */
class Time implements StaticObserverInterface
{
  /**
   * The time the game started.
   *
   * @var float
   */
  protected static float $startTime = 0.0;
  /**
   * The time the game last updated.
   *
   * @var float
   */
  protected static float $lastTime = 0.0;
  /**
   * The time between the last update and the current update.
   *
   * @var float
   */
  protected static float $deltaTime = 0.0;
  /**
   * The time since the game started.
   *
   * @var float
   */
  private static float $time = 0.0;
  /**
   * The time the game was stopped.
   *
   * @var float
   */
  protected static float $stopTime = 0.0;
  /**
   * The number of frames since the game started.
   *
   * @var int
   */
  private static int $frames = 0;
  /**
   * The target frames per second of the game.
   *
   * @var int
   */
  private static int $targetFPS = 60;
  /**
   * The timescale of the game. This affects the speed of the game.
   * A value of 1.0 means the game is running at normal speed.
   *
   * @var float
   */
  private static float $timeScale = 1.0;
  /**
   * The last draw call count.
   *
   * @var int
   */
  private static int $lastDrawCallCount = 0;
  /**
   * The number of draw calls per frame.
   *
   * @var int
   */
  private static int $drawCallsPerFrame = 0;

  /**
   * Creates a new time instance.
   */
  private function __construct()
  {
  }

  protected static function onGameStart(): void
  {
    self::$startTime = self::getSystemTime();
    self::$lastTime = self::$startTime;
  }

  /**
   * Gets the current time. The time is returned in microseconds by default.
   *
   * @param ChronoUnit $chronoUnit The unit of time to return.
   * @return float The current time. The time is returned in microseconds by default.
   */
  public static function getSystemTime(ChronoUnit $chronoUnit = ChronoUnit::MICROS): float
  {
    return match ($chronoUnit) {
      ChronoUnit::NANOS => hrtime(true),
      ChronoUnit::MILLIS => microtime(true) * 1000,
      ChronoUnit::MICROS => microtime(true),
      ChronoUnit::SECONDS => time(),
      default => throw new InvalidArgumentException('Invalid ChronoUnit.'),
    };
  }

  /**
   * Gets the time since the start of the game.
   *
   * @return float The time since the start of the game.
   */
  public static function getTime(): float
  {
    return self::$time;
  }

  /**
   * Returns the timescale of the game.
   *
   * @return float The timescale of the game.
   */
  public static function getTimeScale(): float
  {
    return self::$timeScale;
  }

  /**
   * Sets the timescale of the game.
   *
   * @param float $timeScale The timescale of the game.
   * @return void
   */
  public static function setTimeScale(float $timeScale): void
  {
    self::$timeScale = clamp($timeScale, 0.0, 1.0);
  }

  /**
   * Called when the game is updated.
   *
   * @return void
   */
  public static function onGameUpdate(): void
  {
    self::$time = self::getSystemTime() - self::$startTime;
    self::$deltaTime = self::getTime() - self::$lastTime;
    self::$lastTime = self::getTime();
    self::$frames++;
  }

  /**
   * Gets the time since the last frame in nanoseconds.
   *
   * @return float The time since the last frame in nanoseconds.
   */
  public static function getDeltaTime(): float
  {
    return self::$deltaTime;
  }

  /**
   * Returns a formatted string of the time since the game started.
   *
   * @param ChronoUnit $chronoUnit The unit of time to return.
   * @return string A formatted string of the time since the game started.
   */
  public static function getPrettyTime(ChronoUnit $chronoUnit = ChronoUnit::MINUTES): string
  {
    $time = (int)self::$time;
    $hours = floor($time / 3600);
    $minutes = floor((int)($time / 60) % 60);
    $seconds = $time % 60;

    if ($chronoUnit === ChronoUnit::MINUTES)
    {
      $days = floor($hours / 24);
      return sprintf('%02d:%02d:%02d', $days, $hours, $minutes);
    }

    return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
  }

  /**
   * @inheritDoc
   */
  public static function onNotify(object $entity, EventInterface $event): void
  {
    if ($event instanceof GameEvent)
    {
      switch ($event->getGameEventType())
      {
        case GameEventType::START:
          self::onGameStart();
          break;

        case GameEventType::UPDATE:
          self::onGameUpdate();
          break;

        case GameEventType::STOP:
          self::onGameStop();
          break;

        case GameEventType::PAUSE:
          self::onGamePause();
          break;

        case GameEventType::RESUME:
          self::onGameResume();
          break;

        case GameEventType::RENDER:
          self::onGameRender();
          break;

        case GameEventType::SUSPEND:
          self::onGameSuspend();
          break;

        case GameEventType::QUIT:
          self::onGameQuit();
      }
    }
  }

  /**
   * Called when the game is quit.
   *
   * @return void
   */
  private static function onGameStop(): void
  {
    self::$stopTime = self::getSystemTime();
  }

  /**
   * Called when the game is paused.
   *
   * @return void
   */
  private static function onGameRender(): void
  {
    // Do nothing
  }

  /**
   * Called when the game is paused.
   *
   * @return void
   */
  private static function onGamePause(): void
  {
    // Do nothing
  }

  /**
   * Called when the game is resumed.
   *
   * @return void
   */
  private static function onGameResume(): void
  {
    // Do nothing
  }

  /**
   * Called when the game is suspended.
   *
   * @return void
   */
  private static function onGameSuspend(): void
  {
    // Do nothing
  }

  /**
   * Called when the game is quit.
   *
   * @return void
   */
  private static function onGameQuit(): void
  {
    // Do nothing
  }

  /**
   * Returns the number of frames since the game started.
   *
   * @return int The number of frames since the game started.
   */
  public static function getFrameCount(): int
  {
    return self::$frames;
  }
}