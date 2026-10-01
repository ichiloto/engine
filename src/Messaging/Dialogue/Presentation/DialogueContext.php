<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

/** Presentation identity accompanies a line without changing its gameplay speaker or text. */
final readonly class DialogueContext
{
    /** @param list<array{actorId: string, name: string, emotion: string}> $participants */
    public function __construct(public ?string $actorId = null, public string $emotion = 'Neutral',
        public ?string $skitId = null, public string $skitTitle = '', public string $location = '',
        public array $participants = []) {}
}
