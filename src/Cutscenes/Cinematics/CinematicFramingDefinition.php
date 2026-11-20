<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Cutscenes\Cinematics;

use InvalidArgumentException;

/** Plain, renderer-neutral bindings for subjects that a cinematic phase must keep readable. */
final readonly class CinematicFramingDefinition
{
    public const array SUBJECT_KINDS = [CinematicFramingSubjectKind::PLAYER->value,
        CinematicFramingSubjectKind::NPC->value, CinematicFramingSubjectKind::STAGED_ACTOR->value];
    public const array FIELDS = ['subjects'];
    public const array REFERENCE_FIELDS = ['kind', 'id'];

    /** @param array<string, mixed> $definition */
    public function __construct(private array $definition = [])
    {
        if (array_diff(array_keys($definition), self::FIELDS) !== []
            || !is_array($definition['subjects'] ?? [])) {
            throw new InvalidArgumentException('Cinematic framing accepts named subject bindings.');
        }
        foreach ($definition['subjects'] ?? [] as $key => $reference) {
            if (!is_string($key) || trim($key) === '' || trim($key) !== $key || !is_array($reference)) {
                throw new InvalidArgumentException('Framing bindings need a non-empty stable key and a subject reference.');
            }
            self::validateReference($reference);
        }
    }

    /**
     * A reusable common event uses direct references; a cinematic may also select its own bound keys.
     * Resolution of actual field objects happens at execution, before acquiring or changing a phase.
     *
     * @param list<string|array{kind: string, id?: string}> $requirements
     * @return list<array{kind: string, id?: string}>
     */
    public function getSubjectReferences(array $requirements): array
    {
        self::validateRequirements($requirements);
        $references = $identities = [];
        foreach ($requirements as $requirement) {
            $reference = is_string($requirement)
                ? ($this->definition['subjects'][$requirement]
                    ?? throw new InvalidArgumentException('The framing subject key is not bound in this cinematic.'))
                : $requirement;
            if (!is_array($reference)) {
                throw new InvalidArgumentException('Choose a bound framing key or a direct field subject.');
            }
            self::validateReference($reference);
            $identity = $reference['kind'] . ':' . ($reference['id'] ?? '');
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('A framing phase selects each field identity once.');
            }
            $identities[$identity] = true;
            $references[] = $reference;
        }
        return $references;
    }

    /** Shape validation also applies to reusable commands before a cinematic binding is available. */
    public static function validateRequirements(array $requirements): void
    {
        if (!array_is_list($requirements) || $requirements === []) {
            throw new InvalidArgumentException('A framing phase must select at least one required subject.');
        }
        foreach ($requirements as $requirement) {
            if (is_string($requirement)) {
                if (trim($requirement) === '' || trim($requirement) !== $requirement) {
                    throw new InvalidArgumentException('A framing subject key must be non-empty and stable.');
                }
            } elseif (is_array($requirement)) { self::validateReference($requirement); }
            else { throw new InvalidArgumentException('Choose a bound framing key or a direct field subject.'); }
        }
    }

    /** Framing names field identities, never authored camera geometry or speaker/facing behaviour. */
    private static function validateReference(array $reference): void
    {
        if (array_diff(array_keys($reference), self::REFERENCE_FIELDS) !== []
            || !in_array($reference['kind'] ?? null, self::SUBJECT_KINDS, true)
            || (array_key_exists('id', $reference) && (!is_string($reference['id']) || trim($reference['id']) === ''
                || trim($reference['id']) !== $reference['id']))
            || ($reference['kind'] === 'player' && array_key_exists('id', $reference))) {
            throw new InvalidArgumentException('Framing selects the player or a stable NPC/staged actor id.');
        }
        try { CinematicSubjectResolver::validateReference($reference); }
        catch (InvalidArgumentException $error) {
            throw new InvalidArgumentException('Framing subject: ' . $error->getMessage(), previous: $error);
        }
    }
}
