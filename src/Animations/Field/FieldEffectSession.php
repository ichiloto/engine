<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteAnchor;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteMotion;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;

/** Field placement and reduced-motion policy around the shared timeline playhead. */
final class FieldEffectSession
{
  public readonly EffectPlaybackSession $playback;

  public function __construct(public readonly string $id, public readonly FieldEffectAnchor $anchor,
    CompiledEffectTimeline $timeline)
  {
    $this->playback = new EffectPlaybackSession($timeline);
  }

  public function update(float $seconds, bool $reducedMotion = false): void
  {
    if ($reducedMotion && $this->playback->isLooping) { return; }
    $this->playback->update($seconds);
  }

  /** @return list<FieldEffectSprite> */
  public function getSprites(Vector2 $position, bool $reducedMotion = false, ?PresentationSpriteMotion $motion = null): array
  {
    if ($this->playback->isCompleted) { return []; }
    $frame = $reducedMotion ? (int)($this->playback->timeline->defaults['restFrame'] ?? 0)
      : $this->playback->currentFrame;
    $sprites = [];
    foreach ($this->playback->getActiveSegments($frame) as $segment) {
      foreach ($segment['drawCommands'] as $command) {
        if ($segment['layer'] !== 'image' || !($command['visible'] ?? true)) { continue; }
        $data = $command['payload'];
        $source = $data['sourceFrame'];
        $definition = new GraphicalSpriteDefinition($command['assetId'],
          $data['cells']['width'] * FieldViewport::TILE_SIZE, $data['cells']['height'] * FieldViewport::TILE_SIZE,
          PresentationSpriteAnchor::BOTTOM_CENTER,
          $data['depth'] === 'behind' ? PresentationLayerPolicy::FIELD_EFFECT_BEHIND : PresentationLayerPolicy::FIELD_EFFECT_FRONT,
          new SpriteSourceRect(($source % $data['columns']) * $data['frameWidth'],
            intdiv($source, $data['columns']) * $data['frameHeight'], $data['frameWidth'], $data['frameHeight']));
        $offset = $command['position'] ?? ['x' => 0, 'y' => 0];
        $sprites[] = new FieldEffectSprite($this->id . ':' . $command['trackId'], $definition,
          new Vector2($position->x + $offset['x'], $position->y + $offset['y']), $motion);
      }
    }
    return $sprites;
  }
}
