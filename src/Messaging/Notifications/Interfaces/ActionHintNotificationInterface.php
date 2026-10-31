<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Interfaces;

use Ichiloto\Engine\IO\ActionHint;

interface ActionHintNotificationInterface extends GraphicalNotificationInterface
{
  /** @return list<ActionHint> */
  public function getActionHints(): array;
  public function getPromptText(): string;
}
