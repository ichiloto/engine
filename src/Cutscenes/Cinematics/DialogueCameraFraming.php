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
  private ?DialogueScenePresentation $dialogue = null;
  private ?string $assetRoot = null;
  private array $notes = [];
  /** @var array<string,array{position: \Ichiloto\Engine\Core\Vector2, sweep: ?FieldSpriteBounds}> */
  private array $samples = [];
  public private(set) ?string $diagnostic = null;

  public function __construct(private readonly GameScene $scene) {}

  public function clear(): void
  {
    $this->scene->camera->clearFraming($this);
    $this->diagnostic = null;
  }

  public function reset(): void
  {
    $this->clear(); $this->samples = [];
  }

  /** Capture the admitted cast before the first cinematic command can move it. */
  public function observeSubjects(): void
  {
    $this->collectSubjectBounds($this->scene->cinematicController?->getFramingSubjects() ?? [], $this->scene->getFieldViewport());
  }

  private function collectSubjectBounds(array $subjects, ?FieldViewport $view): array
  {
    $bounds = $next = [];
    foreach ($subjects as $subject) {
      $position = $view === null ? $subject->position : $subject->getGraphicalSpriteWorldPosition();
      $drawn = $view === null ? null : FieldSpriteBounds::findBounds($subject, $view, $this->scene->camera->getWorldOrigin());
      $drawn = $view === null ? $this->getTerminalBounds($subject)
        : ($drawn === null ? new FieldSpriteBounds($position->x, $position->y, $position->x + 1, $position->y + 1)
        : new FieldSpriteBounds($drawn->left / FieldViewport::TILE_SIZE, $drawn->top / FieldViewport::TILE_SIZE,
            $drawn->right / FieldViewport::TILE_SIZE, $drawn->bottom / FieldViewport::TILE_SIZE));
      $id = $subject->getGraphicalSpriteId(); $prior = $this->samples[$id] ?? null; $sweep = null;
      if ($view !== null && !\Ichiloto\Engine\UI\Accessibility::prefersReducedMotion()
        && $subject->getGraphicalSpriteMotion() !== null && $prior !== null) {
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
    if ($scene->cinematicController?->active() === null) { $this->clear(); return; }
    $context = $scene->getPresentationContext();
    $owners = $context !== null ? $context->getActivePresentations() : $scene->getUI()->getActivePresentations();
    $owners = array_values(array_filter($owners, static fn($owner): bool => $owner instanceof DialoguePresentationProviderInterface));
    $lines = array_map(static fn($owner) => $owner->getDialogueSnapshot(), $owners);
    $line = $lines[0] ?? null;
    $subjects = $scene->cinematicController->getFramingSubjects();
    $view = $scene->getFieldViewport();
    $bounds = $this->collectSubjectBounds($subjects, $view);
    if ($line === null || array_any($lines, static fn($line): bool => $line->context->skitId !== null)
      || $subjects === []) { $this->clear(); return; }
    if ($view !== null) {
      $root = $context?->assetRoot ?? $scene->getGame()->getRendererRuntime()?->getAssetRoot();
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
      $tile = FieldViewport::TILE_SIZE * $view->zoom;
      $offsetY = ($height - $view->rows * $tile) / 2;
      $safe = self::findSafeArea($protected, $width, $height);
      $top = $safe === null ? 0 : max(0, ceil(($safe->y - $offsetY) / $tile));
      $bottom = $safe === null ? 0 : min($view->rows, floor(($safe->y + $safe->height - $offsetY) / $tile));

    } else {
      // Terminal text footprints are independent of graphical assets and scale.
      $protected = array_map(static function ($owner): CanvasRectangle {
        $rect = $owner->getPresentationBounds();
        return new CanvasRectangle($rect->getX(), $rect->getY(), $rect->getWidth(), $rect->getHeight());
      }, $owners);
      $width = $scene->camera->screen->getWidth(); $height = $scene->camera->screen->getHeight();
      $safe = self::findSafeArea($protected, $width, $height);
      $top = $safe?->y ?? 0; $bottom = $safe === null ? 0 : $safe->y + $safe->height;

    }
    $group = FieldSpriteBounds::join($bounds);
    if ($bottom <= $top || $group === null || !$scene->camera->frameBounds($this, $group,
      new FieldSpriteBounds(0, $top, $scene->camera->screen->getWidth(), $bottom))) {
      $this->clear();
      $this->diagnostic = 'The current cinematic cast does not fit clear of the dialogue. Adjust its staging or reading viewport.';
      $key = $scene->currentMapId . ':' . $line->speaker;
      if (!isset($this->notes[$key])) { Debug::warn($this->diagnostic); $this->notes[$key] = true; }
    } else { $this->diagnostic = null; }
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
