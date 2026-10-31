<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationChannel;
use Ichiloto\Engine\Messaging\Notifications\Enumerations\NotificationDuration;
use Ichiloto\Engine\Messaging\Notifications\Interfaces\ActionHintNotificationInterface;
use InvalidArgumentException;
use Ichiloto\Engine\UI\Presentation\TerminalControlText;

/** Semantic controls use their current profile's glyphs and readable labels. */
final class ActionHintNotification extends Notification implements ActionHintNotificationInterface
{
  /** @param list<ActionHint> $hints */
  public function __construct(Game $game, string $title, string $message, private readonly array $hints)
  {
    if (!array_is_list($hints) || !array_all($hints, static fn(mixed $hint): bool => $hint instanceof ActionHint)) {
      throw new InvalidArgumentException('A hint notification needs typed action hints.');
    }
    parent::__construct($game, NotificationChannel::INFO, $title, $message, NotificationDuration::SHORT);
  }

  public function getActionHints(): array { return $this->hints; }
  public function getPromptText(): string { return parent::getContentText(); }
  public function getContentText(): string
  {
    $hints = TerminalControlText::formatHints($this->hints);
    return implode("\n", array_filter([$this->getPromptText(), $hints], static fn(string $text): bool => $text !== ''));
  }
}
