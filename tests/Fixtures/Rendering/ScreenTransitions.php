<?php

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;

/** Two authored appearances use the same runtime lifecycle and drawing primitives. */
function getScreenTransitionFixture(bool $diagonal = true): array
{
  $color = static fn($hex) => PresentationColor::rgb(hexdec(substr($hex, 0, 2)),
    hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)))->toArray();
  $bounds = ['x' => 0, 'y' => 0, 'width' => 1600, 'height' => 900];
  $brush = ['type' => 'linear', 'start' => [0, 0], 'end' => [1600, 900], 'stops' => [
    ['offset' => 0, 'color' => $color($diagonal ? '1C304A' : '193C2B')],
    ['offset' => .5, 'color' => $color($diagonal ? '101D31' : '10251B')],
    ['offset' => 1, 'color' => $color($diagonal ? '080F1D' : '08120D')],
  ]];
  $gather = ['operation' => ['type' => 'fill', 'destination' => $bounds,
    'brush' => ['type' => 'solid', 'color' => $color('101D31')], 'opacity' => 0],
    'tweens' => [['path' => ['opacity'], 'to' => .14]]];
  $skew = $diagonal ? 230 : 0;
  $getSweep = static function (bool $reveal) use ($skew, $brush, $bounds, $color): array {
    $top = -260 + $skew;
    $bottom = -260 - $skew;
    $edge = $reveal ? 3200 : -1600;
    $tracks = [['operation' => ['type' => 'fill', 'destination' => $bounds, 'brush' => $brush,
      'masks' => [['type' => 'polygon', 'contours' => [[[$edge, 0], [$top, 0], [$bottom, 900], [$edge, 900]]]]]],
      'easing' => 'smoothstep', 'tweens' => [
        ['path' => ['masks', 0, 'contours', 0, 1, 0], 'to' => 1860 + $skew],
        ['path' => ['masks', 0, 'contours', 0, 2, 0], 'to' => 1860 - $skew],
      ]]];
    foreach ([[0, 4, 'F0D49B'], [12, 2, 'B89A62']] as [$offset, $width, $hex]) {
      $tracks[] = ['operation' => ['type' => 'stroke',
        'points' => [[$top + $offset, 0], [$bottom + $offset, 900]], 'width' => $width,
        'brush' => ['type' => 'solid', 'color' => $color($hex)]], 'easing' => 'smoothstep', 'tweens' => [
          ['path' => ['points', 0, 0], 'to' => 1860 + $skew + $offset],
          ['path' => ['points', 1, 0], 'to' => 1860 - $skew + $offset],
        ]];
    }
    return $tracks;
  };
  return ['id' => $diagonal ? 'gilded-sweep' : 'forest-shutter', 'width' => 1600, 'height' => 900,
    'timings' => ['gather' => 100, 'cover' => 260, 'hold' => 80, 'reveal' => 380],
    'coverBrush' => $brush, 'phases' => ['gather' => [$gather], 'cover' => $getSweep(false), 'reveal' => $getSweep(true)]];
}
