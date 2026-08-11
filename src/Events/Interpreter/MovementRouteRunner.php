<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Executes one sequential, collision-aware player or NPC movement route.
 */
final class MovementRouteRunner
{
  public const array DIRECTIONS = ['up', 'down', 'left', 'right'];

  /** @var array<int, array<string, mixed>> */
  protected array $steps;
  protected int $stepIndex = 0;
  protected int $completedRepeats = 0;
  protected float $remainingDelay = 0.0;
  protected(set) bool $isComplete = false;

  /**
   * @param array<string, mixed> $command The authored `move_route` command.
   */
  public function __construct(
    protected GameScene $gameScene,
    protected array $command,
  )
  {
    $this->steps = array_values(array_filter((array) ($command['steps'] ?? []), is_array(...)));
    $this->validate();
  }

  /**
   * Advances at most one visible route unit.
   *
   * @param float $deltaSeconds Elapsed engine time since the previous tick.
   * @return bool True once the route has completed.
   */
  public function update(float $deltaSeconds): bool
  {
    if ($this->isComplete) {
      return true;
    }

    $this->remainingDelay = max(0.0, $this->remainingDelay - max(0.0, $deltaSeconds));

    if ($this->remainingDelay > 0.0) {
      return false;
    }

    $step = $this->steps[$this->stepIndex] ?? null;

    if (! is_array($step)) {
      $this->isComplete = true;
      return true;
    }

    $count = max(0, intval($step['count'] ?? 1));

    if ($count === 0) {
      $this->advanceStep();
      return $this->isComplete;
    }

    $directionName = strtolower(trim(strval($step['direction'] ?? '')));
    $direction = self::directionVector($directionName);
    $faceOnly = $step['faceOnly'] ?? false;
    $subject = strtolower(trim(strval($this->command['subject'] ?? 'player')));
    $succeeded = match ($subject) {
      'player' => $faceOnly
        ? $this->facePlayer($direction)
        : ($this->gameScene->player?->tryMove($direction, $this->gameScene->camera) ?? false),
      'npc' => $faceOnly
        ? $this->gameScene->npcManager?->faceNpc(strval($this->command['npcId'] ?? ''), $direction) ?? false
        : $this->gameScene->npcManager?->moveNpcById(strval($this->command['npcId'] ?? ''), $direction) ?? false,
      default => false,
    };

    if (! $succeeded) {
      $this->throwBlockedRoute($subject, $directionName, $direction);
    }

    $this->completedRepeats++;

    if ($this->completedRepeats >= $count || $faceOnly) {
      $this->advanceStep();
    }

    $this->remainingDelay = $this->stepDelay($step);

    return $this->isComplete;
  }

  protected function validate(): void
  {
    $wait = $this->command['wait'] ?? true;

    if (! is_bool($wait) || ! $wait) {
      throw new MovementRouteException('Parallel movement routes are not supported; move_route.wait must be true.');
    }

    $subject = strtolower(trim(strval($this->command['subject'] ?? 'player')));

    if (! in_array($subject, ['player', 'npc'], true)) {
      throw new MovementRouteException(sprintf('Unsupported movement-route subject "%s".', $subject));
    }

    if ($subject === 'npc' && trim(strval($this->command['npcId'] ?? '')) === '') {
      throw new MovementRouteException('An NPC movement route requires a stable npcId.');
    }

    if ($this->steps === []) {
      throw new MovementRouteException('A movement route requires at least one step.');
    }

    foreach ($this->steps as $index => $step) {
      $direction = strtolower(trim(strval($step['direction'] ?? '')));

      if (! in_array($direction, self::DIRECTIONS, true)) {
        throw new MovementRouteException(sprintf('Movement route step %d has unsupported direction "%s".', $index + 1, $direction));
      }

      $count = $step['count'] ?? 1;

      if (! is_numeric($count) || floatval($count) !== floatval(intval($count)) || intval($count) < 0) {
        throw new MovementRouteException(sprintf('Movement route step %d has an invalid count.', $index + 1));
      }

      if (array_key_exists('faceOnly', $step) && ! is_bool($step['faceOnly'])) {
        throw new MovementRouteException(sprintf('Movement route step %d has an invalid faceOnly value.', $index + 1));
      }

      $this->stepDelay($step);
    }
  }

  protected function facePlayer(Vector2 $direction): bool
  {
    if ($this->gameScene->player === null) {
      return false;
    }

    $this->gameScene->player->face($direction, $this->gameScene->camera);

    return true;
  }

  /** @param array<string, mixed> $step The route step. */
  protected function stepDelay(array $step): float
  {
    $seconds = $step['seconds'] ?? $this->command['secondsPerStep'] ?? null;

    if ($seconds !== null) {
      if (! is_numeric($seconds) || floatval($seconds) < 0.0) {
        throw new MovementRouteException('Movement-route timing must be zero or a positive number of seconds.');
      }

      return floatval($seconds);
    }

    $speed = $this->command['speed'] ?? null;

    if ($speed !== null) {
      if (! is_numeric($speed) || floatval($speed) <= 0.0) {
        throw new MovementRouteException('Movement-route speed must be greater than zero.');
      }

      return 1.0 / floatval($speed);
    }

    return 0.15;
  }

  protected function advanceStep(): void
  {
    $this->stepIndex++;
    $this->completedRepeats = 0;
    $this->isComplete = $this->stepIndex >= count($this->steps);
  }

  protected function throwBlockedRoute(string $subject, string $directionName, Vector2 $direction): never
  {
    $position = $subject === 'npc'
      ? $this->gameScene->npcManager?->findById(strval($this->command['npcId'] ?? ''))?->position
      : $this->gameScene->player?->position;
    $attemptedX = intval($position?->x ?? 0) + intval($direction->x);
    $attemptedY = intval($position?->y ?? 0) + intval($direction->y);
    $subjectLabel = $subject === 'npc'
      ? sprintf('NPC "%s"', strval($this->command['npcId'] ?? ''))
      : 'player';

    throw new MovementRouteException(sprintf(
      'Movement route failed on map "%s": %s step %d (%s) was blocked at (%d, %d).',
      $this->gameScene->currentMapId,
      $subjectLabel,
      $this->stepIndex + 1,
      $directionName,
      $attemptedX,
      $attemptedY,
    ));
  }

  public static function directionVector(string $direction): Vector2
  {
    return match (strtolower(trim($direction))) {
      'up' => Vector2::up(),
      'down' => Vector2::down(),
      'left' => Vector2::left(),
      'right' => Vector2::right(),
      default => throw new MovementRouteException(sprintf('Unsupported movement-route direction "%s".', $direction)),
    };
  }
}
