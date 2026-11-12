<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectPlaybackTiming;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Console\TerminalPresentationComposer;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;
use InvalidArgumentException;

/** Random-access inspection through production playback and presentation only. */
final class BattleCommandPreview
{
  private readonly ?GraphicalBattlePresentation $graphical;
  private readonly TerminalBattlePresentation $terminal;
  private readonly TerminalPresentationComposer $terminalComposer;
  /** @var array<int, BattleCommandPlayback> Path captured at release for scrubber boundaries. */
  private array $timingPlaybacks = [];
  public int $totalFrames { get => $this->getTotalFrames(); }

  /** @param list<CharacterInterface> $targets */
  public function __construct(BattleConfig $battle, public readonly BattleCommandTimeline $plan,
    private readonly CharacterInterface $actor, private readonly array $targets,
    private readonly BattlePoseRole $pose, ?BattlePresentationCatalog $catalog = null, string $assetRoot = '',
    public readonly EffectPresentation $presentation = EffectPresentation::GRAPHICAL, ?GraphicalBattlePresentation $graphical = null)
  {
    $participants = [...$battle->partyRoster->battlers, ...$battle->troop->members->toArray()];
    if (!in_array($actor, $participants, true) || $targets === []
      || array_any($targets, static fn($target): bool => !in_array($target, $participants, true))) {
      throw new InvalidArgumentException('Battle preview actor and targets must belong to its battle.');
    }
    if ($presentation === EffectPresentation::GRAPHICAL && $catalog === null) {
      throw new InvalidArgumentException('A graphical battle preview requires its presentation catalog.');
    }
    $this->graphical = $presentation === EffectPresentation::TERMINAL ? null
      : ($graphical ?? GraphicalBattlePresentation::prepare($battle, $catalog, $assetRoot));
    $this->terminal = new TerminalBattlePresentation($battle, conditionEffects: BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($assetRoot === '' ? getcwd() . '/assets' : $assetRoot)));
    $this->terminalComposer = new TerminalPresentationComposer();
  }

  public function getFrameAtTime(float $seconds, bool $reducedMotion = false, bool $imageRotation = false,
    bool $coverageDabs = false, bool $textureStrip = false): BattleCommandPreviewFrame
  {
    if (!is_finite($seconds) || $seconds < 0) {
      throw new InvalidArgumentException('Battle preview time must be finite and non-negative.');
    }
    return $this->getFrameAtIndex(min($this->getTotalFrames($reducedMotion) - 1,
      EffectPlaybackTiming::getFrameCountForElapsed($seconds, 1 / BattleCommandTimeline::FPS)), $reducedMotion, $imageRotation, $coverageDabs, $textureStrip);
  }

  public function getFrameAtIndex(int $frame, bool $reducedMotion = false, bool $imageRotation = false,
    bool $coverageDabs = false, bool $textureStrip = false): BattleCommandPreviewFrame
  {
    if ($frame < 0 || $frame >= $this->getTotalFrames($reducedMotion)) {
      throw new InvalidArgumentException('Battle preview frame must be inside the command timeline.');
    }
    $cues = [];
    $playback = $this->createPlayback($reducedMotion, static function (array $cue) use (&$cues): void {
      $cues[] = $cue;
    });
    $seconds = $frame / BattleCommandTimeline::FPS;
    $playback->update($seconds);
    $canvas = $this->graphical?->frame(new BattlePresentationSnapshot($playback,
      poseElapsedSeconds: $seconds), now: $seconds, reducedMotion: $reducedMotion, imageRotation: $imageRotation, coverageDabs: $coverageDabs, textureStrip: $textureStrip);
    $lines = $this->terminal->getFrame($playback, $reducedMotion, $seconds)['lines'];
    $terminalCanvas = $this->terminalComposer->createCanvasFromLines($lines, new RendererGridConfig(
      $this->terminal->width, $this->terminal->height,
      intdiv(PresentationCanvas::DEFAULT_WIDTH, $this->terminal->width),
      intdiv(PresentationCanvas::DEFAULT_HEIGHT, $this->terminal->height)));
    $toPreviewCue = fn(array $cue): array => [...$cue, 'frame' => $this->getPlaybackFrame($cue['frame'], $reducedMotion)];
    return new BattleCommandPreviewFrame($frame, $playback->phase, $canvas,
      $lines,
      array_map($toPreviewCue, $playback->session->getCuesAt()), array_map($toPreviewCue, $cues),
      $playback->presentationFailure === null ? [] : [$playback->presentationFailure->getMessage()],
      $this->plan->getAuthoredFramesAtCommandFrame($playback->session->currentFrame), $terminalCanvas);
  }

  public function getTotalFrames(bool $reducedMotion = false): int
  {
    $timing = $this->getTimingPlayback($reducedMotion);
    return $timing === null ? $this->plan->timeline->defaults['lengthFrames']
      : self::getFrameBoundary($timing->getDurationSeconds());
  }

  public function getPlaybackFrame(int $commandFrame, bool $reducedMotion = false): int
  {
    $timing = $this->getTimingPlayback($reducedMotion);
    return $timing === null ? $commandFrame : self::getFrameBoundary($timing->getElapsedSecondsAtFrame($commandFrame));
  }

  public function getPlaybackPhases(bool $reducedMotion = false): array
  {
    if ($this->getTimingPlayback($reducedMotion) === null) { return $this->plan->phases; }
    $phases = [];
    foreach ($this->plan->phases as $name => $range) {
      $start = $this->getPlaybackFrame($range['start'], $reducedMotion);
      $end = $name === 'finish' ? $this->getTotalFrames($reducedMotion)
        : $this->getPlaybackFrame($range['start'] + $range['length'], $reducedMotion);
      $phases[$name] = ['start' => $start, 'length' => $end - $start];
    }
    return $phases;
  }

  private static function getFrameBoundary(float $seconds): int
  {
    $frames = $seconds * BattleCommandTimeline::FPS;
    return (int)ceil($frames - PHP_FLOAT_EPSILON * max(1, $frames) * 4);
  }

  private function getTimingPlayback(bool $reducedMotion): ?BattleCommandPlayback
  {
    if ($this->graphical === null || $this->plan->flightTiming === null) { return null; }
    $key = (int)$reducedMotion;
    if (!isset($this->timingPlaybacks[$key])) {
      $playback = $this->createPlayback($reducedMotion, static function (): void {});
      $playback->update($this->plan->flightTiming['release'] / BattleCommandTimeline::FPS);
      $this->timingPlaybacks[$key] = $playback;
    }
    return $this->timingPlaybacks[$key];
  }

  private function createPlayback(bool $reducedMotion, callable $cueSink): BattleCommandPlayback
  {
    // Traversal preserves phase/cue order, but its callbacks have no game or audio side effects.
    $playback = new BattleCommandPlayback($this->plan, $this->actor, $this->targets, $this->pose,
      static function (): void {}, $cueSink);
    if ($this->plan->travel !== null && !$this->plan->travel->isPossible) {
      $playback->recordPresentationFailure(new \RuntimeException('Battle run-to-target could not reach contact: ' . $this->plan->travel->failure->value));
    }
    $this->graphical?->bindCommandPosePoints($playback, $reducedMotion, 0);
    $playback->begin();
    return $playback;
  }
}
