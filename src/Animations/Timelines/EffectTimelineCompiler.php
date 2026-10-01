<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Timelines;

/** Shared segment compilation. Consumer-specific metadata stays with its definition. */
final class EffectTimelineCompiler
{
  /** @param list<array<string, mixed>> $tracks @return list<array<string, mixed>> */
  public function compileTracks(array $tracks): array
  {
    $segments = [];
    foreach ($tracks as $track) {
      foreach ($track['keyframes'] as $keyframe) {
        $payload = $keyframe['payload'] ?? [];
        $segments[] = [
          'startFrame' => $keyframe['frame'],
          'endFrame' => $keyframe['frame'] + ($keyframe['duration'] ?? 1) - 1,
          'layer' => $track['type'],
          'drawCommands' => [[
            'trackId' => $track['id'],
            'position' => $keyframe['position'] ?? null,
            'content' => $keyframe['content'] ?? null,
            'assetId' => $keyframe['assetId'] ?? null,
            'color' => $keyframe['color'] ?? null,
            'visible' => $keyframe['visible'] ?? true,
            'zIndex' => $keyframe['zIndex'] ?? 0,
            'blendMode' => $keyframe['blendMode'] ?? null,
            'easing' => $keyframe['easing'] ?? null,
            'payload' => $payload,
          ]],
          'clearBeforeDraw' => (bool)($payload['clearBeforeDraw'] ?? false),
        ];
      }
    }
    usort($segments, static fn(array $a, array $b): int => [$a['startFrame'], $a['drawCommands'][0]['zIndex'], $a['layer']]
      <=> [$b['startFrame'], $b['drawCommands'][0]['zIndex'], $b['layer']]);
    return $segments;
  }
}
