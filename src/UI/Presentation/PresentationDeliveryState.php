<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** A producer observes delivery independently of a toast or acknowledged surface. */
enum PresentationDeliveryState: string
{
  case QUEUED = 'queued';
  case PRESENTING = 'presenting';
  case COMPLETED = 'completed';
  case CANCELLED = 'cancelled';
}
