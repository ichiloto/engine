<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

use InvalidArgumentException;

/** Diagnostic inspection can retain all errors; runtime requires a complete valid source. */
final readonly class MapEventResolution
{
    /** @param list<array<string,mixed>> $events @param list<MapValidationIssue> $issues */
    public function __construct(public array $events, public array $issues) {}

    public function requireEvents(): array
    {
        if ($this->issues !== []) { throw new InvalidArgumentException($this->issues[0]->message); }
        return $this->events;
    }
}
