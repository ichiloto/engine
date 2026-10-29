<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Animations\Field\FieldPoseAnimation;
use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Rendering\Presentation\PresentationSpritePivot;
use Ichiloto\Engine\Rendering\Sprites\CharacterSheet;
use Ichiloto\Engine\Rendering\Sprites\FieldSpriteRole;
use Ichiloto\Engine\Rendering\Sprites\GraphicalSpriteDefinition;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use Ichiloto\Engine\Rendering\Tilesets\TileAppearance;
use InvalidArgumentException;

/**
 * Reusable presentation intent; occupancyStamp is an authoring recipe, never runtime collision.
 *
 * A doorway names the cells, relative to a placement's anchor, where the
 * artwork paints a door, and where each door's entrance lies: on the painted
 * opening, or in front of a door painted on a solid face. It is a fact about
 * the art, which conforms to the map: validation requires every placement's
 * doorway to meet an entrance exactly as declared ({@see \Ichiloto\Engine\Field\Validation\MapDoorwayRule}).
 *
 * Attachments name features of the artwork where visuals attach, such as an
 * aerial; a visual staged on one follows the current art ({@see WorldObjectAttachment}).
 */
final readonly class WorldObjectResource
{
    public const int MAX_DOORWAY_CELLS = Doorway::MAX_CELLS;

    private function __construct(
        public string $id,
        public string $name,
        public PresentationSpritePivot $pivot,
        public GraphicalSpriteDefinition|CharacterSheet|FieldPoseAnimation|null $sprites,
        /** @var list<array{x: int, y: int, type: CollisionType}> Relative physical cells. */
        public array $occupancyStamp,
        public ?WorldSignStyle $sign = null,
        /** @var list<array{x: int, y: int, entrance: DoorwayEntrance}> Relative cells of the painted doors. */
        public array $doorway = [],
        /** @var list<WorldObjectAttachment> */
        public array $attachments = [],
        /** What the art depicts, for diagnostics; absence retains legacy unclassified-image inspection. */
        public ?TileAppearance $appearance = null,
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
        if (!is_array($data) || array_diff(array_keys($data), ['name', 'pivot', 'sprites2d', 'occupancyStamp', 'sign', 'doorway', 'attachments', 'appearance']) !== []
            || !is_string($data['name'] ?? null) || trim($data['name']) === ''
            || str_contains($data['name'], "\0") || preg_match('//u', $data['name']) !== 1
            || !is_array($data['pivot'] ?? null)
            || (array_key_exists('sprites2d', $data) === array_key_exists('sign', $data))
            || (array_key_exists('sprites2d', $data) && !is_array($data['sprites2d']))
            || (array_key_exists('sign', $data) && !is_array($data['sign']))) {
            throw new InvalidArgumentException("World object resource '{$id}' requires name, pivot and exactly one sprites2d or sign presentation, with optional occupancyStamp, doorway and attachments.");
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
            array_key_exists('sign', $data) ? WorldSignStyle::fromArray($data['sign']) : null, $doorway,
            array_key_exists('attachments', $data) ? WorldObjectAttachment::readList($id, $data['attachments']) : [],
            array_key_exists('appearance', $data) ? TileAppearance::parse($data['appearance']) : null);
    }

    public function findAttachment(string $id): ?WorldObjectAttachment
    {
        return array_find($this->attachments, static fn(WorldObjectAttachment $attachment): bool => $attachment->id === $id);
    }

    /** @return list<array{x: int, y: int, entrance: DoorwayEntrance}> */
    private static function readDoorway(string $id, array $data): array
    {
        return array_key_exists('doorway', $data) ? Doorway::readCells($data['doorway'], "Resource '{$id}'") : [];
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
