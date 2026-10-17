<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Field\Enumerations\CompassDirection;

/** A directed physical transfer retains its own source landmark and knowledge. */
final readonly class RegionRoute
{
  public function __construct(
    public string $sourceId,
    public string $destinationId,
    public MapLandmarkDefinition $landmark,
    public ?CompassDirection $direction,
  ) {}

  public function isVisible(GameState $state): bool
  {
    return !$this->landmark->secret || $state->hasRevealedLandmark($this->sourceId, $this->landmark->id);
  }

  public function getKnownName(GameState $state): ?string
  {
    return $state->hasRevealedLandmark($this->sourceId, $this->landmark->id)
      ? $this->landmark->name?->getText() : null;
  }
}
