<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Optional project bindings. No authored source sizes and no display-name/glyph inference. */
final readonly class MenuIconRegistry
{
  /** @var array<string, string> */
  public array $icons;

  /** @param array<string, string> $icons Semantic keys such as weapon.staff, slot.head, with optional unknown fallback. */
  public function __construct(public string $assetRoot, array $icons, public ?string $cursor = null)
  {
    $copy = [];
    foreach ($icons as $semantic => $asset) {
      if (!is_string($semantic) || !is_string($asset)) {
        throw new InvalidArgumentException('Menu icon bindings require semantic keys and asset paths.');
      }
      CanvasValidation::id($semantic);
      SpriteValidation::validateAssetPath($asset);
      $copy[$semantic] = $asset;
    }
    if ($cursor !== null) { SpriteValidation::validateAssetPath($cursor); }
    $this->icons = $copy;
  }

  public function asset(WeaponType|EquipmentSlotType|string|null $metadata): ?string
  {
    if ($metadata === null) { return null; }
    $semantic = match (true) {
      $metadata instanceof WeaponType => 'weapon.' . strtolower($metadata->value),
      $metadata instanceof EquipmentSlotType => 'slot.' . $metadata->value,
      default => $metadata,
    };
    return $this->icons[$semantic] ?? $this->icons['unknown'] ?? null;
  }

  /** Full-source contain uses a zero-cut CanvasNineSlice, never stretches or slices an icon.
   * @return list<CanvasImage>
   */
  public function contain(string $id, string $asset, CanvasRectangle $box, int $layer, CanvasRectangle $clip): array
  {
    return self::containAsset($this->assetRoot, $id, $asset, $box, $layer, $clip);
  }

  /** @return list<CanvasImage> */
  public static function containAsset(string $root, string $id, string $asset, CanvasRectangle $box, int $layer, CanvasRectangle $clip): array
  {
    $art = CanvasNineSlice::fromPng($root, $asset);
    $scale = min($box->width / $art->source->width, $box->height / $art->source->height);
    $width = $art->source->width * $scale;
    $height = $art->source->height * $scale;
    return $art->images($id, new CanvasRectangle($box->x + ($box->width - $width) / 2,
      $box->y + ($box->height - $height) / 2, $width, $height), $layer, $clip);
  }
}
