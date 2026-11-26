<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Animations\Field\FieldSpriteBounds;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationProviderInterface;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueScenePresentation;
use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Field\Npc;
use Ichiloto\Engine\Field\Player;
use Ichiloto\Engine\IO\Console\TerminalText;

/** Scene-owned projection; UI and current subjects supply geometry, gameplay remains unchanged. */
final class DialogueCameraFraming
{
  private const float TRANSITION_SECONDS = 0.2;
  private ?DialogueScenePresentation $dialogue = null;
  private ?string $assetRoot = null;
  private array $notes = [];
  private ?object $scope = null;
  /** @var list<FieldSpriteBounds> */
  private array $safeAreas = [];
  /** @var array{?FieldViewport, int, int}|array{} */
  private array $viewportLayout = [];
  private string $diagnosticKey = '';
  /** @var array<string,array{position: \Ichiloto\Engine\Core\Vector2, sweep: ?FieldSpriteBounds}> */
  private array $samples = [];
  public private(set) ?string $diagnostic = null;

  public function __construct(private readonly GameScene $scene) {}

  public function clear(): void
  {
    $this->scene->camera->clearFraming($this, $this->getMotionSeconds());
    $this->diagnostic = null;
    $this->safeAreas = [];
  }

  public function reset(): void
  {
    $this->clear(); $this->samples = []; $this->scope = null; $this->viewportLayout = [];
  }

  /** Capture the admitted cast before the first cinematic command can move it. */
  public function observeSubjects(): void
  {
    $this->collectSubjectBounds($this->scene->cinematicController?->getFramingSubjects() ?? [], $this->scene->getFieldViewport());
  }

  private function collectSubjectBounds(array $subjects, ?FieldViewport $view): array
  {
    $bounds = $next = [];
    $slides = $view !== null && $this->scene->canPresentFieldMotion();
    foreach ($subjects as $subject) {
      $position = $view === null ? $subject->position : $subject->getGraphicalSpriteWorldPosition();
      $drawn = $view === null ? null : FieldSpriteBounds::findBounds($subject, $view, $this->scene->camera->getWorldOrigin());
      $drawn = $view === null ? $this->getTerminalBounds($subject)
        : ($drawn === null ? new FieldSpriteBounds($position->x, $position->y, $position->x + 1, $position->y + 1)
        : new FieldSpriteBounds($drawn->left / FieldViewport::TILE_SIZE, $drawn->top / FieldViewport::TILE_SIZE,
            $drawn->right / FieldViewport::TILE_SIZE, $drawn->bottom / FieldViewport::TILE_SIZE));
      $id = $subject->getGraphicalSpriteId(); $prior = $this->samples[$id] ?? null; $sweep = null;
      if ($slides && $subject->getGraphicalSpriteMotion() !== null && $prior !== null) {
        $dx = $prior['position']->x - $position->x; $dy = $prior['position']->y - $position->y;
        $sweep = $dx !== 0.0 || $dy !== 0.0
          ? new FieldSpriteBounds($drawn->left + $dx, $drawn->top + $dy, $drawn->right + $dx, $drawn->bottom + $dy)
          : $prior['sweep'];
      }
      $bounds[] = FieldSpriteBounds::join([$drawn, $sweep]);
      $next[$id] = ['position' => clone $position, 'sweep' => $sweep];
    }
    $this->samples = $next;
    return $bounds;
  }

  /** Terminal sprites retain their own rows, display widths and player shape clipping. */
  private function getTerminalBounds(Player|Npc|StagedActor $subject): FieldSpriteBounds
  {
    $position = $subject->position; $offsetY = 0;
    if ($subject instanceof Npc) { $rows = [$subject->getFieldTerminalSprite()]; }
    elseif ($subject instanceof Player) {
      $shape = $subject->getShape(); $offsetY = $shape->getY(); $rows = [];
      for ($row = $offsetY; $row < $offsetY + $shape->getHeight(); $row++) {
        $rows[] = TerminalText::sliceSymbols($subject->sprite[$row], $shape->getX(), $shape->getWidth());
      }
    } else { $rows = $subject->sprite; }
    $width = max([1, ...array_map(TerminalText::displayWidth(...), $rows)]);
    return new FieldSpriteBounds($position->x, $position->y + $offsetY,
      $position->x + $width, $position->y + $offsetY + max(1, count($rows)));
  }

  public function update(): void
  {
    $scene = $this->scene;
    if ($scene->cinematicController?->active() === null) { $this->reset(); return; }
    $scope = $scene->cinematicController->dialogueFramingScope;
    if ($scope !== $this->scope) { $this->clear(); $this->scope = $scope; }
    $owners = $scene->getActiveFieldPresentations();
    $owners = array_values(array_filter($owners, static fn($owner): bool => $owner instanceof DialoguePresentationProviderInterface));
    $lines = array_map(static fn($owner) => $owner->getDialogueSnapshot(), $owners);
    $line = $lines[0] ?? null;
    $this->diagnosticKey = $scene->currentMapId . ':phase:' . ($scope === null ? 'none' : spl_object_id($scope));
    try { $subjects = $scene->cinematicController->getFramingSubjects(); }
    catch (\RuntimeException $error) {
      $this->reportFailure('A required cinematic framing subject is unavailable: ' . $error->getMessage());
      return;
    }
    $view = $scene->getFieldViewport();
    $screenWidth = $scene->camera->screen->getWidth();
    $screenHeight = $scene->camera->screen->getHeight();
    $layout = [$view, $screenWidth, $screenHeight];
    if ($layout != $this->viewportLayout) {
      $this->clear();
      $this->viewportLayout = $layout;
    }
    $visible = $view?->getVisibleBounds() ?? new FieldSpriteBounds(0, 0, $screenWidth, $screenHeight);
    if ($view !== null && $scene->cinematicController->getCurrentFramingScope() !== null
      && array_any($subjects, static fn($subject): bool => $subject instanceof StagedActor && !$subject->isGraphicallyVisible)) {
      $this->reportFailure('A required cinematic framing subject has no visible graphical presentation.');
      return;
    }
    if ($view !== null && $scene->cinematicController->getCurrentFramingScope() !== null) {
      foreach ($subjects as $subject) {
        $expectsArtwork = $subject instanceof StagedActor ? $subject->hasGraphicalArtwork
          : $subject->getGraphicalCharacterSheet() !== null;
        if ($expectsArtwork && $subject->getGraphicalSpriteDefinition() === null) {
          $this->reportFailure('A required cinematic framing subject has invalid or unavailable graphical artwork.');
          return;
        }
      }
    }
    $bounds = $this->collectSubjectBounds($subjects, $view);
    if (array_any($lines, static fn($line): bool => $line->context->skitId !== null)
      || $subjects === []) { $this->clear(); return; }
    $hasOverlay = $scene->cinematicPresentation?->overlayPresentation !== null;
    if ($line === null && !$hasOverlay) {
      if ($scene->cinematicController->getCurrentFramingScope() !== null && $this->safeAreas === []) {
        $this->safeAreas = [$visible];
      }
      if ($this->safeAreas !== []) { $this->frameSubjects($bounds, $visible); }
      return;
    }
    if ($line !== null) { $this->diagnosticKey .= ':' . $line->speaker; }
    if ($view !== null) {
      $root = $scene->getFieldAssetRoot();
      if ($root !== $this->assetRoot) {
        $this->assetRoot = $root;
        $this->dialogue = $root === null ? null : new DialogueScenePresentation($root);
      }
      $width = $view->grid->columns * $view->grid->cellWidth;
      $height = $view->grid->rows * $view->grid->cellHeight;
      $protected = [];
      foreach ($lines as $activeLine) {
        $areas = $this->dialogue?->getProtectedBounds($activeLine, $width, $height);
        if ($areas === null) { $this->clear(); return; }
        array_push($protected, ...$areas);
      }
      array_push($protected, ...($scene->cinematicPresentation?->getProtectedOverlayBounds(
        $width, $height, $root, $view->grid) ?? []));
      $tile = FieldViewport::TILE_SIZE * $view->zoom;
      // Preserve pixel edges for the camera's integer-cell origin calculation.
      $this->safeAreas = array_map(static fn(CanvasRectangle $area): FieldSpriteBounds => new FieldSpriteBounds(
        ($area->x - $view->surfaceOffsetX) / $tile, ($area->y - $view->surfaceOffsetY) / $tile,
        ($area->x + $area->width - $view->surfaceOffsetX) / $tile,
        ($area->y + $area->height - $view->surfaceOffsetY) / $tile),
        self::findGraphicalSafeAreas($protected, $width, $height));
    } else {
      // Terminal text footprints are independent of graphical assets and scale.
      $protected = array_map(static function ($owner): CanvasRectangle {
        $rect = $owner->getPresentationBounds();
        return new CanvasRectangle($rect->getX(), $rect->getY(), $rect->getWidth(), $rect->getHeight());
      }, $owners);
      array_push($protected, ...($scene->cinematicPresentation?->getProtectedOverlayBounds($screenWidth, $screenHeight) ?? []));
      $safe = self::findSafeArea($protected, $screenWidth, $screenHeight);
      $this->safeAreas = $safe === null ? [] : [
        new FieldSpriteBounds($visible->left, $safe->y, $visible->right, $safe->y + $safe->height),
      ];
    }
    $this->frameSubjects($bounds, $visible);
  }

  private function getMotionSeconds(): float
  {
    return $this->scene->canPresentCameraMotion() ? self::TRANSITION_SECONDS : 0.0;
  }

  private function frameSubjects(array $bounds, FieldSpriteBounds $visible): void
  {
    $group = FieldSpriteBounds::join($bounds);
    if ($group === null || !$this->scene->camera->frameBoundsInAreas($this, $group, $this->safeAreas, $this->getMotionSeconds(),
        visibleArea: $visible)) {
      $this->reportFailure();
    } else { $this->diagnostic = null; }
  }

  private function reportFailure(?string $message = null): void
  {
    $this->clear();
    $this->diagnostic = $message ?? 'The current cinematic cast does not fit clear of the dialogue. Adjust its staging or reading viewport.';
    if (!isset($this->notes[$this->diagnosticKey])) {
      Debug::warn($this->diagnostic); $this->notes[$this->diagnosticKey] = true;
    }
  }

  /**
   * @param list<CanvasRectangle> $protected
   * @return list<CanvasRectangle>
   */
  private static function findGraphicalSafeAreas(array $protected, int $width, int $height): array
  {
    $tops = [0]; $bottoms = [$height]; $obstacles = [];
    foreach ($protected as $rect) {
      $left = max(0, $rect->x); $right = min($width, $rect->x + $rect->width);
      $top = max(0, $rect->y); $bottom = min($height, $rect->y + $rect->height);
      if ($left >= $right || $top >= $bottom) { continue; }
      $obstacles[] = [$left, $top, $right, $bottom];
      $tops[] = $bottom; $bottoms[] = $top;
    }
    usort($obstacles, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $areas = [];
    // A clear rectangle can extend vertically until it meets an obstacle or the viewport edge.
    $bottoms = array_unique($bottoms);
    foreach (array_unique($tops) as $top) {
      foreach ($bottoms as $bottom) {
        if ($top >= $bottom) { continue; }
        $start = 0;
        foreach ($obstacles as [$left, $blockedTop, $right, $blockedBottom]) {
          if ($blockedTop >= $bottom || $blockedBottom <= $top) { continue; }
          if ($left > $start) { $areas[] = new CanvasRectangle($start, $top, $left - $start, $bottom - $top); }
          $start = max($start, $right);
        }
        if ($start < $width) { $areas[] = new CanvasRectangle($start, $top, $width - $start, $bottom - $top); }
      }
    }
    // Keep the established preference for broad shots, with a stable order for equal areas.
    usort($areas, static fn(CanvasRectangle $a, CanvasRectangle $b): int =>
      [-$a->width, -$a->height, $a->y, $a->x] <=> [-$b->width, -$b->height, $b->y, $b->x]);
    return $areas;
  }

  /** Largest full-width horizontal band clear of every actual UI protected rectangle. */
  public static function findSafeArea(array $protected, int $width, int $height): ?CanvasRectangle
  {
    $blocked = [];
    foreach ($protected as $rect) {
      if (!$rect instanceof CanvasRectangle || $rect->x >= $width) { continue; }
      $top = max(0, min($height, $rect->y)); $bottom = max(0, min($height, $rect->y + $rect->height));
      if ($bottom > $top) { $blocked[] = [$top, $bottom]; }
    }
    usort($blocked, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
    $start = 0; $best = null;
    foreach ([...$blocked, [$height, $height]] as [$top, $bottom]) {
      if ($top > $start && ($best === null || $top - $start > $best->height)) {
        $best = new CanvasRectangle(0, $start, $width, $top - $start);
      }
      $start = max($start, $bottom);
    }
    return $best;
  }
}
