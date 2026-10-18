<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field\Validation;

/** A player-visible map defect. Authoring tools may save drafts containing these errors. */
final readonly class MapValidationIssue
{
    /** @param list<array{int,int}> $cells @param list<int|string> $path */
    public function __construct(
        public string $mapId,
        public string $rule,
        public string $message,
        public ?string $marker = null,
        public array $cells = [],
        public array $path = [],
        public string $hint = '',
    ) {}
}
