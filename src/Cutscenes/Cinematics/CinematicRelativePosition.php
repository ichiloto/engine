<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Field\WorldObjectAttachment;
use Ichiloto\Engine\Scenes\Game\GameScene;
use InvalidArgumentException;

/**
 * An explicit subject-relative map location, resolved when its command or route leg begins.
 * A staged visual may instead sit on a world object's named attachment
 * ({@see WorldObjectAttachment}), which owns both its terminal cell and its point on the art.
 */
final class CinematicRelativePosition
{
    public const array SUBJECT_KINDS = ['player', 'npc', 'world_object', 'staged_actor', 'party_actor', 'marker'];

    /** @param bool $allowAttachment Whether the entry places a staged visual, the one thing an attachment holds. */
    public static function validate(array $entry, bool $allowAttachment = false): void
    {
        if (!is_array($entry['at'] ?? null) || array_is_list($entry['at'])
            || array_diff(array_keys($entry['at']), ['kind', 'id', 'attachment']) !== []
            || array_intersect(['x', 'y'], array_keys($entry)) !== []) {
            throw new InvalidArgumentException('A relative position requires at with kind/id and no independent x/y.');
        }
        $reference = $entry['at'];
        if (!in_array($reference['kind'] ?? null, self::SUBJECT_KINDS, true)) {
            throw new InvalidArgumentException('A relative position must name a map subject.');
        }
        if (array_key_exists('id', $reference) && !is_string($reference['id'])) {
            throw new InvalidArgumentException('A relative position subject id must be a string.');
        }
        CinematicSubjectResolver::validateReference($reference);
        if (array_key_exists('attachment', $reference)) {
            if (!$allowAttachment) {
                throw new InvalidArgumentException('Only a staged visual can sit on a world object attachment.');
            }
            if ($reference['kind'] !== 'world_object') {
                throw new InvalidArgumentException('An attachment belongs to a world object.');
            }
            WorldObjectAttachment::assertId($reference['attachment']);
            if (array_key_exists('offset', $entry)) {
                throw new InvalidArgumentException('A visual on an attachment sits on it; the attachment owns its cell and point, so it takes no offset.');
            }
            return;
        }
        $offset = array_key_exists('offset', $entry) ? $entry['offset'] : ['x' => 0, 'y' => 0];
        if (!is_array($offset) || $offset === [] || array_diff(array_keys($offset), ['x', 'y']) !== []) {
            throw new InvalidArgumentException('A relative position offset requires integer x and/or y.');
        }
        foreach ($offset as $value) {
            if (!is_int($value) || $value < -16384 || $value > 16384) {
                throw new InvalidArgumentException('Relative position offsets require integers within -16384..16384 cells.');
            }
        }
    }

    public static function resolve(GameScene $scene, array $entry): Vector2
    {
        self::validate($entry);
        $position = new CinematicSubjectResolver($scene)->position($entry['at']);
        $x = $position->x + ($entry['offset']['x'] ?? 0);
        $y = $position->y + ($entry['offset']['y'] ?? 0);
        return new Vector2($x, $y);
    }

    /** The world object attachment a staged visual's entry names, or null when it names none. */
    public static function resolveAttachment(GameScene $scene, array $entry): ?CinematicAttachment
    {
        self::validate($entry, true);
        if (!array_key_exists('attachment', $entry['at'])) { return null; }
        return new CinematicSubjectResolver($scene)->attachment($entry['at']['id'], $entry['at']['attachment']);
    }
}
