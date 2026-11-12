<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasLabel;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasNineSlice;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Presentation\Text\TextMeasurement;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** Replaceable board intent; source facts are read from the current PNG, not authored twice. */
final readonly class WorldSignStyle
{
  public array $padding;

  public function __construct(public string $asset, public string $fontRole,
    public int $left, public int $right, public int $density, array $padding,
    public int $minimumWidth, public int $maximumWidth, public int $layer = 0,
    public ?int $repeatWidth = null)
  {
    SpriteValidation::validateAssetPath($asset);
    \Ichiloto\Engine\Rendering\Presentation\Text\ProjectTextCatalog::assertRoleId($fontRole);
    if (strlen($asset) > 4096 || min($left, $right) < 0 || max($left, $right) > CanvasValidation::MAX_EXTENT
      || !in_array($density, [1, 2], true) || $minimumWidth < 1 || $minimumWidth > $maximumWidth
      || $maximumWidth > 8192 || !PresentationLayerPolicy::isFieldWorldLayer($layer)
      || ($repeatWidth !== null && ($repeatWidth < 1 || $repeatWidth > CanvasValidation::MAX_EXTENT))
      || !array_is_list($padding) || count($padding) !== 4
      || array_any($padding, static fn($v) => !is_int($v) || $v < 0 || $v > 8192)
      || $padding[0] + $padding[2] >= $maximumWidth) {
      throw new InvalidArgumentException('Sign style requires bounded fixed caps, density, four logical padding values, widths and a field layer.');
    }
    $this->padding = array_map(static fn(int $value): int => $value, $padding);
  }

  public static function fromArray(mixed $data): self
  {
    $required = ['asset', 'fontRole', 'left', 'right', 'density', 'padding', 'minimumWidth', 'maximumWidth'];
    if (!is_array($data) || array_diff($required, array_keys($data)) !== []
      || array_diff(array_keys($data), [...$required, 'layer', 'repeatWidth']) !== []
      || !is_string($data['asset']) || !is_string($data['fontRole']) || !is_array($data['padding'])
      || array_any(['left', 'right', 'density', 'minimumWidth', 'maximumWidth'], static fn($key) => !is_int($data[$key]))
      || (array_key_exists('layer', $data) && !is_int($data['layer']))
      || (array_key_exists('repeatWidth', $data) && !is_int($data['repeatWidth']))) {
      throw new InvalidArgumentException('Sign styles require the explicit board/font and layout fields.');
    }
    return new self($data['asset'], $data['fontRole'], $data['left'], $data['right'], $data['density'],
      $data['padding'], $data['minimumWidth'], $data['maximumWidth'], $data['layer'] ?? 0, $data['repeatWidth'] ?? null);
  }

  public function getBoard(string $assetRoot): CanvasNineSlice
  {
    $board = CanvasNineSlice::getFromPng($assetRoot, $this->asset, left: $this->left, right: $this->right, density: $this->density);
    if ($board->source->height / $this->density <= $this->padding[1] + $this->padding[3]
      || ($board->left + $board->right) / $this->density > $this->maximumWidth) {
      throw new InvalidArgumentException('Current sign artwork cannot fit the authored padding or width limit.');
    }
    return $board;
  }

  public function getFitRatio(CanvasNineSlice $board, TextMeasurement $text): float
  {
    $bounds = $text->metrics->paintBounds;
    return min(1.0, $bounds->width === 0.0 ? 1.0 : ($this->maximumWidth - $this->padding[0] - $this->padding[2]) / $bounds->width,
      $bounds->height === 0.0 ? 1.0 : ($board->source->height / $this->density - $this->padding[1] - $this->padding[3]) / $bounds->height);
  }

  /** Center expanded ink, including signed bearings/effects; grain and caps are never stretched. */
  public function composeCanvas(CanvasNineSlice $board, TextMeasurement $text, PresentationSpritePivot $pivot): array
  {
    if ($this->getFitRatio($board, $text) < 1.0) {
      throw new InvalidArgumentException('Prepared label does not fit its sign board.');
    }
    $bounds = $text->metrics->paintBounds;
    $width = (int)ceil(max($this->minimumWidth, ($board->left + $board->right) / $this->density,
      $bounds->width + $this->padding[0] + $this->padding[2]));
    $height = $board->source->height / $this->density;
    $middle = $board->source->width - $board->left - $board->right;
    $sample = min($this->repeatWidth ?? $middle, $middle);
    // A full-density tail may extend past a small cap; transparent logical margin owns that extent.
    $logicalWidth = (int)ceil($width + max(0, ($sample - $board->right) / $this->density));
    $logicalHeight = (int)ceil($height);
    $images = $board->composeRepeatedXImages('board', new CanvasRectangle(0, 0, $width, $height), 0,
      repeatSource: new SpriteSourceRect($board->source->x + $board->left, $board->source->y, $sample, $board->source->height));
    $x = $this->padding[0] + ($width - $this->padding[0] - $this->padding[2] - $bounds->width) / 2 - $bounds->x;
    $y = $this->padding[1] + ($height - $this->padding[1] - $this->padding[3] - $bounds->height) / 2 - $bounds->y;
    return [new PresentationCanvas($logicalWidth, $logicalHeight, images: $images,
      labels: [new CanvasLabel('label', $text, $x, $y, 1)]),
      new PresentationSpritePivot($pivot->x * $width / $logicalWidth, $pivot->y * $height / $logicalHeight)];
  }
}
