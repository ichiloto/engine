<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\NotificationInterface;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\ActionHintNotificationInterface;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;

/** Transient notices are a headline and at most two short body lines, not a reading surface. */
final class NotificationContentPolicy
{
    public const int COLUMNS = 36;

    public static function fitsToast(NotificationInterface $notice): bool
    {
        $body = $notice instanceof ActionHintNotificationInterface ? $notice->getPromptText() : $notice->getContentText();
        $hints = $notice instanceof ActionHintNotificationInterface
            ? TerminalControlText::formatHints($notice->getActionHints()) : '';
        return count(TerminalText::wrapParagraphsToWidth($notice->getContentTitle(), self::COLUMNS)) <= 1
            && count(TerminalText::wrapParagraphsToWidth($body, self::COLUMNS)) <= 2
            && ($hints === '' || count(TerminalText::wrapParagraphsToWidth($hints, self::COLUMNS)) <= 1);
    }

    /** One headline, two prose rows and one independently measured semantic control row. */
    public static function getContentRowLimit(NotificationInterface $notice): int
    {
        return $notice instanceof ActionHintNotificationInterface && $notice->getActionHints() !== [] ? 4 : 3;
    }
}
