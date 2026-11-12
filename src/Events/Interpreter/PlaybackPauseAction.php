<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

enum PlaybackPauseAction: string
{
    case SKIP = 'Skip';
    case EXIT = 'Exit';
    case CANCEL = 'Cancel';
    case TITLE = 'To Title';
    case QUIT = 'Quit Game';
}
