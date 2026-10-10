<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** Capability selection precedes depth and clearing in every effect consumer. */
final class EffectSegmentComposition
{
  /** @param list<array<string, mixed>> $segments @return list<array<string, mixed>> */
  public static function compose(array $segments, ?EffectPresentation $presentation = null): array
  {
    if ($presentation !== null) {
      $segments = array_values(array_filter($segments, $presentation->acceptsSegment(...)));
    }
    usort($segments, static fn(array $a, array $b): int => ($a['drawCommands'][0]['zIndex'] ?? 0)
      <=> ($b['drawCommands'][0]['zIndex'] ?? 0));
    $visible = [];
    foreach ($segments as $segment) {
      if ($segment['clearBeforeDraw'] ?? false) { $visible = []; }
      $visible[] = $segment;
    }
    return $visible;
  }
}
