<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Exceptions\MovementRouteException;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;

/** Session-local history of successful movement, never persisted as story state. */
final class MovementRouteRecord
{
  /** @var list<array{direction: string}> */
  private array $inverse = [];
  private Vector2 $endpoint;
  public bool $complete = false;
  private bool $retraced = false;
  private readonly MovementHeading $heading;
  private readonly array|string $sprite;

  public function __construct(public readonly string $mapId, public readonly Player|Npc $subject,
    private readonly int $generation)
  {
    $this->endpoint = clone $subject->position;
    $this->heading = $subject->heading;
    $this->sprite = $subject->sprite;
  }

  public function record(string $direction): void
  {
    $this->inverse[] = ['direction' => match ($direction) {
      'up' => 'down', 'down' => 'up', 'left' => 'right', 'right' => 'left',
    }];
    $this->endpoint = clone $this->subject->position;
  }

  /** @return list<array<string, mixed>> */
  public function retrace(string $mapId, Player|Npc $subject, int $generation): array
  {
    if (!$this->complete || $this->retraced || $this->mapId !== $mapId || $this->subject !== $subject
      || $this->generation !== $generation
      || $subject->position->x !== $this->endpoint->x || $subject->position->y !== $this->endpoint->y) {
      throw new MovementRouteException('A recorded route can only be retraced once, after completion, by its original subject from its endpoint on the same map.');
    }
    $this->retraced = true;
    return array_reverse($this->inverse);
  }

  public function restoreFacing(): void
  {
    // Keep the position reached by walking; restore even a directionless idle pose.
    if ($this->subject instanceof Player) {
      $this->subject->restoreFieldTransform(clone $this->subject->position, $this->heading, (array)$this->sprite);
    } else {
      $this->subject->restoreFieldTransform(clone $this->subject->position, $this->heading, strval($this->sprite));
    }
  }
}
