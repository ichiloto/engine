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
  /**
   * @param non-empty-list<int> $frames Source cells in playback order.
   * @param list<int> $entryFrames One-shot source cells before the main movement.
   * @param list<int> $exitFrames One-shot source cells after the main movement.
   */
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
    /** Foot-to-strike offset in current standing-body units, independent of pose sheet dimensions. */
    public ?BattlePoseReach $reach = null,
    public array $entryFrames = [],
    public array $exitFrames = [],
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
    foreach (['entryFrames' => $entryFrames, 'exitFrames' => $exitFrames] as $name => $sequence) {
      if (!array_is_list($sequence) || count($sequence) > 10000
        || array_any($sequence, static fn($frame): bool => !is_int($frame) || $frame < 0 || $frame >= $columns * $rows)) {
        throw new InvalidArgumentException("Battle pose {$name} requires a list of at most 10000 valid source cell numbers.");
      }
    }
  }

  /** @return list<int> Source cells on this sequence's independent clock. */
  public function getSequenceFrames(BattlePoseSequence $sequence): array
  {
    return match ($sequence) {
      BattlePoseSequence::MAIN => $this->frames,
      BattlePoseSequence::ENTRY => $this->entryFrames,
      BattlePoseSequence::EXIT => $this->exitFrames,
    };
  }

  /** Authored duration of one pass, including repeated cell holds. */
  public function getSequenceSeconds(BattlePoseSequence $sequence): float
  {
    return count($this->getSequenceFrames($sequence)) / $this->fps;
  }

  /** Includes the strike entry and its last authored hold. */
  public function getRecoverySeconds(): float
  {
    return $this->impactFrame === null ? 0.0 : (count($this->frames) - $this->impactFrame) / $this->fps;
  }

  public function getArtwork(string $assetRoot, float $elapsedSeconds, bool $reducedMotion = false,
    ?float $impactAtSeconds = null, ?float $durationSeconds = null,
    BattlePoseSequence $sequence = BattlePoseSequence::MAIN): ?BattlerArtwork
  {
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < 0) {
      throw new InvalidArgumentException('Battle pose elapsed time must be finite and non-negative.');
    }
    if ($impactAtSeconds !== null && (!is_finite($impactAtSeconds) || $impactAtSeconds < 0)) {
      throw new InvalidArgumentException('A pose impact time must be finite and non-negative.');
    }
    if ($durationSeconds !== null && (!is_finite($durationSeconds) || $durationSeconds <= 0)) {
      throw new InvalidArgumentException('An explicit pose duration must be finite and positive.');
    }
    $frames = $this->getSequenceFrames($sequence);
    if ($frames === []) { return null; }
    $main = $sequence === BattlePoseSequence::MAIN;
    $size = PngAssetPreflight::getAvailableSize($assetRoot, $this->asset);
    if ($size === null) { return null; }
    if ($size['width'] % $this->columns !== 0 || $size['height'] % $this->rows !== 0) {
      throw new InvalidArgumentException('The current battler pose PNG does not divide into its authored sheet grid.');
    }
    $index = EffectPlaybackTiming::getFrameCountForElapsed($elapsedSeconds, 1 / $this->fps);
    if ($main && !$this->loop && $this->impactFrame === null && $durationSeconds !== null) {
      $index = min(count($frames) - 1, EffectPlaybackTiming::getFrameCountForElapsed($elapsedSeconds, $durationSeconds / count($frames)));
    }
    if ($main && !$reducedMotion && $this->impactFrame !== null && $impactAtSeconds !== null) {
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
    $source = $reducedMotion ? $this->restFrame : $frames[
      $main && $this->loop ? $index % count($frames) : min($index, count($frames) - 1)];
    $width = intdiv($size['width'], $this->columns);
    $height = intdiv($size['height'], $this->rows);
    $crop = $this->columns * $this->rows === 1 ? null : new SpriteSourceRect(
      ($source % $this->columns) * $width, intdiv($source, $this->columns) * $height, $width, $height);
    return new BattlerArtwork($this->asset, $width, $height, $this->pivotX * $width, $this->pivotY * $height, $crop);
  }
}
