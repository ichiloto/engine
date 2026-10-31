<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Scenes\Game\GameScene;
use InvalidArgumentException;

/**
 * The heading a staged position leaves its character facing: a fixed cardinal
 * `facing`, or `faceToward` a map subject named like a relative position's `at`.
 * A subject is resolved when its command or route leg begins, from the cell the
 * character stands on there; standing on the subject itself keeps the current heading.
 */
final class CinematicFacing
{
    public const array FIELDS = ['facing', 'faceToward'];
    public const array CARDINALS = ['north', 'east', 'south', 'west'];

    /** Whether an entry declares an end facing of either kind. */
    public static function isDeclared(array $entry): bool
    {
        return array_intersect(self::FIELDS, array_keys($entry)) !== [];
    }

    public static function validate(array $entry): void
    {
        if (array_key_exists('facing', $entry) && array_key_exists('faceToward', $entry)) {
            throw new InvalidArgumentException('Choose either a facing or a subject to face toward, not both.');
        }
        if (array_key_exists('facing', $entry)
            && (!is_string($entry['facing']) || !in_array(strtolower(trim($entry['facing'])), self::CARDINALS, true))) {
            throw new InvalidArgumentException('Facing must be north, south, east, or west.');
        }
        if (!array_key_exists('faceToward', $entry)) {
            return;
        }
        $reference = $entry['faceToward'];
        if (!is_array($reference) || array_is_list($reference) || array_diff(array_keys($reference), ['kind', 'id']) !== []
            || !in_array($reference['kind'] ?? null, CinematicRelativePosition::SUBJECT_KINDS, true)) {
            throw new InvalidArgumentException('Face toward requires a map subject kind and, where the kind needs one, its id.');
        }
        if (array_key_exists('id', $reference) && !is_string($reference['id'])) {
            throw new InvalidArgumentException('Face toward subject id must be a string.');
        }
        CinematicSubjectResolver::validateReference($reference);
    }

    /** The authored end heading for a character standing at $from, or null when none is declared or it cannot turn. */
    public static function resolve(GameScene $scene, array $entry, Vector2 $from): ?MovementHeading
    {
        self::validate($entry);
        if (isset($entry['facing'])) {
            return MovementHeading::from(ucfirst(strtolower(trim($entry['facing']))));
        }
        if (!isset($entry['faceToward'])) {
            return null;
        }
        return self::getHeadingToward($from, new CinematicSubjectResolver($scene)->position($entry['faceToward']));
    }

    /**
     * The cardinal heading from one cell toward another along the longer axis; an
     * equal split faces vertically, toward or away from the viewer. Null for the same cell.
     */
    public static function getHeadingToward(Vector2 $from, Vector2 $to): ?MovementHeading
    {
        $dx = intval($to->x) - intval($from->x);
        $dy = intval($to->y) - intval($from->y);
        return match (true) {
            $dx === 0 && $dy === 0 => null,
            abs($dx) > abs($dy) => $dx > 0 ? MovementHeading::EAST : MovementHeading::WEST,
            default => $dy > 0 ? MovementHeading::SOUTH : MovementHeading::NORTH,
        };
    }
}
