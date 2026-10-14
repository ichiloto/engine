<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Launch;

use InvalidArgumentException;

/** Installed usability only; it does not promise a successful native handshake. */
final readonly class RendererAvailability
{
  public function __construct(public bool $available, public ?string $reason = null)
  {
    if (($available && $reason !== null) || (!$available && ($reason === null || trim($reason) === ''))) {
      throw new InvalidArgumentException('Available renderers have no reason; unavailable renderers require a diagnostic.');
    }
  }
}
