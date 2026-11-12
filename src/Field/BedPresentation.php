<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Rendering\FieldViewport;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Presentation\PresentationWorldCanvas;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use InvalidArgumentException;

/** One atomic bed subject owns base, current occupant and foreground covers in that order. */
final class BedPresentation
{
    public static function compose(WorldObject $object, GraphicalSpriteDefinition $base, GraphicalSpriteDefinition $cover,
        ?Npc $occupant, ?GraphicalSpriteDefinition $body, array $owners = []): ?PresentationWorldCanvas
    {
        $anchorX = ($object->definition->x + .5) * FieldViewport::TILE_SIZE;
        $anchorY = ($object->definition->y + 1) * FieldViewport::TILE_SIZE;
        $parts = [];
        foreach ([['base', $base, 0], ['cover', $cover, 2]] as [$id, $frame, $layer]) {
            $pivot = $frame->pivot ?? $object->definition->pivot;
            $parts[] = [$id, $frame, $anchorX - $pivot->x * $frame->width,
                $anchorY - $frame->lift - $pivot->y * $frame->height, $layer];
        }
        if ($occupant !== null && $body !== null) {
            $point = $object->locateAttachment($occupant->bed->attachment);
            if ($point === null) { return null; }
            $parts[] = ['occupant', $body, $point['x'] - $body->width / 2, $point['y'] - $body->height / 2, 1];
        }
        // Canvas child images currently expose mirrors, not quarter turns. Preserve the Terminal
        // presentation and report this shared canvas capability gap for an unsupported turned role.
        if (array_any($parts, static fn(array $part): bool => $part[1]->quarterTurns % 2 !== 0)) {
            throw new InvalidArgumentException('Bed composition needs canvas child quarter-turn support for ' . $object->definition->id);
        }
        $left = floor(min(array_column($parts, 2)));
        $top = floor(min(array_column($parts, 3)));
        $right = ceil(max(array_map(static fn(array $part): float => $part[2] + $part[1]->width, $parts)));
        $bottom = ceil(max(array_map(static fn(array $part): float => $part[3] + $part[1]->height, $parts)));
        // Keep the cell's anchor inside the compound canvas so pivots remain normalized.
        $left = min($left, $anchorX); $top = min($top, $anchorY);
        $right = max($right, $anchorX); $bottom = max($bottom, $anchorY);
        $width = max(1, (int)($right - $left)); $height = max(1, (int)($bottom - $top));
        $images = array_map(static fn(array $part): CanvasImage => new CanvasImage($part[0], $part[1]->asset,
            new CanvasRectangle($part[2] - $left, $part[3] - $top, $part[1]->width, $part[1]->height),
            $part[4], $part[1]->sourceRect, flipX: $part[1]->quarterTurns === 2, flipY: $part[1]->quarterTurns === 2), $parts);
        return new PresentationWorldCanvas($object->getGraphicalSpriteId(), $object->definition->x, $object->definition->y,
            new PresentationSpritePivot(($anchorX - $left) / $width, ($anchorY - $top) / $height),
            new PresentationCanvas($width, $height, images: $images,
                presentationOwners: array_values(array_unique([$object->getGraphicalSpriteId(), ...$owners]))), $base->layer);
    }
}
