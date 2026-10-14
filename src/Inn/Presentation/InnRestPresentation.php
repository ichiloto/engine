<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Inn\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageSession;
use Ichiloto\Engine\Cutscenes\Presentation\PartyStageSelection;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use InvalidArgumentException;
use RuntimeException;

/** Owns the optional visual handle and the originating rest boundary, never recovery or input. */
final class InnRestPresentation
{
  public const string TERMINAL_LAYER = 'inn-rest';
  private ?CinematicStageSession $session = null;
  private readonly string $mapId;
  private readonly ?int $generation;
  private readonly ?\Ichiloto\Engine\Field\MapLayerSet $layers;
  private readonly ?CinematicPresentationManager $manager;
  private ?PartyStageSelection $selection = null;
  private ?EffectTimelineLibrary $library = null;
  private ?string $selectedTimeline = null;
  private ?string $selectionFailure = null;
  private float $elapsedSeconds = 0.0;
  private bool $released = false;

  public bool $isOriginCurrent {
    get => !$this->scene->isStopping && $this->mapId === $this->scene->currentMapId
      && $this->generation === $this->scene->cinematicStage?->generation && $this->layers === $this->scene->mapManager?->layers
      && (!isset($this->scene->sceneManager) || $this->scene->sceneManager->currentScene === $this->scene);
  }

  public function __construct(private readonly GameScene $scene, mixed $reference, private readonly float $durationSeconds)
  {
    if (!is_finite($durationSeconds) || $durationSeconds <= 0) {
      throw new InvalidArgumentException('Inn rest presentation requires its finite positive rest duration.');
    }
    $this->mapId = $scene->currentMapId;
    $this->generation = $scene->cinematicStage?->generation;
    $this->layers = $scene->mapManager?->layers;
    $this->manager = $scene->cinematicPresentation;
    if ($reference === null || !$scene->canPresentCinematicStage() || $this->manager === null) { return; }
    try {
      if (is_array($reference)) { $reference = PartyStageSelection::fromArray($reference); }
      if ($reference instanceof PartyStageSelection) {
        $this->selection = $reference;
      }
      if (!is_string($reference) && $this->selection === null) {
        throw new InvalidArgumentException('Inn presentation requires a stable timeline identity or party stage selection.');
      }
      $root = $scene->getGame()->getRendererRuntime()?->getAssetRoot();
      if ($root === null) { throw new InvalidArgumentException('Inn runtime presentation requires its renderer asset root.'); }
      $this->library = new EffectTimelineLibrary($root);
      if ($this->selection !== null) { $this->refreshSelection(); }
      else { $this->replaceTimeline($reference); }
    } catch (InvalidArgumentException|RuntimeException $error) {
      $this->reportSelectionFailure($error->getMessage());
    }
  }

  public function advanceTo(float $elapsedSeconds): void
  {
    if ($this->released || !$this->isOriginCurrent || $this->manager !== $this->scene->cinematicPresentation
      || ($this->session !== null && $this->manager?->hasStagePresentation($this->session) !== true)) {
      $this->release();
      throw new RuntimeException('Inn stay interrupted by scene presentation teardown or transfer.');
    }
    if (!is_finite($elapsedSeconds) || $elapsedSeconds < $this->elapsedSeconds) {
      throw new InvalidArgumentException('Inn rest progress must be finite and monotonic.');
    }
    $this->elapsedSeconds = $elapsedSeconds;
    $this->refreshSelection();
    $this->session?->advanceTo($elapsedSeconds);
  }

  /** Selection can change, but every stage still seeks on the one caller-owned rest clock. */
  private function refreshSelection(): void
  {
    if ($this->selection === null || $this->library === null) { return; }
    try {
      $reference = $this->selection->selectTimeline($this->scene->party);
    } catch (InvalidArgumentException|RuntimeException $error) {
      $this->replaceTimeline(null);
      $this->reportSelectionFailure($error->getMessage());
      return;
    }
    if ($reference === null) {
      $this->replaceTimeline(null);
      $this->reportSelectionFailure('No stage timeline is bound to the current ' . $this->selection->treatment . ' treatment.');
      return;
    }
    // A missing optional timeline is attempted once per selection, not once per frame.
    if ($reference === $this->selectedTimeline) { return; }
    try {
      $this->replaceTimeline($reference);
      $this->selectionFailure = null;
    } catch (InvalidArgumentException|RuntimeException $error) {
      $this->reportSelectionFailure($error->getMessage());
    }
  }

  private function replaceTimeline(?string $reference): void
  {
    $this->releaseSession();
    $this->selectedTimeline = $reference;
    if ($reference === null || $this->library === null) { return; }
    $timeline = $this->library->loadStage($reference);
    $this->session = $this->manager?->beginStagePresentation($timeline, $this->library->assetRoot,
      $this->durationSeconds, [self::TERMINAL_LAYER]);
  }

  private function reportSelectionFailure(string $message): void
  {
    if ($this->selectionFailure !== $message) {
      Debug::warn('Optional inn presentation unavailable: ' . $message);
    }
    $this->selectionFailure = $message;
  }

  private function releaseSession(): void
  {
    if ($this->session === null) { return; }
    $this->manager?->releaseStagePresentation($this->session);
    $this->session = null;
  }

  public function release(): void
  {
    if ($this->released) { return; }
    $this->released = true;
    $this->releaseSession();
  }
}
