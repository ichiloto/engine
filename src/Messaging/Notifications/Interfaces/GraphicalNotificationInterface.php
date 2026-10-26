<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Notifications\Interfaces;

/** Optional presentation data; legacy custom notifications retain their terminal renderer. */
interface GraphicalNotificationInterface extends NotificationInterface
{
  public function getPresentationId(): string;
  public function getPresentationRole(): string;
  public function getPresentationOpacity(): float;
  public function delayPresentation(float $seconds): void;
}
