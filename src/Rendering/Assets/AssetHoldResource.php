<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use InvalidArgumentException;

/** Pixel preparation shared by owner leases, frozen-frame handoffs and renderer admission. */
final readonly class AssetHoldResource
{
  public function __construct(public string $asset, public ?SpriteSourceRect $sourceRect = null,
    public AssetHoldBase $base = new AssetHoldBase(), public ?AssetHoldDrawSize $drawSize = null,
    public ?AssetHoldCanvas $canvas = null)
  {
    AssetHoldValidation::readAsset($asset);
    if ($drawSize === null && $canvas !== null) {
      AssetHoldValidation::reject(AssetHoldRejection::INVALID_DRAW_SIZE, 'Canvas context requires a drawing extent.');
    }
    if ($drawSize !== null && $canvas === null) {
      AssetHoldValidation::reject(AssetHoldRejection::INVALID_CANVAS, 'A display-size copy requires its logical canvas context.');
    }
  }

  public static function fromArray(array $value): self
  {
    AssetHoldValidation::assertKeys($value, [], ['asset', 'sourceRect', 'base', 'drawSize', 'canvas']);
    $asset = AssetHoldValidation::readAsset($value['asset'] ?? null);
    $source = null;
    if (array_key_exists('sourceRect', $value)) {
      $reason = AssetHoldRejection::INVALID_RECT;
      $rect = AssetHoldValidation::readObject($value['sourceRect'], $reason);
      AssetHoldValidation::assertKeys($rect, ['x', 'y', 'width', 'height'], [], $reason);
      foreach ($rect as $coordinate) { AssetHoldValidation::readInteger($coordinate, 0, 4294967295, $reason); }
      try { $source = new SpriteSourceRect($rect['x'], $rect['y'], $rect['width'], $rect['height']); }
      catch (InvalidArgumentException $error) { AssetHoldValidation::reject($reason, $error->getMessage()); }
    }
    return new self($asset, $source, array_key_exists('base', $value)
      ? AssetHoldBase::fromArray(AssetHoldValidation::readObject($value['base'], AssetHoldRejection::INVALID_BASE))
      : new AssetHoldBase(), array_key_exists('drawSize', $value)
        ? AssetHoldDrawSize::fromArray(AssetHoldValidation::readObject($value['drawSize'], AssetHoldRejection::INVALID_DRAW_SIZE)) : null,
      array_key_exists('canvas', $value)
        ? AssetHoldCanvas::fromArray(AssetHoldValidation::readObject($value['canvas'], AssetHoldRejection::INVALID_CANVAS)) : null);
  }

  public static function fromCanvasImage(CanvasImage $image, ?AssetHoldCanvas $canvas = null): self
  {
    return new self($image->asset, $image->sourceRect, AssetHoldBase::fromCanvasImage($image),
      $canvas === null ? null : new AssetHoldDrawSize($image->destination->width, $image->destination->height), $canvas);
  }

  public function toArray(): array
  {
    return ['asset' => $this->asset, ...($this->sourceRect === null ? [] : ['sourceRect' => $this->sourceRect->toArray()]),
      ...($this->base->isIdentity ? [] : ['base' => $this->base->toArray()]),
      ...($this->drawSize === null ? [] : ['drawSize' => $this->drawSize->toArray(), 'canvas' => $this->canvas->toArray()])];
  }
  /** Shared descriptor identity for preparation collection; never artwork bytes or a gameplay identity. */
  public function getKey(): string
  {
    return hash('sha256', serialize($this->toArray()));
  }

}
