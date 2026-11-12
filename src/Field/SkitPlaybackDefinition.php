<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Field\Enumerations\SkitTrigger;
use InvalidArgumentException;

/** Shared authored playback fields for runtime and source-preserving editors. */
final readonly class SkitPlaybackDefinition
{
    /** @param array<string, string> $sounds */
    private function __construct(public SkitTrigger $trigger, public array $sounds) {}

    /** @param array<array-key, mixed> $skit */
    public static function fromArray(array $skit, string $context = 'Skit'): self
    {
        $trigger = $skit['trigger'] ?? SkitTrigger::PROMPT->value;
        $trigger = is_string($trigger) ? SkitTrigger::tryFrom($trigger) : null;
        if ($trigger === null) {
            throw new InvalidArgumentException("{$context} trigger must be prompt or script.");
        }
        return new self($trigger, SkitPlaybackPlan::readSounds($skit['sounds'] ?? [], $context));
    }
}
