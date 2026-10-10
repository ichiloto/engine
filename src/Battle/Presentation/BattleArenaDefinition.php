<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use InvalidArgumentException;

/** Scene artwork only. Formations and battle geometry have independent owners. */
final readonly class BattleArenaDefinition
{
  public function __construct(
    public string $name,
    public CanvasImage $background,
    public ?BattleUiSkin $skin = null,
  ) {
    if (trim($name) === '' || preg_match('/\p{Cc}/u', $name) !== 0) {
      throw new InvalidArgumentException('Battle arena display name must be nonempty text without control characters.');
    }
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
  }
}
