<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use InvalidArgumentException;

/**
 * Scene artwork and the ground its background shows. Formations belong to
 * troops and the theme; the arena only says where on its picture battlers
 * may stand: the band of ground ({@see BattleGround}) and the land and
 * water footing within it ({@see BattleFooting}), so any formation stands on
 * any arena; and how its light falls on battlers' contact shadows
 * ({@see BattleShadowLight}).
 */
final readonly class BattleArenaDefinition
{
  public function __construct(
    public string $name,
    public CanvasImage $background,
    public ?BattleUiSkin $skin = null,
    public ?BattleGround $ground = null,
    public BattleFooting $footing = new BattleFooting(),
    public ?BattleShadowLight $shadowLight = null,
  ) {
    if (trim($name) === '' || preg_match('/\p{Cc}/u', $name) !== 0) {
      throw new InvalidArgumentException('Battle arena display name must be nonempty text without control characters.');
    }
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
  }
}
