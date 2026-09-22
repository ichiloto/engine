<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Project-owned art, using the same typed pieces as HUD and Results. */
final readonly class BattlePauseSkin
{
  public const array TEXTURES = ['panel', 'normal', 'selected', 'pressed', 'focus', 'selector', 'divider'];
  public const array COLORS = ['text', 'muted', 'accent', 'ink'];

  /** @param array<string, CanvasNineSlice> $textures
   * @param array<string, PresentationColor> $colors
   */
  public function __construct(public array $textures, public array $colors)
  {
    foreach ([[self::TEXTURES, $textures, CanvasNineSlice::class], [self::COLORS, $colors, PresentationColor::class]] as [$roles, $entries, $type]) {
      if (count($entries) !== count($roles)) { throw new InvalidArgumentException('Pause skin requires exactly its supported roles.'); }
      foreach ($roles as $role) {
        if (!($entries[$role] ?? null) instanceof $type) { throw new InvalidArgumentException("Pause skin requires a typed {$role}."); }
      }
    }
    if (count(array_unique(array_map(static fn(CanvasNineSlice $art): int => $art->density, $textures))) !== 1) {
      throw new InvalidArgumentException('Pause skin must use one PNG density.');
    }
  }
}
