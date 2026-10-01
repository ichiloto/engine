<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

/** Preserve unrepresentable content in the queue instead of truncating a notification. */
final class NotificationContentOverflow extends \RuntimeException {}
