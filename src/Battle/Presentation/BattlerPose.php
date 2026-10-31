<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Rendering\Presentation\SpriteSourceRect;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/** A still or animated battle pose. Sheet geometry is authoring intent, not cached PNG size. */
final readonly class BattlerPose
{
  /** @param non-empty-list<int> $frames Source cells in playback order. */
  public function __construct(
    public string $asset,
    public int $columns = 1,
    public int $rows = 1,
    public array $frames = [0],
    public int $fps = 8,
    public bool $loop = true,
    public int $restFrame = 0,
    public float $pivotX = 0.5,
    public float $pivotY = 1.0,
    public ?float $scaleSpan = null,
    /** Whether this pose's art paints its own shadow; null follows the battler's grounding. */
    public ?bool $bakedShadow = null,
    /** Index into playback entries, including repeated holds; aligns to target-phase start. */
    public ?int $impactFrame = null,
  ) {
    SpriteValidation::validateAssetPath($asset);
    if ($impactFrame !== null && ($loop || $impactFrame < 0 || $impactFrame >= count($frames))) {
      throw new InvalidArgumentException('An impact frame requires a non-looping pose and a valid playback-entry index.');
    }
    if ($scaleSpan !== null) { BattlerScale::validateUnit($scaleSpan); }
    if ($columns < 1 || $rows < 1 || $columns > 64 || $rows > 64 || $fps < 1 || $fps > 120
      || !array_is_list($frames) || $frames === [] || count($frames) > 10000
      || array_any($frames, static fn($frame): bool => !is_int($frame) || $frame < 0 || $frame >= $columns * $rows)
      || $restFrame < 0 || $restFrame >= $columns * $rows || !is_finite($pivotX) || !is_finite($pivotY)
      || min($pivotX, $pivotY) < 0 || max($pivotX, $pivotY) > 1) {
      throw new InvalidArgumentException('Battle poses require a bounded sheet, valid source frames, fps 1..120 and normalized pivots.');
    }
  }

  /** Includes the strike entry and its last authored hold. */
  public function getRecoverySeconds(): float
  {
    return $this->impactFrame === null ? 0.0 : (count($this->frames) - $this->impactFrame) / $this->fps;
  }

  public function getArtwork(string $assetRoot, float $elapsedSeconds, bool $reducedMotion = false,
    ?float $impactAtSeconds = null): ?BattlerArtwork
  {
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < 0) {
      throw new InvalidArgumentException('Battle pose elapsed time must be finite and non-negative.');
    }
    if ($impactAtSeconds !== null && (!is_finite($impactAtSeconds) || $impactAtSeconds < 0)) {
      throw new InvalidArgumentException('A pose impact time must be finite and non-negative.');
    }
    $size = PngAssetPreflight::getAvailableSize($assetRoot, $this->asset);
    if ($size === null) { return null; }
    if ($size['width'] % $this->columns !== 0 || $size['height'] % $this->rows !== 0) {
      throw new InvalidArgumentException('The current battler pose PNG does not divide into its authored sheet grid.');
    }
    $index = EffectPlaybackTiming::getFrameCountForElapsed($elapsedSeconds, 1 / $this->fps);
    if (!$reducedMotion && $this->impactFrame !== null && $impactAtSeconds !== null) {
      if ($elapsedSeconds < $impactAtSeconds) {
        // Preserve natural anticipation, hold its final entry, compress only a short lead-in.
        $duration = $this->impactFrame / $this->fps;
        $index = $duration > $impactAtSeconds
          ? EffectPlaybackTiming::getFrameCountForElapsed($elapsedSeconds, $impactAtSeconds / max(1, $this->impactFrame))
          : $index;
        $index = min($index, max(0, $this->impactFrame - 1));
        if ($this->impactFrame === 0) { $reducedMotion = true; }
      } else {
        $index = $this->impactFrame + EffectPlaybackTiming::getFrameCountForElapsed(
          $elapsedSeconds - $impactAtSeconds, 1 / $this->fps);
      }
    }
    $source = $reducedMotion ? $this->restFrame : $this->frames[
      $this->loop ? $index % count($this->frames) : min($index, count($this->frames) - 1)];
    $width = intdiv($size['width'], $this->columns);
    $height = intdiv($size['height'], $this->rows);
    $crop = $this->columns * $this->rows === 1 ? null : new SpriteSourceRect(
      ($source % $this->columns) * $width, intdiv($source, $this->columns) * $height, $width, $height);
    return new BattlerArtwork($this->asset, $width, $height, $this->pivotX * $width, $this->pivotY * $height, $crop);
  }
}
