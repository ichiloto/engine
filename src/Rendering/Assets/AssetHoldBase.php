<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Assets;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImageBottomFade;
use InvalidArgumentException;

/** The renderer's reusable pixel preparation; layout, opacity and colour matrices remain drawing state. */
final class AssetHoldBase
{
  public readonly float $brightness;

  public function __construct(float $brightness = 1.0, public readonly bool $flipX = false, public readonly bool $flipY = false,
    public readonly int $rotation = 0, public readonly ?CanvasImageBottomFade $bottomFade = null)
  {
    $this->brightness = AssetHoldValidation::readNumber($brightness, 0, 1, AssetHoldRejection::INVALID_BASE);
    AssetHoldValidation::readInteger($rotation, 0, 359, AssetHoldRejection::INVALID_BASE);
    if ($bottomFade !== null && $rotation !== 0) {
      AssetHoldValidation::reject(AssetHoldRejection::INVALID_BASE, 'Bottom fade requires zero rotation.');
    }
  }

  public bool $isIdentity {
    get => $this->brightness === 1.0 && !$this->flipX && !$this->flipY
      && $this->rotation === 0 && $this->bottomFade === null;
  }

  public static function fromArray(array $value): self
  {
    $reason = AssetHoldRejection::INVALID_BASE;
    AssetHoldValidation::assertKeys($value, ['brightness', 'flipX', 'flipY', 'rotation'], ['bottomFade'], $reason);
    $fade = null;
    if (array_key_exists('bottomFade', $value)) {
      $data = AssetHoldValidation::readObject($value['bottomFade'], $reason);
      try { $fade = CanvasImageBottomFade::fromArray($data); }
      catch (InvalidArgumentException $error) { AssetHoldValidation::reject($reason, $error->getMessage()); }
    }
    return new self(AssetHoldValidation::readNumber($value['brightness'], 0, 1, $reason),
      AssetHoldValidation::readBoolean($value['flipX'], $reason), AssetHoldValidation::readBoolean($value['flipY'], $reason),
      AssetHoldValidation::readInteger($value['rotation'], 0, 359, $reason), $fade);
  }

  public static function fromCanvasImage(CanvasImage $image): self
  {
    return new self($image->brightness, $image->flipX, $image->flipY, $image->rotation ?? 0, $image->bottomFade);
  }

  public function toArray(): array
  {
    return ['brightness' => $this->brightness, 'flipX' => $this->flipX, 'flipY' => $this->flipY,
      'rotation' => $this->rotation, ...($this->bottomFade === null ? [] : ['bottomFade' => $this->bottomFade->toArray()])];
  }
}
