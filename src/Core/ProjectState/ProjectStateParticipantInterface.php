<?php

namespace Ichiloto\Engine\Core\ProjectState;

/** Project-owned schema policy, not a second mutable state store. */
interface ProjectStateParticipantInterface
{
    public string $namespace { get; }
    public int $version { get; }

    /**
     * Purely migrate saved data to the current version; throw for unsupported versions.
     * This is never called for an absent section (which means no recorded progress).
     *
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function migrateState(array $data, int $fromVersion): array;

    /**
     * Purely validate current schema and project references. Must accept [] as no progress.
     * No grants, IO, runtime mutation or artwork-byte identity in either callback.
     *
     * @param array<array-key, mixed> $data
     */
    public function validateState(array $data): void;
}
