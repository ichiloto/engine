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
  public int $totalFrames { get => $this->plan->timeline->defaults['lengthFrames']; }

  /** @param list<CharacterInterface> $targets */
  public function __construct(BattleConfig $battle, public readonly BattleCommandTimeline $plan,
    private readonly CharacterInterface $actor, private readonly array $targets,
    private readonly BattlePoseRole $pose, ?BattlePresentationCatalog $catalog = null, string $assetRoot = '',
    public readonly EffectPresentation $presentation = EffectPresentation::GRAPHICAL)
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
      : GraphicalBattlePresentation::prepare($battle, $catalog, $assetRoot);
    $this->terminal = new TerminalBattlePresentation($battle, conditionEffects: BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary($assetRoot === '' ? getcwd() . '/assets' : $assetRoot)));
    $this->terminalComposer = new TerminalPresentationComposer();
  }

  public function getFrameAtTime(float $seconds, bool $reducedMotion = false): BattleCommandPreviewFrame
  {
    if (!is_finite($seconds) || $seconds < 0) {
      throw new InvalidArgumentException('Battle preview time must be finite and non-negative.');
    }
    return $this->getFrameAtIndex(min($this->totalFrames - 1,
      EffectPlaybackTiming::getFrameCountForElapsed($seconds, 1 / BattleCommandTimeline::FPS)), $reducedMotion);
  }

  public function getFrameAtIndex(int $frame, bool $reducedMotion = false): BattleCommandPreviewFrame
  {
    if ($frame < 0 || $frame >= $this->totalFrames) {
      throw new InvalidArgumentException('Battle preview frame must be inside the command timeline.');
    }
    $cues = [];
    // Traversal preserves phase/cue order, but its callbacks have no game or audio side effects.
    $playback = new BattleCommandPlayback($this->plan, $this->actor, $this->targets, $this->pose,
      static function (): void {}, static function (array $cue) use (&$cues): void { $cues[] = $cue; });
    $playback->begin();
    $seconds = $frame / BattleCommandTimeline::FPS;
    $playback->update($seconds);
    $canvas = $this->graphical?->frame(new BattlePresentationSnapshot($playback,
      poseElapsedSeconds: $seconds), now: $seconds, reducedMotion: $reducedMotion);
    $lines = $this->terminal->getFrame($playback, $reducedMotion, $seconds)['lines'];
    $terminalCanvas = $this->terminalComposer->createCanvasFromLines($lines, new RendererGridConfig(
      $this->terminal->width, $this->terminal->height,
      intdiv(PresentationCanvas::DEFAULT_WIDTH, $this->terminal->width),
      intdiv(PresentationCanvas::DEFAULT_HEIGHT, $this->terminal->height)));
    return new BattleCommandPreviewFrame($playback->session->currentFrame, $playback->phase, $canvas,
      $lines,
      $playback->session->getCuesAt(), $cues,
      $playback->presentationFailure === null ? [] : [$playback->presentationFailure->getMessage()],
      $this->plan->getAuthoredFramesAtCommandFrame($playback->session->currentFrame), $terminalCanvas);
  }
}
