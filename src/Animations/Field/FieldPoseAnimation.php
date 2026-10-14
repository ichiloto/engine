<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use InvalidArgumentException;

/** Authored field-image frames; geometry comes from the current asset, never gameplay. */
final class FieldPoseAnimation
{
  public const array FIELDS = ['columns', 'rows', 'frames', 'fps', 'loop', 'restFrame'];
  public const int MAX_GRID_AXIS = 64;
  public const int MAX_FRAMES = 10000;
  public const int MAX_FPS = 120;
  public const int DEFAULT_FPS = 8;
  public int $layer { get => $this->image->layer; }
  public float $durationSeconds { get => count($this->frames) / $this->fps; }
  /** Leave room for the shared boundary tolerance before converting elapsed frames to an integer. */
  public float $maximumElapsedSeconds { get => PHP_INT_MAX / (2 * $this->fps); }

  /** @param non-empty-list<int> $frames Source cells in playback order. */
  private function __construct(public readonly GraphicalSpriteDefinition $image, public readonly int $columns,
    public readonly int $rows, public readonly array $frames, public readonly int $fps,
    public readonly bool $loop, public readonly int $restFrame) {}

  public static function fromArray(array $data): self
  {
    $animation = $data['animation'] ?? null;
    unset($data['animation']);
    $image = GraphicalSpriteDefinition::fromArray($data);
    if (!is_array($animation) || array_diff(array_keys($animation), self::FIELDS) !== []) {
      throw new InvalidArgumentException('Field pose animation accepts only columns, rows, frames, fps, loop and restFrame.');
    }
    $columns = $animation['columns'] ?? 1;
    $rows = $animation['rows'] ?? 1;
    $frames = $animation['frames'] ?? null;
    $fps = $animation['fps'] ?? self::DEFAULT_FPS;
    $loop = $animation['loop'] ?? true;
    $rest = $animation['restFrame'] ?? 0;
    foreach (['columns', 'rows', 'fps', 'loop', 'restFrame'] as $key) {
      if (array_key_exists($key, $animation) && $animation[$key] === null) {
        throw new InvalidArgumentException('Field pose animation.' . $key . ' cannot be null.');
      }
    }
    if (!is_int($columns) || !is_int($rows) || $columns < 1 || $columns > self::MAX_GRID_AXIS || $rows < 1 || $rows > self::MAX_GRID_AXIS
      || !is_int($fps) || $fps < 1 || $fps > self::MAX_FPS || !is_bool($loop)
      || !is_array($frames) || !array_is_list($frames) || $frames === [] || count($frames) > self::MAX_FRAMES
      || array_any($frames, static fn($frame): bool => !is_int($frame) || $frame < 0 || $frame >= $columns * $rows)
      || !is_int($rest) || $rest < 0 || $rest >= $columns * $rows) {
      throw new InvalidArgumentException('Field pose animation requires grid axes 1..64, 1..10000 valid source frames, fps 1..120, boolean loop and a valid restFrame.');
    }
    return new self($image, $columns, $rows, $frames, $fps, $loop, $rest);
  }

  /** Read-only clock selection shared by runtime and authoring previews. */
  public function getFrame(string $assetRoot, float $elapsedSeconds, bool $reducedMotion = false): ?GraphicalSpriteDefinition
  {
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < 0) {
      throw new InvalidArgumentException('Field pose elapsed time must be finite and non-negative.');
    }
    $size = PngAssetPreflight::getAvailableSize($assetRoot, $this->image->asset);
    if ($size === null) { return null; }
    $region = $this->image->sourceRect;
    $width = $region?->width ?? $size['width'];
    $height = $region?->height ?? $size['height'];
    if (($region?->x ?? 0) + $width > $size['width'] || ($region?->y ?? 0) + $height > $size['height']
      || $width % $this->columns !== 0 || $height % $this->rows !== 0) {
      throw new InvalidArgumentException('The current field pose PNG/crop must fit the image and divide into its authored grid.');
    }
    $elapsed = $this->loop
      ? ($elapsedSeconds > $this->maximumElapsedSeconds ? fmod($elapsedSeconds, $this->durationSeconds) : $elapsedSeconds)
      : min($elapsedSeconds, $this->durationSeconds);
    $index = EffectPlaybackTiming::getFrameCountForElapsed($elapsed, 1 / $this->fps);
    $source = $reducedMotion ? $this->restFrame : $this->frames[
      $this->loop ? $index % count($this->frames) : min($index, count($this->frames) - 1)];
    $width = intdiv($width, $this->columns);
    $height = intdiv($height, $this->rows);
    return new GraphicalSpriteDefinition($this->image->asset, $this->image->width, $this->image->height,
      $this->image->anchor, $this->image->layer, new SpriteSourceRect(
        ($region?->x ?? 0) + ($source % $this->columns) * $width,
        ($region?->y ?? 0) + intdiv($source, $this->columns) * $height, $width, $height),
      $this->image->lift, $this->image->quarterTurns);
  }
}
