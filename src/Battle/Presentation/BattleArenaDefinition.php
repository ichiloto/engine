<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Tilesets\TerrainKind;
use InvalidArgumentException;

/**
 * Scene artwork and the ground its background shows. Formations belong to
 * troops and the theme; the arena only says where on its picture battlers
 * may stand: the band of ground ({@see BattleGround}) and the land and
 * water footing within it ({@see BattleFooting}), so any formation stands on
 * any arena; and how its light falls on battlers' contact shadows
 * ({@see BattleShadowLight}).
 *
 * An arena may stand for a whole region and name, for each encounter terrain
 * a battle there can start on (`woodland`, `desert`, ...), the arena drawn
 * for it: its terrain variants. A variant may name the arena itself, when
 * its own picture already shows that terrain.
 */
final readonly class BattleArenaDefinition
{
  /**
   * @param array<string, string> $variants Encounter terrain => the arena drawn for it, by catalog key.
   */
  public function __construct(
    public string $name,
    public CanvasImage $background,
    public ?BattleUiSkin $skin = null,
    public ?BattleGround $ground = null,
    public BattleFooting $footing = new BattleFooting(),
    public ?BattleShadowLight $shadowLight = null,
    public array $variants = [],
  ) {
    if (trim($name) === '' || preg_match('/\p{Cc}/u', $name) !== 0) {
      throw new InvalidArgumentException('Battle arena display name must be nonempty text without control characters.');
    }
    if ($background->layer >= 100) {
      throw new InvalidArgumentException('Arena background must be below battler layer 100.');
    }
    foreach ($variants as $terrain => $arena) {
      if (!is_string($terrain) || preg_match(TerrainKind::ENCOUNTER_PATTERN, $terrain) !== 1) {
        throw new InvalidArgumentException('Arena terrain variants are keyed by encounter terrain ids, such as woodland.');
      }
      if (!is_string($arena)) {
        throw new InvalidArgumentException("The {$terrain} variant must name an arena key.");
      }
      CanvasValidation::id($arena);
    }
  }
}
