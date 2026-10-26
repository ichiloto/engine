<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

/** Read-only availability, not an announcement or a second skit queue. */
final readonly class SkitPrompt
{
  public function __construct(public string $id, public string $title) {}
}
