<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;

/** Transient notices are a headline and at most two short body lines, not a reading surface. */
final class NotificationContentPolicy
{
    public const int COLUMNS = 36;

    public static function fitsToast(NotificationInterface $notice): bool
    {
        return count(TerminalText::wrapParagraphsToWidth($notice->getContentTitle(), self::COLUMNS)) <= 1
            && count(TerminalText::wrapParagraphsToWidth($notice->getContentText(), self::COLUMNS)) <= 2;
    }
}
