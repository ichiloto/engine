<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Field\Enumerations\DoorwayEntrance;
use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;

/**
 * Where artwork paints its doors: cells relative to the art's own origin, each
 * with where its entrance lies ({@see DoorwayEntrance}). A world object's
 * resource ({@see WorldObjectResource::$doorway}) and a tileset piece
 * ({@see \Ichiloto\Engine\Rendering\Tilesets\TilesetPiece::$doorway}) declare
 * one in the same form, and validation requires every placement's doors to
 * meet an entrance exactly so ({@see Validation\MapDoorwayRule}).
 */
final class Doorway
{
    /** The most door cells one piece of art declares. */
    public const int MAX_CELLS = 16;

    private function __construct()
    {
    }

    /**
     * Reads a declared doorway, such as `[['x' => 2, 'y' => 4], ['x' => 4, 'y' => 4, 'entrance' => 'front']]`.
     * An entrance left out lies on the painted opening.
     *
     * @param string $context Names the declaration's owner in messages, such as `Resource 'cave'`.
     * @return list<array{x: int, y: int, entrance: DoorwayEntrance}>
     * @throws InvalidArgumentException When the doorway is not a short list of distinct relative cells.
     */
    public static function readCells(mixed $doorway, string $context): array
    {
        if (!is_array($doorway) || !array_is_list($doorway) || $doorway === [] || count($doorway) > self::MAX_CELLS) {
            throw new InvalidArgumentException("{$context} doorway must be a list of 1.." . self::MAX_CELLS . ' relative cells.');
        }
        $cells = $seen = [];
        foreach ($doorway as $cell) {
            if (!is_array($cell) || array_diff(array_keys($cell), ['x', 'y', 'entrance']) !== [] || !is_int($cell['x'] ?? null) || !is_int($cell['y'] ?? null)
                || isset($seen[$cell['y']][$cell['x']])) {
                throw new InvalidArgumentException("{$context} doorway requires distinct integer x/y offsets from the anchor.");
            }
            $entrance = DoorwayEntrance::tryFrom(is_string($cell['entrance'] ?? null) ? $cell['entrance'] : '');
            if (array_key_exists('entrance', $cell) && $entrance === null) {
                throw new InvalidArgumentException("{$context} doorway entrance must be one of: "
                    . implode(', ', array_column(DoorwayEntrance::cases(), 'value')) . '.');
            }
            SpriteValidation::validateSigned32BitRange($cell['x']);
            SpriteValidation::validateSigned32BitRange($cell['y']);
            $seen[$cell['y']][$cell['x']] = true;
            $cells[] = ['x' => $cell['x'], 'y' => $cell['y'], 'entrance' => $entrance ?? DoorwayEntrance::ON];
        }
        return $cells;
    }

    /**
     * The declaration form of read cells, as an author writes it: an entrance on the opening is left out.
     *
     * @param list<array{x: int, y: int, entrance: DoorwayEntrance}> $cells
     * @return list<array{x: int, y: int, entrance?: string}>
     */
    public static function exportCells(array $cells): array
    {
        return array_map(static fn(array $cell): array => ['x' => $cell['x'], 'y' => $cell['y']]
            + ($cell['entrance'] === DoorwayEntrance::ON ? [] : ['entrance' => $cell['entrance']->value]), $cells);
    }
}
