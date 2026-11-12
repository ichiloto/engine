<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\WorldObject;
use Ichiloto\Engine\Field\WorldObjectAttachment;
use Ichiloto\Engine\Rendering\Sprites\FieldSpritePlacement;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;

/**
 * A staged visual held on a feature of a world object's art. The terminal
 * shows it on the attachment's cell; graphical art is centred on the
 * attachment's point of the object's current artwork, so replacing the art
 * moves the visual with it.
 */
final class CinematicAttachment
{
    public function __construct(public readonly WorldObject $object, public readonly WorldObjectAttachment $attachment) {}

    /** The attachment's terminal cell. */
    public Vector2 $cell {
        get => new Vector2($this->object->definition->x + $this->attachment->cellX,
            $this->object->definition->y + $this->attachment->cellY);
    }

    /**
     * The image centred on the attachment and the cell that places it, or
     * null while the object draws no art and its terminal cell presents it.
     *
     * @return array{position: Vector2, image: GraphicalSpriteDefinition}|null
     */
    public function place(GraphicalSpriteDefinition $image): ?array
    {
        $point = $this->object->locateAttachment($this->attachment);
        return $point === null ? null : FieldSpritePlacement::centre($point['x'], $point['y'], $image);
    }
}
