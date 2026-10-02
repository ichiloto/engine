<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackSession;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackUpdate;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\EffectSegmentComposition;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
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
    CompiledEffectTimeline $timeline, ?float $secondsPerFrame = null)
  {
    $this->playback = new EffectPlaybackSession($timeline, secondsPerFrame: $secondsPerFrame);
  }

  public function update(float $seconds, bool $reducedMotion = false): EffectPlaybackUpdate
  {
    if ($reducedMotion && $this->playback->isLooping) {
      return new EffectPlaybackUpdate(crossedCues: $seconds > 0 && !$this->playback->isPaused
        ? $this->playback->takeCurrentFrameCues() : []);
    }
    return $this->playback->update($seconds);
  }

  /** @param list<array<string, mixed>> $cues */
  public static function playCues(array $cues): void
  {
    foreach ($cues as $cue) {
      if (($cue['type'] ?? '') !== 'playSound') { continue; }
      $sound = $cue['payload']['sound'] ?? $cue['payload']['soundEffect'] ?? '';
      if (is_string($sound) && $sound !== '') { play_sound($sound); }
    }
  }

  /** Inspection never dispatches cues or advances playback. */
  public function getActiveSegments(bool $reducedMotion = false, ?EffectPresentation $presentation = null): array
  {
    if ($this->playback->isCompleted) { return []; }
    return EffectSegmentComposition::compose($this->playback->getActiveSegments($reducedMotion
      ? (int)($this->playback->timeline->defaults['restFrame'] ?? 0) : null), $presentation);
  }

  public function renderText(Camera $camera, Vector2 $origin, EffectPresentation $presentation,
    bool $reducedMotion = false): void
  {
    $commands = [];
    foreach ($this->getActiveSegments($reducedMotion, $presentation) as $segment) {
      if (!in_array($segment['layer'], ['glyph', 'text'], true)) { continue; }
      array_push($commands, ...$segment['drawCommands']);
    }
    usort($commands, static fn(array $a, array $b): int => ($a['zIndex'] ?? 0) <=> ($b['zIndex'] ?? 0));
    foreach ($commands as $command) {
      if (!($command['visible'] ?? true)) { continue; }
      $offset = $command['position'] ?? ['x' => 0, 'y' => 0];
      $base = ($command['payload']['anchor'] ?? 'target') === 'screen' ? new Vector2() : $origin;
      foreach (explode("\n", (string)($command['content'] ?? '')) as $row => $line) {
        $x = (int)$base->x + $offset['x'];
        $y = (int)$base->y + $offset['y'] + $row;
        if ($y < 0 || $y >= $camera->screen->getHeight()) { continue; }
        foreach (TerminalText::visibleSymbols($line) as $symbol) {
          $width = TerminalText::getSymbolWidth($symbol);
          if (trim($symbol) !== '' && $x >= 0 && $x + $width <= $camera->screen->getWidth()) {
            $color = $command['color'] ?? null;
            $camera->draw(is_string($color) && $color !== '' ? '<fg=' . $color . '>' . $symbol . '</>' : $symbol, $x, $y);
          }
          $x += $width;
        }
      }
    }
  }

  /** @return list<FieldEffectSprite> */
  public function getSprites(Vector2 $position, bool $reducedMotion = false, ?PresentationSpriteMotion $motion = null): array
  {
    if ($this->playback->isCompleted) { return []; }
    $sprites = [];
    foreach ($this->getActiveSegments($reducedMotion, EffectPresentation::GRAPHICAL) as $segment) {
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
