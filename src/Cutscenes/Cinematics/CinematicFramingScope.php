<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

/** Identity and restoration boundary for one explicit, renderer-neutral framing phase. */
final readonly class CinematicFramingScope
{
    public function __construct(
        public array $references,
        public string $lanePath,
        public string $mapId,
        public ?int $stageGeneration,
        public ?self $parent,
    ) {}
}
