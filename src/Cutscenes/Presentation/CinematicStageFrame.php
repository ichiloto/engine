<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

/** One immutable frame transform shared by every stage image and attachment. */
final readonly class CinematicStageFrame
{
  public function __construct(public CinematicStage $stage, public bool $active, public int $contentFrame,
    public array $camera, public array $cover, public bool $drawsContent) {}

  public function getImagePlacement(array $placement, array $pivot, array $offset, int $width, int $height): array
  {
    $scale = min($width / $this->stage->data['canvas']['width'], $height / $this->stage->data['canvas']['height'])
      * $this->camera['zoom'];
    $point = isset($placement['subject'])
      ? $this->stage->getSubjectPoint($placement['subject'], $placement['attachment'] ?? null) : ['x' => 0, 'y' => 0];
    $x = $point['x'] + $placement['position']['x'] + $offset['x'];
    $y = $point['y'] + $placement['position']['y'] + $offset['y'];
    return ['x' => $width / 2 + ($x - $this->camera['focus']['x'] - $placement['size']['width'] * $pivot['x']) * $scale,
      'y' => $height / 2 + ($y - $this->camera['focus']['y'] - $placement['size']['height'] * $pivot['y']) * $scale,
      'width' => $placement['size']['width'] * $scale, 'height' => $placement['size']['height'] * $scale];
  }
}
