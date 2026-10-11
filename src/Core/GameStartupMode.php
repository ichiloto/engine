<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Core;

use InvalidArgumentException;

/** An explicit launch intent, never persisted into project settings or saves. */
enum GameStartupMode: string
{
    case TITLE = 'title';
    case NEW_GAME = 'new-game';

    public const string ENVIRONMENT_VARIABLE = 'ICHILOTO_START_MODE';

    public static function readEnvironment(): ?self
    {
        $value = getenv(self::ENVIRONMENT_VARIABLE);
        if ($value === false || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)))
            ?? throw new InvalidArgumentException('Unknown game start mode. Use title or new-game.');
    }
}
