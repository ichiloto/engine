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

/** Reusable presentation intent; occupancyStamp is an authoring recipe, never runtime collision. */
final readonly class WorldObjectResource
{
    private function __construct(
        public string $id,
        public string $name,
        public PresentationSpritePivot $pivot,
        public GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation $sprites,
        /** @var list<array{x: int, y: int, type: CollisionType}> Relative physical cells. */
        public array $occupancyStamp,
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
        if (!is_array($data) || array_diff(array_keys($data), ['name', 'pivot', 'sprites2d', 'occupancyStamp']) !== []
            || !is_string($data['name'] ?? null) || trim($data['name']) === ''
            || str_contains($data['name'], "\0") || preg_match('//u', $data['name']) !== 1
            || !is_array($data['pivot'] ?? null) || !is_array($data['sprites2d'] ?? null)) {
            throw new InvalidArgumentException("World object resource '{$id}' requires name, pivot and sprites2d, with optional occupancyStamp.");
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
        $sprites = array_map(static fn(mixed $value): mixed => $value, $data['sprites2d']);
        if (is_array($sprites['animation'] ?? null)) {
            $animation = array_map(static fn(mixed $value): mixed => $value, $sprites['animation']);
            // Pose intent retains a frame list: detach it before sharing across placements.
            if (is_array($animation['frames'] ?? null)) {
                $animation['frames'] = array_map(static fn(mixed $frame): mixed => $frame, $animation['frames']);
            }
            $sprites['animation'] = $animation;
        }
        return new self($id, $data['name'], PresentationSpritePivot::fromArray($data['pivot']),
            FieldSpriteRole::readDefinition($sprites), $cells);
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
