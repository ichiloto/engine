<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Enumerations;

enum SkitTrigger: string
{
    case PROMPT = 'prompt';
    case SCRIPT = 'script';
}
