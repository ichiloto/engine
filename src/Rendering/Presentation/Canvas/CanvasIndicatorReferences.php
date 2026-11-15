<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Canvas;

use Closure;
use InvalidArgumentException;

/** Image association resolves against each complete root, independently of colour and paint order. */
final class CanvasIndicatorReferences
{
  public static function resolve(PresentationCanvas $root, PresentationCanvas $local,
    CanvasIndicator $indicator): CanvasImage|CanvasComposite
  {
    $target = $local;
    if ($indicator->imagePath !== null) {
      $target = $root;
      foreach (array_slice($indicator->imagePath, 0, -1) as $part) {
        $groups = array_values(array_filter($target->groups, static fn(CanvasGroup $group): bool => $group->id === $part));
        if (count($groups) !== 1) {
          throw new InvalidArgumentException('Indicator imagePath requires one existing group at each step: ' . $part);
        }
        $target = $groups[0]->canvas;
      }
    }
    // Preserve ordinary image-before-composite association when both share a leaf ID.
    return array_find($target->images, static fn(CanvasImage $image): bool => $image->id === $indicator->imageId)
      ?? array_find($target->composites, static fn(CanvasComposite $surface): bool => $surface->id === $indicator->imageId)
      ?? throw new InvalidArgumentException('Indicator source image/composite is absent from its selected canvas: ' . $indicator->imageId);
  }

  /** @param list<PresentationCanvas> $roots Each world/still/overlay root owns its own reference graph. */
  public static function assertCanvases(array $roots): void
  {
    if (!array_is_list($roots)) { throw new InvalidArgumentException('Indicator reference roots require a list.'); }
    foreach ($roots as $root) {
      foreach (CanvasFrameBudget::getCanvases([$root]) as $local) {
        foreach ($local->indicators as $indicator) { self::resolve($root, $local, $indicator); }
      }
    }
  }

  /** composeOverlay prefixes root group IDs; descendants retain their local IDs. */
  public static function prefixRootPaths(PresentationCanvas $canvas, string $prefix): PresentationCanvas
  {
    return $prefix === '' ? $canvas : self::mapRootPaths($canvas, static function (array $path) use ($prefix): array {
      $path[0] = $prefix . $path[0]; return $path;
    });
  }

  /** Promoting a complete canvas into a placed group adds that group to every qualified path. */
  public static function nestRootPaths(PresentationCanvas $canvas, string $groupId): PresentationCanvas
  {
    CanvasValidation::id($groupId);
    return self::mapRootPaths($canvas, static fn(array $path): array => [$groupId, ...$path]);
  }

  /** @param Closure(list<string>): list<string> $map */
  private static function mapRootPaths(PresentationCanvas $canvas, Closure $map): PresentationCanvas
  {
    $changed = false; $indicators = $groups = [];
    foreach ($canvas->indicators as $indicator) {
      $path = $indicator->imagePath === null ? null : $map($indicator->imagePath);
      if ($path === $indicator->imagePath) { $indicators[] = $indicator; continue; }
      $changed = true;
      $indicators[] = new CanvasIndicator($indicator->id, $indicator->imageId, $indicator->kind, $indicator->bounds,
        $indicator->strokeWidth, $indicator->color, $indicator->layer, $path);
    }
    foreach ($canvas->groups as $group) {
      $child = self::mapRootPaths($group->canvas, $map);
      if ($child === $group->canvas) { $groups[] = $group; continue; }
      $changed = true;
      $groups[] = new CanvasGroup($group->id, $group->x, $group->y, $child,
        $group->layer, $group->clipRect, $group->colorTransform, $group->scale);
    }
    if (!$changed) { return $canvas; }
    return new PresentationCanvas($canvas->width, $canvas->height, $canvas->images, $indicators, $canvas->textLayers,
      $canvas->composites, $canvas->protectedAreas, $canvas->presentationOwners, $canvas->labels, $canvas->viewportFit, $groups);
  }
}
