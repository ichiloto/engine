<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * Reusable presentation intent; occupancyStamp is an authoring recipe, never runtime collision.
 *
 * A doorway names the cells, relative to a placement's anchor, where the
 * artwork paints an opening the player walks through. It is a fact about the
 * art, which conforms to the map: validation requires every placement's
 * doorway to stand on an entrance ({@see \Ichiloto\Engine\Field\Validation\MapDoorwayRule}).
 */
final readonly class WorldObjectResource
{
    public const int MAX_DOORWAY_CELLS = 16;

    private function __construct(
        public string $id,
        public string $name,
        public PresentationSpritePivot $pivot,
        public GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation|null $sprites,
        /** @var list<array{x: int, y: int, type: CollisionType}> Relative physical cells. */
        public array $occupancyStamp,
        public ?WorldSignStyle $sign = null,
        /** @var list<array{x: int, y: int}> Relative cells of the painted opening. */
        public array $doorway = [],
    ) {}

    public static function assertId(mixed $id): void
    {
        if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/', $id) !== 1) {
            throw new InvalidArgumentException('World object resource ids must be stable lowercase identifiers of 1..64 characters.');
        }
    }

    public static function fromArray(string $id, mixed $data): self
    {
        self::assertId($id);
        if (!is_array($data) || array_diff(array_keys($data), ['name', 'pivot', 'sprites2d', 'occupancyStamp', 'sign', 'doorway']) !== []
            || !is_string($data['name'] ?? null) || trim($data['name']) === ''
            || str_contains($data['name'], "\0") || preg_match('//u', $data['name']) !== 1
            || !is_array($data['pivot'] ?? null)
            || (array_key_exists('sprites2d', $data) === array_key_exists('sign', $data))
            || (array_key_exists('sprites2d', $data) && !is_array($data['sprites2d']))
            || (array_key_exists('sign', $data) && !is_array($data['sign']))) {
            throw new InvalidArgumentException("World object resource '{$id}' requires name, pivot and exactly one sprites2d or sign presentation, with optional occupancyStamp and doorway.");
        }
        $stamp = array_key_exists('occupancyStamp', $data) ? $data['occupancyStamp'] : [];
        if (!is_array($stamp) || !array_is_list($stamp) || count($stamp) > WorldObjectDefinition::MAX_CELLS) {
            throw new InvalidArgumentException('Resource occupancyStamp must be a list of at most 256 relative cells.');
        }
        $cells = $seen = [];
        foreach ($stamp as $cell) {
            if (!is_array($cell) || array_diff(array_keys($cell), ['x', 'y', 'type']) !== []
                || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null)
                || !($cell['type'] ?? null) instanceof CollisionType || $cell['type'] === CollisionType::PASS_THROUGH
                || isset($seen[$cell['y']][$cell['x']])) {
                throw new InvalidArgumentException('Resource occupancyStamp requires distinct integer x/y offsets and resolved CollisionType cases.');
            }
            SpriteValidation::validateSigned32BitRange($cell['x']);
            SpriteValidation::validateSigned32BitRange($cell['y']);
            $seen[$cell['y']][$cell['x']] = true;
            // Detach authored references; mutable source arrays cannot alter the admitted recipe.
            $cells[] = ['x' => $cell['x'], 'y' => $cell['y'], 'type' => $cell['type']];
        }
        $doorway = self::readDoorway($id, $data);
        $sprites = array_map(static fn(mixed $value): mixed => $value, $data['sprites2d'] ?? []);
        if (is_array($sprites['animation'] ?? null)) {
            $animation = array_map(static fn(mixed $value): mixed => $value, $sprites['animation']);
            // Pose intent retains a frame list: detach it before sharing across placements.
            if (is_array($animation['frames'] ?? null)) {
                $animation['frames'] = array_map(static fn(mixed $frame): mixed => $frame, $animation['frames']);
            }
            $sprites['animation'] = $animation;
        }
        return new self($id, $data['name'], PresentationSpritePivot::fromArray($data['pivot']),
            array_key_exists('sprites2d', $data) ? FieldSpriteRole::readDefinition($sprites) : null, $cells,
            array_key_exists('sign', $data) ? WorldSignStyle::fromArray($data['sign']) : null, $doorway);
    }

    /** @return list<array{x: int, y: int}> */
    private static function readDoorway(string $id, array $data): array
    {
        if (!array_key_exists('doorway', $data)) { return []; }
        $doorway = $data['doorway'];
        if (!is_array($doorway) || !array_is_list($doorway) || $doorway === [] || count($doorway) > self::MAX_DOORWAY_CELLS) {
            throw new InvalidArgumentException("Resource '{$id}' doorway must be a list of 1.." . self::MAX_DOORWAY_CELLS . ' relative cells.');
        }
        $cells = $seen = [];
        foreach ($doorway as $cell) {
            if (!is_array($cell) || array_diff(array_keys($cell), ['x', 'y']) !== [] || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null)
                || isset($seen[$cell['y']][$cell['x']])) {
                throw new InvalidArgumentException("Resource '{$id}' doorway requires distinct integer x/y offsets from the anchor.");
            }
            SpriteValidation::validateSigned32BitRange($cell['x']);
            SpriteValidation::validateSigned32BitRange($cell['y']);
            $seen[$cell['y']][$cell['x']] = true;
            $cells[] = ['x' => $cell['x'], 'y' => $cell['y']];
        }
        return $cells;
    }

    /** Resolve for explicit authoring only. Validate every cell before a consumer writes occupancy. */
    public function resolveOccupancyStamp(MapLayerSet $layers, int $x, int $y): array
    {
        $grid = $layers->getComposedGrid();
        if (!isset($grid[$y][$x])) {
            throw new InvalidArgumentException("Resource '{$this->id}' stamp anchor must be inside the map.");
        }
        $cells = [];
        foreach ($this->occupancyStamp as $cell) {
            $targetX = $x + $cell['x'];
            $targetY = $y + $cell['y'];
            if (!isset($grid[$targetY][$targetX])) {
                throw new InvalidArgumentException("Resource '{$this->id}' occupancyStamp extends outside the map.");
            }
            $cells[] = ['x' => $targetX, 'y' => $targetY, 'type' => $cell['type']];
        }
        return $cells;
    }
}
