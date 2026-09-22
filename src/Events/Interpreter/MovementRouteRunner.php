<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Cutscenes\Cinematics\StagedActor;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Accessibility;

/**
 * Executes one sequential, collision-aware player or NPC movement route.
 */
final class MovementRouteRunner implements EventPendingOperationInterface
{
  public const array DIRECTIONS = ['up', 'down', 'left', 'right'];

  /** @var array<int, array<string, mixed>> */
  protected array $steps;
  protected int $stepIndex = 0;
  protected int $completedRepeats = 0;
  protected float $remainingDelay = 0.0;
  protected(set) bool $isComplete = false;
  /** @var list<array{x?: int, y?: int}> */
  private array $waypoints = [];
  private Player|Npc|StagedActor $routeSubject;
  private Vector2 $expectedPosition;
  private string $mapId;
  private int $generation;
  private ?MovementRouteRecord $record = null;
  private ?MovementRouteRecord $returnRecord = null;

  /**
   * @param array<string, mixed> $command The authored `move_route` command.
   */
  public function __construct(
    protected GameScene $gameScene,
    protected array $command,
    private readonly ?EventExecutionSession $session = null,
  )
  {
    $this->steps = array_values(array_filter((array) ($command['steps'] ?? []), is_array(...)));
    $this->validate();
    $this->routeSubject = $this->resolveSubject()
      ?? throw new MovementRouteException(sprintf(
        'Movement-route %s is not available on map "%s".',
        $this->subjectLabel(),
        $gameScene->currentMapId,
      ));
    $this->expectedPosition = clone $this->routeSubject->position;
    $this->mapId = $gameScene->currentMapId;
    $this->generation = $gameScene->cinematicStage?->generation ?? 0;
    if (isset($command['remember']) || isset($command['retrace'])) {
      if ($session?->cinematic === null || $this->routeSubject instanceof StagedActor) {
        throw new MovementRouteException('Recorded routes require a real subject in an active cinematic session.');
      }
      if (isset($command['retrace'])) {
        $this->returnRecord = $session->movementRoute($command['retrace']);
        $this->steps = $this->returnRecord->retrace(
          $this->mapId, $this->routeSubject, $this->generation);
      } else {
        $this->record = new MovementRouteRecord($this->mapId, $this->routeSubject, $this->generation);
        $session->rememberMovementRoute($command['remember'], $this->record);
      }
    }
    $this->waypoints = $command['waypoints'] ?? [];
    if ($this->waypoints !== []) {
      $this->prepareNextLeg();
    }
    $session?->claimMovementSubject($this->routeSubject);
    if ($session?->cinematic !== null && !$this->routeSubject instanceof StagedActor) {
      $gameScene->cinematicStage?->captureSubject($this->routeSubject);
    }
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

    if (Accessibility::prefersReducedMotion()) {
      $maximumUnits = 1 + count($this->waypoints)
        * MovementRoutePlanner::MAX_VISITED_CELLS;

      foreach ($this->steps as $step) {
        $maximumUnits += max(1, intval($step['count'] ?? 1));
      }

      for ($unit = 0; $unit < $maximumUnits && ! $this->isComplete; $unit++) {
        $this->remainingDelay = 0.0;
        $this->advanceVisibleUnit();
      }

      return $this->isComplete;
    }

    $this->remainingDelay = max(0.0, $this->remainingDelay - max(0.0, $deltaSeconds));

    if ($this->remainingDelay > 0.0) {
      return false;
    }

    return $this->advanceVisibleUnit();
  }

  /** Advances one authored route unit after its delay has elapsed. */
  protected function advanceVisibleUnit(): bool
  {
    if ($this->isComplete) {
      return true;
    }

    $this->assertSubjectCurrent();

    $step = $this->steps[$this->stepIndex] ?? null;

    if (! is_array($step)) {
      $this->finish();
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
      'staged_actor' => $this->gameScene->cinematicStage?->move(
        strval($this->command['actorId'] ?? ''),
        $direction,
        boolval($faceOnly),
      ) ?? false,
      default => false,
    };

    if (! $succeeded) {
      $this->throwBlockedRoute($subject, $directionName, $direction);
    }

    $this->assertSubjectCurrent(checkPosition: false);
    if (!$faceOnly) {
      $expected = Vector2::sum($this->expectedPosition, $direction);
      if ($this->routeSubject->position->x !== $expected->x || $this->routeSubject->position->y !== $expected->y) {
        throw new MovementRouteException('Movement-route subject was displaced during a step.');
      }
      $this->expectedPosition = $expected;
      $this->record?->record($directionName);
    }

    $this->completedRepeats++;

    if ($this->completedRepeats >= $count || $faceOnly) {
      $this->advanceStep();
    }

    $this->remainingDelay = $this->stepDelay($step);

    return $this->isComplete;
  }

  public function cancel(): void
  {
    if ($this->isComplete) {
      return;
    }
    $this->isComplete = true;
    $this->session?->releaseMovementSubject($this->routeSubject);
  }

  private function finish(): void
  {
    if ($this->returnRecord !== null) {
      $this->returnRecord->restoreFacing();
      $this->gameScene->requestFieldPresentationReconciliation();
    }
    if ($this->record !== null) {
      $this->record->complete = true;
    }
    $this->cancel();
  }

  private function resolveSubject(): Player|Npc|StagedActor|null
  {
    return match (strtolower(trim(strval($this->command['subject'] ?? 'player')))) {
      'npc' => $this->gameScene->npcManager?->findById(strval($this->command['npcId'] ?? '')),
      'staged_actor' => $this->gameScene->cinematicStage?->find(strval($this->command['actorId'] ?? '')),
      default => $this->gameScene->player,
    };
  }

  private function subjectLabel(): string
  {
    return match (strtolower(trim(strval($this->command['subject'] ?? 'player')))) {
      'npc' => sprintf('NPC "%s"', strval($this->command['npcId'] ?? '')),
      'staged_actor' => sprintf('staged actor "%s"', strval($this->command['actorId'] ?? '')),
      default => 'player',
    };
  }

  private function assertSubjectCurrent(bool $checkPosition = true): void
  {
    if ($this->mapId !== $this->gameScene->currentMapId || $this->resolveSubject() !== $this->routeSubject
      || $this->generation !== ($this->gameScene->cinematicStage?->generation ?? 0)
      || ($checkPosition && ($this->routeSubject->position->x !== $this->expectedPosition->x
        || $this->routeSubject->position->y !== $this->expectedPosition->y))) {
      throw new MovementRouteException('Movement-route subject, map or position changed outside its owned route.');
    }
  }

  private function prepareNextLeg(): void
  {
    $this->steps = [];
    $this->stepIndex = 0;
    while ($this->steps === [] && $this->waypoints !== []) {
      $point = array_shift($this->waypoints);
      $target = new Vector2($point['x'] ?? $this->routeSubject->position->x,
        $point['y'] ?? $this->routeSubject->position->y);
      $this->steps = MovementRoutePlanner::plan($this->gameScene, $this->routeSubject->position,
        $target, $this->routeSubject instanceof Npc);
    }
  }

  /** Shared extension validation for runtime and cinematic authoring. */
  public static function validatePathOptions(array $command): void
  {
    $modes = array_intersect(['steps', 'waypoints', 'retrace'], array_keys($command));
    if (count($modes) !== 1) {
      throw new MovementRouteException('Movement route requires exactly one of steps, waypoints or retrace.');
    }
    foreach (['remember', 'retrace'] as $field) {
      if (array_key_exists($field, $command) && (!is_string($command[$field])
        || preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]*$/', $command[$field]) !== 1)) {
        throw new MovementRouteException("Movement route $field requires a safe stable id.");
      }
    }
    if (isset($command['remember'], $command['retrace'])) {
      throw new MovementRouteException('A retraced route cannot also be remembered.');
    }
    if ((isset($command['waypoints']) || isset($command['remember']) || isset($command['retrace']))
      && strtolower(trim(strval($command['subject'] ?? 'player'))) === 'staged_actor') {
      throw new MovementRouteException('Waypoints and recorded routes require a real player or NPC subject.');
    }
    if (!array_key_exists('waypoints', $command)) {
      return;
    }
    $points = $command['waypoints'];
    if (!is_array($points) || !array_is_list($points) || $points === []) {
      throw new MovementRouteException('Movement-route waypoints must be a non-empty list.');
    }
    foreach ($points as $point) {
      if (!is_array($point) || $point === [] || array_diff(array_keys($point), ['x', 'y']) !== []) {
        throw new MovementRouteException('Each movement-route waypoint requires x and/or y.');
      }
      foreach ($point as $value) {
        if (!is_int($value) || $value < 0) {
          throw new MovementRouteException('Movement-route waypoint coordinates must be non-negative integers.');
        }
      }
    }
  }

  protected function validate(): void
  {
    self::validatePathOptions($this->command);
    $wait = $this->command['wait'] ?? true;

    if (! is_bool($wait) || ! $wait) {
      throw new MovementRouteException('move_route.wait must be true; use separate cinematic parallel lanes for concurrent routes.');
    }

    $subject = strtolower(trim(strval($this->command['subject'] ?? 'player')));

    if (! in_array($subject, ['player', 'npc', 'staged_actor'], true)) {
      throw new MovementRouteException(sprintf('Unsupported movement-route subject "%s".', $subject));
    }

    if ($subject === 'npc' && trim(strval($this->command['npcId'] ?? '')) === '') {
      throw new MovementRouteException('An NPC movement route requires a stable npcId.');
    }

    if ($subject === 'staged_actor' && trim(strval($this->command['actorId'] ?? '')) === '') {
      throw new MovementRouteException('A staged-actor movement route requires a stable actorId.');
    }

    $this->stepDelay([]);
    if (!array_key_exists('steps', $this->command)) {
      return;
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
      if (! is_numeric($seconds) || !is_finite(floatval($seconds)) || floatval($seconds) < 0.0) {
        throw new MovementRouteException('Movement-route timing must be zero or a positive number of seconds.');
      }

      return floatval($seconds);
    }

    $speed = $this->command['speed'] ?? null;

    if ($speed !== null) {
      if (! is_numeric($speed) || !is_finite(floatval($speed)) || floatval($speed) <= 0.0) {
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
    if ($this->stepIndex >= count($this->steps)) {
      if ($this->waypoints !== []) {
        $this->prepareNextLeg();
      }
      if ($this->stepIndex >= count($this->steps)) {
        $this->finish();
      }
    }
  }

  protected function throwBlockedRoute(string $subject, string $directionName, Vector2 $direction): never
  {
    $position = match ($subject) {
      'npc' => $this->gameScene->npcManager?->findById(strval($this->command['npcId'] ?? ''))?->position,
      'staged_actor' => $this->gameScene->cinematicStage?->find(strval($this->command['actorId'] ?? ''))?->position,
      default => $this->gameScene->player?->position,
    };
    $attemptedX = intval($position?->x ?? 0) + intval($direction->x);
    $attemptedY = intval($position?->y ?? 0) + intval($direction->y);
    $blockerDetail = $subject === 'staged_actor'
      ? $this->gameScene->cinematicStage?->lastMoveFailure
      : null;

    throw new MovementRouteException(sprintf(
      'Movement route failed on map "%s": %s step %d (%s) was blocked at (%d, %d).%s',
      $this->gameScene->currentMapId,
      $this->subjectLabel(),
      $this->stepIndex + 1,
      $directionName,
      $attemptedX,
      $attemptedY,
      $blockerDetail !== null ? ' ' . $blockerDetail : '',
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
