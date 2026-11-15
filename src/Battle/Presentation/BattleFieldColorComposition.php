<?php
declare(strict_types=1);
namespace Ichiloto\Engine\Battle\Presentation;
use Ichiloto\Engine\Rendering\Presentation\Canvas\{CanvasDrawOrder, CanvasDrawingKind, CanvasGroup, CanvasIndicator,
  CanvasIndicatorReferences, CanvasViewportFit, PresentationCanvas};
use SplObjectStorage;
use LogicException;

/** Consecutive treatments preserve the painter's order and every original source primitive. */
final class BattleFieldColorComposition
{
  public static function compose(PresentationCanvas $canvas, BattleCanvasOwners $owners): PresentationCanvas
  {
    CanvasIndicatorReferences::assertCanvases([$canvas]);
    $runs = []; $hasTreatment = false; $lastKey = null;
    $items = CanvasDrawOrder::getItems($canvas); $sharedLayers = [];
    foreach ($items as $item) {
      $drawing = $item['drawing'];
      if (!$owners->isScreenDrawing($drawing)) { $sharedLayers[$drawing->layer] = true; }
    }
    // Root-qualified paths name groups. A grouped local marker needs its root UI source grouped too.
    foreach ($canvas->indicators as $indicator) {
      if ($indicator->imagePath === null && !$owners->isScreenDrawing($indicator)) {
        $source = CanvasIndicatorReferences::resolve($canvas, $canvas, $indicator);
        if ($owners->isScreenDrawing($source)) { $sharedLayers[$source->layer] = true; }
      }
    }
    $rootItems = [];
    /** @var SplObjectStorage<object, ?string> $placements */
    $placements = new SplObjectStorage(); $groupPlacements = [];
    foreach ($items as $item) {
      $drawing = $item['drawing']; $transform = $owners->getTransform($drawing);
      $hasTreatment = $hasTreatment || $transform !== null;
      if ($owners->isScreenDrawing($drawing) && !isset($sharedLayers[$drawing->layer])) {
        $rootItems[] = $item; $lastKey = null; $placements[$drawing] = null;
        if ($drawing instanceof CanvasGroup) { $groupPlacements[$drawing->id] = null; }
        continue;
      }
      $key = $owners->getDrawingKey($drawing);
      if ($key !== $lastKey) { $runs[] = ['id' => 'field-color-' . hash('sha256', $item['kind']->value . ':' . $drawing->id), 'transform' => $transform, 'items' => []]; $lastKey = $key; }
      $index = array_key_last($runs); $runs[$index]['items'][] = $item;
      $runs[$index]['layer'] = $drawing->layer;
      $placements[$drawing] = $runs[$index]['id'];
      if ($drawing instanceof CanvasGroup) { $groupPlacements[$drawing->id] = $runs[$index]['id']; }
    }
    if (!$hasTreatment) { return $canvas; }
    $groups = [];
    foreach ($runs as $index => $run) {
      $images = $surfaces = $indicators = $text = $labels = $children = [];
      foreach ($run['items'] as ['kind' => $kind, 'drawing' => $drawing]) {
        match ($kind) {
          CanvasDrawingKind::IMAGE => $images[] = $drawing,
          CanvasDrawingKind::COMPOSITE => $surfaces[] = $drawing,
          CanvasDrawingKind::TEXT => $text[] = $drawing,
          CanvasDrawingKind::LABEL => $labels[] = $drawing,
          CanvasDrawingKind::INDICATOR => $indicators[] = self::relocateIndicator($drawing, $canvas, $run['id'], $placements, $groupPlacements),
          CanvasDrawingKind::GROUP => $children[] = self::relocateGroup($drawing, $groupPlacements),
        };
      }
      $local = new PresentationCanvas($canvas->width, $canvas->height, $images, $indicators, $text, $surfaces,
        [], labels: $labels, viewportFit: CanvasViewportFit::NATIVE, groups: $children);
      $groups[] = new CanvasGroup($run['id'], 0, 0, $local, $run['layer'], colorTransform: $run['transform']);
    }
    $images = $surfaces = $indicators = $text = $labels = [];
    foreach ($rootItems as ['kind' => $kind, 'drawing' => $drawing]) {
      match ($kind) {
        CanvasDrawingKind::IMAGE => $images[] = $drawing,
        CanvasDrawingKind::COMPOSITE => $surfaces[] = $drawing,
        CanvasDrawingKind::TEXT => $text[] = $drawing,
        CanvasDrawingKind::LABEL => $labels[] = $drawing,
        CanvasDrawingKind::INDICATOR => $indicators[] = self::relocateIndicator($drawing, $canvas, null, $placements, $groupPlacements),
        CanvasDrawingKind::GROUP => $groups[] = self::relocateGroup($drawing, $groupPlacements),
      };
    }
    $result = new PresentationCanvas($canvas->width, $canvas->height, $images, $indicators, $text, $surfaces,
      $canvas->getOverlayProtection(), $canvas->presentationOwners, $labels, $canvas->viewportFit, $groups);
    CanvasIndicatorReferences::assertCanvases([$result]);
    return $result;
  }

  private static function relocateIndicator(CanvasIndicator $indicator, PresentationCanvas $root, ?string $ownRun,
    SplObjectStorage $placements, array $groupPlacements): CanvasIndicator
  {
    if ($indicator->imagePath !== null) { return self::relocateQualifiedIndicator($indicator, $groupPlacements); }
    $target = CanvasIndicatorReferences::resolve($root, $root, $indicator);
    $targetRun = $placements[$target];
    if ($targetRun === $ownRun) { return $indicator; }
    if ($targetRun === null) { throw new LogicException('A grouped indicator needs its root source promoted into a group.'); }
    return self::copyIndicator($indicator, [$targetRun, $indicator->imageId]);
  }

  private static function relocateQualifiedIndicator(CanvasIndicator $indicator, array $groupPlacements): CanvasIndicator
  {
    $path = $indicator->imagePath;
    return $path === null || $groupPlacements[$path[0]] === null ? $indicator
      : self::copyIndicator($indicator, [$groupPlacements[$path[0]], ...$path]);
  }

  private static function copyIndicator(CanvasIndicator $indicator, array $path): CanvasIndicator
  {
    return new CanvasIndicator($indicator->id, $indicator->imageId, $indicator->kind, $indicator->bounds,
      $indicator->strokeWidth, $indicator->color, $indicator->layer, $path);
  }

  private static function relocateGroup(CanvasGroup $group, array $groupPlacements): CanvasGroup
  {
    $old = $group->canvas;
    $indicators = array_map(static fn(CanvasIndicator $indicator): CanvasIndicator =>
      self::relocateQualifiedIndicator($indicator, $groupPlacements), $old->indicators);
    $groups = array_map(static fn(CanvasGroup $child): CanvasGroup => self::relocateGroup($child, $groupPlacements), $old->groups);
    if ($indicators === $old->indicators && $groups === $old->groups) { return $group; }
    return new CanvasGroup($group->id, $group->x, $group->y,
      new PresentationCanvas($old->width, $old->height, $old->images, $indicators, $old->textLayers, $old->composites,
        $old->protectedAreas, $old->presentationOwners, $old->labels, $old->viewportFit, $groups),
      $group->layer, $group->clipRect, $group->colorTransform, $group->scale);
  }
}
