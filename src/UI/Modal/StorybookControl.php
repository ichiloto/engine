<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

/** Reader button identity remains independent of its displayed label. */
enum StorybookControl: string
{
    case PREVIOUS = 'Previous';
    case NEXT = 'Next';
    case CLOSE = 'Close';
}
