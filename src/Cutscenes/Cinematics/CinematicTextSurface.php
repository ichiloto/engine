<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/** The field presentation where a timed cinematic text cue is visible. */
enum CinematicTextSurface: string
{
    public const string FIELD = 'presentation';

    case ALL = 'all';
    case TERMINAL = 'terminal';
    case GRAPHICAL = 'graphical';

    public static function getFromCommand(array $command): self
    {
        $value = array_key_exists(self::FIELD, $command) ? $command[self::FIELD] : self::ALL->value;
        return (is_string($value) ? self::tryFrom($value) : null)
            ?? throw new InvalidArgumentException('Timed text presentation must be all, terminal, or graphical.');
    }

    public function isVisible(bool $graphicalField): bool
    {
        return match ($this) {
            self::ALL => true,
            self::TERMINAL => !$graphicalField,
            self::GRAPHICAL => $graphicalField,
        };
    }
}
