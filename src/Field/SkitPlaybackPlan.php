<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use InvalidArgumentException;

/** Current speaker, voice and place bindings prepared once for either playback host. */
final readonly class SkitPlaybackPlan
{
    /**
     * @param list<array{speaker: string, text: string, voicePath: ?string, presentation: DialogueContext}> $beats
     * @param array<string, string> $sounds Opening and closing SFX references, resolved by AudioManager.
     */
    public function __construct(
        public string $id,
        public array $beats,
        public float $speed,
        public float $musicDuck,
        public array $sounds = [],
    ) {
    }

    /** @return array<string, string> */
    public static function readSounds(mixed $sounds, string $context): array
    {
        if (!is_array($sounds) || array_diff(array_keys($sounds), ['open', 'close']) !== []) {
            throw new InvalidArgumentException("{$context} sounds accepts named open and close SFX references.");
        }
        foreach ($sounds as $cue => $reference) {
            if (!is_string($reference) || trim($reference) === '' || str_contains($reference, "\0")) {
                throw new InvalidArgumentException("{$context} sounds.{$cue} requires a non-empty SFX reference.");
            }
        }
        return $sounds;
    }
}
