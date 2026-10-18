<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Separate reading, holder selection and character equipment contexts. */
enum SummonsMenuView
{
    case SUMMONS;
    case ACTIONS;
    case INFO;
    case HOLDERS;
    case CHARACTER;
}
