<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Presentation;

use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;
use Closure;

/** A caller advances one visual lifetime; inspection cannot dispatch cues or mutate the field. */
final class CinematicStageSession
{
  public readonly string $id;
  public readonly EffectPlaybackTiming $timing;
  public readonly array $excludedLayers;
  private readonly CinematicStage $stage;
  private float $elapsedSeconds = 0.0;
  public private(set) bool $isReleased = false;
  public private(set) bool $hasPresentationFailure = false;

  public bool $isActive { get => $this->checkIsCurrent(); }
  public int $currentFrame { get => min($this->timing->totalFrames - 1,
    $this->timing->getFrameCountAt(min($this->elapsedSeconds, $this->timing->durationSeconds))); }

  /** @param list<string> $excludedLayers Terminal layers replaced only while this canvas is usable. */
  public function __construct(public readonly CompiledEffectTimeline $timeline, private readonly string $assetRoot,
    float $durationSeconds, array $excludedLayers = [], private ?Closure $isCurrent = null)
  {
    if (!is_array($timeline->defaults['stage'] ?? null) || ($timeline->defaults['playback']['loop'] ?? false)
      || $timeline->cueSchedule !== []) {
      throw new InvalidArgumentException('Caller-owned stages require a stage, once playback and no cues.');
    }
    foreach ($timeline->playbackSegments as $segment) {
      foreach ($segment['drawCommands'] as $command) {
        if ($segment['layer'] !== 'image' || ($command['payload']['anchor'] ?? null) !== 'stage') {
          throw new InvalidArgumentException('Caller-owned stages contain only stage image segments.');
        }
      }
    }
    if (!array_is_list($excludedLayers) || count($excludedLayers) > 1024) {
      throw new InvalidArgumentException('Stage exclusions require a bounded list of layer IDs.');
    }
    foreach ($excludedLayers as $layer) { CanvasValidation::id($layer); }
    $this->excludedLayers = array_map(static fn(string $layer): string => $layer, $excludedLayers);
    $this->id = 'stage-presentation:' . spl_object_id($this);
    $this->timing = EffectPlaybackTiming::createForDuration($timeline, $durationSeconds);
    $this->stage = CinematicStage::fromArray($timeline->defaults['stage'], $this->timing->totalFrames,
      intval($timeline->defaults['restFrame'] ?? 0));
  }

  public function advanceTo(float $elapsedSeconds): void
  {
    if (!$this->checkIsCurrent()) { return; }
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < $this->elapsedSeconds) {
      throw new InvalidArgumentException('Stage consumer progress must be finite and monotonic.');
    }
    $this->elapsedSeconds = $elapsedSeconds;
  }

  public function getCanvas(int $width, int $height, bool $reducedMotion = false, bool $imageFlips = true): ?PresentationCanvas
  {
    if (!$this->checkIsCurrent() || $this->hasPresentationFailure) { return null; }
    try {
      return CinematicStagePresentation::compose($this->stage->getFrame($this->currentFrame, $reducedMotion),
        $this->timeline->playbackSegments, $this->assetRoot, $width, $height, $imageFlips);
    } catch (InvalidArgumentException|RuntimeException $error) {
      $this->hasPresentationFailure = true;
      Debug::warn('Optional stage presentation unavailable: ' . $error->getMessage());
      return null;
    }
  }

  private function checkIsCurrent(): bool
  {
    if (!$this->isReleased && $this->isCurrent !== null && !($this->isCurrent)()) { $this->release(); }
    return !$this->isReleased;
  }

  public function release(): void
  {
    $this->isReleased = true;
    $this->isCurrent = null;
  }
}
