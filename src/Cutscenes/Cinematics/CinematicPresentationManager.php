<?php

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Field\FieldEffectSession;
use Ichiloto\Engine\Animations\Field\FieldEffectSprite;
use Ichiloto\Engine\Animations\Field\FieldScreenSprite;
use Ichiloto\Engine\Animations\Timelines\EffectPresentation;
use Ichiloto\Engine\Animations\Timelines\CompiledEffectTimeline;
use Ichiloto\Engine\Cutscenes\Presentation\CinematicStageSession;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\PresentationLayerPolicy;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\ScreenTransitionSession;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\UI\Enumerations\PresentationPriority;
use Ichiloto\Engine\UI\Accessibility;

/** Renders temporary cinematic overlays and reusable field animation frames. */
final class CinematicPresentationManager
{
  protected ?CinematicTextPresentation $overlay = null;
  private ?DialogueScenePresentation $dialoguePresentation = null;
  private ?string $dialogueAssetRoot = null;
  private bool $hasGraphicalOverlay = false;
  /** @var array<string, array{session: FieldEffectSession, screenSpace: bool}> */
  private array $effects = [];
  /** @var array<string, array{session: CinematicStageSession}> */
  private array $stages = [];
  protected ?ScreenTransitionSession $transitionSession = null;
  protected ?string $fieldCover = null;

  public function __construct(protected GameScene $gameScene)
  {
  }

  public function showOverlay(string $kind, string $text, string $title = ''): void
  {
    $this->overlay = new CinematicTextPresentation($kind, trim($text), trim($title));
    $this->hasGraphicalOverlay = false;
  }

  public ?CinematicTextPresentation $overlayPresentation { get => $this->overlay; }

  public function clearOverlay(?CinematicTextPresentation $owner = null): void
  {
    if ($owner !== null && $owner !== $this->overlay) { return; }
    $this->overlay = null;
    $this->hasGraphicalOverlay = false;
  }

  public function getOverlayCanvas(int $width, int $height, ?string $assetRoot): ?PresentationCanvas
  {
    $this->hasGraphicalOverlay = false;
    if ($this->overlay === null || $assetRoot === null) { return null; }
    if ($this->dialogueAssetRoot !== $assetRoot) {
      $this->dialogueAssetRoot = $assetRoot;
      $this->dialoguePresentation = new DialogueScenePresentation($assetRoot);
    }
    $canvas = $this->overlay->getCanvas($this->dialoguePresentation, $width, $height);
    $this->hasGraphicalOverlay = $canvas !== null;
    return $canvas;
  }

  public function getOverlayExcludedLayers(): array
  {
    return $this->hasGraphicalOverlay ? ['cinematic-overlay'] : [];
  }

  public function presentEffect(FieldEffectSession $session, bool $screenSpace = false): void
  {
    if (isset($this->effects[$session->id])) { $this->effects[$session->id]['session']->playback->pause(); }
    $this->effects[$session->id] = ['session' => $session, 'screenSpace' => $screenSpace];
  }

  public function hasEffect(string $id): bool { return isset($this->effects[$id]); }

  public function removeEffect(string $id): void
  {
    if (isset($this->effects[$id])) { $this->effects[$id]['session']->playback->pause(); }
    unset($this->effects[$id]);
  }

  public int $effectCount { get => count($this->effects); }

  /** The caller owns progress and releases only the returned handle. */
  public function beginStagePresentation(CompiledEffectTimeline $timeline, string $assetRoot,
    float $durationSeconds, array $excludedLayers = []): CinematicStageSession
  {
    $this->reconcileStagePresentations();
    $scene = $this->gameScene;
    $mapId = $scene->currentMapId;
    $generation = $scene->cinematicStage?->generation;
    $layers = $scene->mapManager?->layers;
    $session = new CinematicStageSession($timeline, $assetRoot, $durationSeconds, $excludedLayers,
      static fn(): bool => !$scene->isStopping && $mapId === $scene->currentMapId
        && $generation === $scene->cinematicStage?->generation && $layers === $scene->mapManager?->layers
        && (!isset($scene->sceneManager) || $scene->sceneManager->currentScene === $scene));
    if (!$session->isActive) { throw new \LogicException('A stage presentation requires a current, running scene owner.'); }
    $this->stages[$session->id] = ['session' => $session];
    return $session;
  }

  public function hasStagePresentation(CinematicStageSession $session): bool
  {
    $this->reconcileStagePresentations();
    return ($this->stages[$session->id]['session'] ?? null) === $session;
  }

  public function releaseStagePresentation(CinematicStageSession $session): void
  {
    if (($this->stages[$session->id]['session'] ?? null) !== $session) { return; }
    $session->release();
    unset($this->stages[$session->id]);
  }

  public function clearStagePresentations(): void
  {
    foreach ($this->stages as $entry) { $entry['session']->release(); }
    $this->stages = [];
  }

  private function reconcileStagePresentations(): void
  {
    foreach ($this->stages as $entry) {
      if (!$entry['session']->isActive) {
        $this->releaseStagePresentation($entry['session']);
      }
    }
  }

  public function getStageCanvas(int $width, int $height, bool $imageFlips = true): ?PresentationCanvas
  {
    $this->reconcileStagePresentations();
    $canvas = null;
    foreach ($this->stages as $entry) {
      $session = $entry['session'];
      $next = $session->getCanvas($width, $height, Accessibility::prefersReducedMotion(), $imageFlips);
      if ($next !== null) { $canvas = PresentationCanvas::composeOverlay($canvas, $next, $session->id . ':'); }
    }
    return $canvas;
  }

  public function getStageExcludedLayers(): array
  {
    $this->reconcileStagePresentations();
    $layers = [];
    foreach ($this->stages as $entry) {
      if (!$entry['session']->hasPresentationFailure) { array_push($layers, ...$entry['session']->excludedLayers); }
    }
    return array_values(array_unique($layers));
  }

  /** @return list<FieldEffectSprite> */
  public function getEffectSprites(?FieldViewport $viewport): array
  {
    if ($viewport === null) { return []; }
    $sprites = [];
    foreach ($this->effects as $effect) {
      $position = $effect['session']->anchor->cell;
      if ($position === null) { continue; }
      foreach ($effect['session']->getSprites($position, Accessibility::prefersReducedMotion()) as $sprite) {
        if (!$effect['screenSpace']) { $sprites[] = $sprite; continue; }
        $definition = $sprite->getGraphicalSpriteDefinition();
        $point = $sprite->getGraphicalSpriteWorldPosition();
        $size = FieldViewport::TILE_SIZE * $viewport->zoom;
        $width = $viewport->grid->columns * $viewport->grid->cellWidth;
        $height = $viewport->grid->rows * $viewport->grid->cellHeight;
        $sprites[] = new FieldScreenSprite($sprite->getGraphicalSpriteId(),
          new GraphicalSpriteDefinition($definition->asset, (int)round($definition->width * $viewport->zoom),
            (int)round($definition->height * $viewport->zoom), $definition->anchor, $definition->layer,
            $definition->sourceRect, pivot: $definition->pivot),
          new Vector2((int)round((($width - $viewport->columns * $size) / 2 + ($point->x + .5) * $size)
              / $viewport->grid->cellWidth - .5),
            (int)round((($height - $viewport->rows * $size) / 2 + ($point->y + 1) * $size)
              / $viewport->grid->cellHeight - 1)));
      }
    }
    return $sprites;
  }

  public function hideField(string $fill = '█'): void
  {
    $this->fieldCover = $fill !== '' ? $fill : '█';
  }

  public function presentTransition(ScreenTransitionSession $session): void
  {
    $this->transitionSession = $session;
  }

  public function finishTransition(string $direction): void
  {
    $this->transitionSession = null;

    if (strtolower($direction) === 'in') {
      $this->fieldCover = null;
    } else {
      $this->hideField();
    }
  }

  public function clearTransition(): void
  {
    $this->transitionSession = null;
    $this->fieldCover = null;
  }

  public function hasTransitionCover(): bool
  {
    return $this->fieldCover !== null || $this->transitionSession !== null;
  }

  public function getTransitionCanvas(int $width, int $height): ?PresentationCanvas
  {
    if ($this->transitionSession !== null) {
      return $this->transitionSession->getPresentationCanvas($width, $height);
    }
    return $this->fieldCover === null ? null
      : new ScreenTransition(TransitionStyle::FADE)->composeCover($width, $height);
  }

  public function clear(): void
  {
    $this->clearStagePresentations();
    $this->clearOverlay();
    foreach (array_keys($this->effects) as $id) { $this->removeEffect($id); }
    $this->clearTransition();
  }

  public function render(): void
  {
    $this->gameScene->renderFieldEffects();
    $this->renderEffects();
    Console::withLayer('cinematic-overlay', fn() => $this->renderOverlay(),
      PresentationLayerPolicy::UI + PresentationPriority::MODAL->value);
    Console::withLayer('cinematic-cover', fn() => $this->renderTransition(), PresentationLayerPolicy::TRANSITION);
  }

  protected function renderEffects(): void
  {
    $presentation = $this->gameScene->isGraphicalFieldPresented() ? EffectPresentation::GRAPHICAL : EffectPresentation::TERMINAL;
    foreach ($this->effects as $effect) {
      $position = $effect['session']->anchor->cell;
      if ($position === null) { continue; }
      $origin = $effect['screenSpace'] ? $position : $this->gameScene->camera->getScreenSpacePosition($position);
      Console::withLayer($effect['session']->id, fn() => $effect['session']->renderText(
        $this->gameScene->camera, $origin, $presentation, Accessibility::prefersReducedMotion()),
        $effect['screenSpace'] ? PresentationLayerPolicy::UI : PresentationLayerPolicy::FIELD_EFFECT_FRONT);
    }
  }

  protected function renderOverlay(): void
  {
    // Text cells belong to the presentation host, not the square-tile field camera.
    $grid = $this->gameScene->getPresentationContext()?->grid;
    $this->overlay?->renderTerminal($grid?->columns ?? Console::getWidth(), $grid?->rows ?? Console::getHeight());
  }

  protected function renderTransition(): void
  {
    if ($this->transitionSession?->hasRenderedFrame()) {
      $this->transitionSession->renderCurrentFrame();
      return;
    }

    if ($this->fieldCover === null) {
      return;
    }

    $width = $this->gameScene->camera->screen->getWidth();
    $height = $this->gameScene->camera->screen->getHeight();
    $this->gameScene->camera->draw(
      array_fill(0, $height, str_repeat($this->fieldCover, $width)),
      0,
      0,
    );
  }
}
