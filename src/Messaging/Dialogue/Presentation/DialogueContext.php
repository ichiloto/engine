<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use InvalidArgumentException;

/** Presentation identity accompanies a line without changing its gameplay speaker or text. */
final readonly class DialogueContext
{
    public const string DEFAULT_EMOTION = 'Neutral';
    public const array TEXT_FIELDS = ['emotion'];

    /** @param list<array{actorId: string, name: string, emotion: string}> $participants */
    public function __construct(public ?string $actorId = null, public string $emotion = self::DEFAULT_EMOTION,
        public ?string $skitId = null, public string $skitTitle = '', public string $location = '',
        public array $participants = []) {}

    /** Authored text retains its display speaker; the catalog owns identity and expression bindings. */
    public static function getFromText(array $data): self
    {
        $emotion = array_key_exists('emotion', $data) ? $data['emotion'] : self::DEFAULT_EMOTION;
        if (!is_string($emotion) || trim($emotion) === '') {
            throw new InvalidArgumentException('Text emotion must be a non-empty catalog expression name.');
        }
        return new self(emotion: $emotion);
    }
}
