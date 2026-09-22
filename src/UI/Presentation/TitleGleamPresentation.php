<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasComposite;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeOperation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasCompositeValues as V;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use InvalidArgumentException;

/** A periodically resting sweep, clipped to current artwork alpha and authored regions. */
final class TitleGleamPresentation
{
  public static function getComposite(CanvasImage $logo, array $data, float $time, bool $reduced): ?CanvasComposite
  {
    V::validateKeys($data, ['delay', 'period', 'duration', 'overscan', 'bandWidth', 'tilt', 'regions', 'stops']);
    $delay = V::getNumber($data['delay'], 0, 86400);
    $period = TitleMotionValues::getPositive($data['period']);
    $duration = TitleMotionValues::getPositive($data['duration']);
    if ($duration > $period) { throw new InvalidArgumentException('Title gleam duration must fit its period.'); }
    $overscan = V::getNumber($data['overscan'], 0, 4096);
    $band = TitleMotionValues::getPositive($data['bandWidth'], 4096);
    $tilt = V::getNumber($data['tilt'], -1, 1);
    $stops = TitleMotionValues::getStops($data['stops']);
    $width = (int)ceil($logo->destination->width); $height = (int)ceil($logo->destination->height);
    $rect = ['x' => 0, 'y' => 0, 'width' => $width, 'height' => $height];
    $contours = [];
    foreach (V::getList($data['regions'], 1, 8) as $region) {
      [$x, $y, $w, $h] = V::getList($region, 4, 4);
      $normalized = V::getRectangle(['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h], true);
      $x = $normalized['x'] * $width; $y = $normalized['y'] * $height;
      $w = $normalized['width'] * $width; $h = $normalized['height'] * $height;
      $contours[] = [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]];
    }
    $phase = fmod(fmod($time - $delay, $period) + $period, $period);
    $x = -$overscan + min(1, $phase / $duration) * ($width + 2 * $overscan);
    $backdrop = new CanvasCompositeOperation(['type' => 'image', 'asset' => $logo->asset, 'destination' => $rect]);
    $sweep = new CanvasCompositeOperation(['type' => 'fill', 'destination' => $rect, 'blend' => 'screen',
      'brush' => ['type' => 'linear', 'start' => [$x - $band / 2, 0], 'end' => [$x + $band / 2, $height * $tilt], 'stops' => $stops],
      'masks' => [['type' => 'image_alpha', 'asset' => $logo->asset, 'destination' => $rect],
        ['type' => 'polygon', 'contours' => $contours]]]);
    if ($reduced || $phase >= $duration) { return null; }
    return new CanvasComposite($logo->id, $width, $height, $logo->destination,
      [$backdrop, $sweep], $logo->layer, $logo->opacity);
  }
}
