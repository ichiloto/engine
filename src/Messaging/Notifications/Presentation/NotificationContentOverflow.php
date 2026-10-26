<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Presentation;

/** Route unrepresentable toast content to an acknowledged alert without truncation. */
final class NotificationContentOverflow extends \RuntimeException {}
