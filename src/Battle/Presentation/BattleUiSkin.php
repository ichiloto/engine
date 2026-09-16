<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Optional project-owned battle artwork and palette, never saved gameplay state. */
final readonly class BattleUiSkin
{
  /** @var array<string, CanvasNineSlice> */
  public array $textures;
  /** @var array<string, PresentationColor> */
  public array $colors;

  public function __construct(array $textures, array $colors, public ?BattleTargetCursor $targetCursor = null)
  {
    $this->textures = self::roles($textures, CanvasNineSlice::class,
      ['panel', 'quiet', 'track', 'hp', 'mp', 'atb', 'selector', 'target', 'queued', 'acting']);
    $this->colors = self::roles($colors, PresentationColor::class,
      ['text', 'muted', 'selected', 'focus', 'disabled', 'damage', 'healing', 'mp', 'ink']);
  }

  private static function roles(array $entries, string $type, array $roles): array
  {
    if (count($entries) !== count($roles)) {
      throw new InvalidArgumentException('Battle UI skin must declare exactly its supported roles.');
    }
    $copy = [];
    foreach ($roles as $role) {
      if (!($entries[$role] ?? null) instanceof $type) {
        throw new InvalidArgumentException("Battle UI skin requires a typed {$role} entry.");
      }
      $copy[$role] = $entries[$role];
    }
    return $copy;
  }
}
