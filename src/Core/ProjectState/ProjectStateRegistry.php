<?php

namespace Ichiloto\Engine\Core\ProjectState;

use Closure;
use Throwable;

/** One Game owns all registered project state; restoration is one array swap. */
final class ProjectStateRegistry
{
    /** @var array<string, array{participant: ProjectStateParticipantInterface, version: int}> */
    private array $participants = [];
    /** @var array<string, array{version: int, data: array<array-key, mixed>}> */
    private array $snapshot = [];
    private bool $preparing = false;
    private bool $restoring = false;
    private bool $started = false;

    /** @var list<string> Unknown data is retained but cannot be used or changed without its policy. */
    public array $diagnostics {
        get {
            $diagnostics = [];
            foreach (array_diff_key($this->snapshot, $this->participants) as $namespace => $section) {
                $diagnostics[] = sprintf('Project-state namespace "%s" (version %d) has no registered participant; retained unchanged, not hydrated as active project progress.', $namespace, $section['version']);
            }
            return $diagnostics;
        }
    }

    /** Register from project bootstrap before restoring or changing campaign state. */
    public function registerParticipant(ProjectStateParticipantInterface $participant): void
    {
        $this->assertMutable();
        if ($this->started) {
            throw new ProjectStateException('Register project-state participants before the first campaign restoration or mutation.');
        }
        $namespace = $participant->namespace;
        ProjectStateData::validateNamespace($namespace);
        if ($participant->version < 1 || isset($this->participants[$namespace])
            || count($this->participants) >= ProjectStateData::MAX_NAMESPACES) {
            throw new ProjectStateException(sprintf('Project-state participant "%s" has an invalid version, duplicate namespace or exceeds the namespace limit.', $namespace));
        }
        $this->participants[$namespace] = ['participant' => $participant, 'version' => $participant->version];
    }

    /**
     * Read-only migration/validation. No live state or diagnostics change on failure or success.
     *
     * @param array<string, array{version: int, data: array<array-key, mixed>}> $snapshot
     * @return array<string, array{version: int, data: array<array-key, mixed>}>
     */
    public function prepareSnapshot(array $snapshot): array
    {
        $this->assertMutable();
        $this->preparing = true;
        try {
            $prepared = ProjectStateData::copySnapshot($snapshot);
            foreach ($this->participants as $namespace => $registration) {
                $participant = $registration['participant'];
                $version = $registration['version'];
                if ($participant->namespace !== $namespace || $participant->version !== $version) {
                    throw new ProjectStateException(sprintf('Project-state participant "%s" changed its registered identity or version.', $namespace));
                }
                $section = $prepared[$namespace] ?? null;
                $data = $section['data'] ?? [];
                try {
                    if ($section !== null && $section['version'] > $version) {
                        throw new ProjectStateException(sprintf('Saved version %d is newer than supported version %d.', $section['version'], $version));
                    }
                    if ($section !== null && $section['version'] !== $version) {
                        $data = $participant->migrateState($data, $section['version']);
                    }
                    $data = ProjectStateData::copySnapshot([$namespace => ['version' => $version, 'data' => $data]])[$namespace]['data'];
                    $participant->validateState($data);
                    if ($participant->namespace !== $namespace || $participant->version !== $version) {
                        throw new ProjectStateException('A participant must keep its registered identity and version stable.');
                    }
                } catch (Throwable $failure) {
                    throw new ProjectStateException(sprintf('Cannot prepare project-state namespace "%s": %s', $namespace, $failure->getMessage()), previous: $failure);
                }
                $prepared[$namespace] = ['version' => $version, 'data' => $data];
            }
            return ProjectStateData::copySnapshot($prepared);
        } finally {
            $this->preparing = false;
        }
    }

    /** @param array<string, array{version: int, data: array<array-key, mixed>}> $snapshot */
    public function restoreSnapshot(array $snapshot): void
    {
        if ($this->restoring) {
            throw new ProjectStateException('A campaign restoration is already in progress.');
        }
        $prepared = $this->prepareSnapshot($snapshot);
        $this->snapshot = $prepared;
        $this->started = true;
    }

    /** Restore before field consumers run; roll back project state if scene configuration fails. */
    public function runWithSnapshot(array $snapshot, Closure $configure): void
    {
        if ($this->restoring) {
            throw new ProjectStateException('Nested campaign restoration is unsupported.');
        }
        $previous = $this->snapshot;
        $started = $this->started;
        $this->restoreSnapshot($snapshot);
        $this->restoring = true;
        try {
            $configure();
        } catch (Throwable $failure) {
            $this->snapshot = $previous;
            $this->started = $started;
            throw $failure;
        } finally {
            $this->restoring = false;
        }
    }

    /** @return array<array-key, mixed> A detached value, not a mutable reference. */
    public function getState(string $namespace): array
    {
        $this->assertMutable();
        $this->requireParticipant($namespace);
        return $this->snapshot[$namespace]['data'] ?? [];
    }

    /**
     * Validate the complete proposed update before committing any namespace.
     * @param array<string, array<array-key, mixed>> $states
     */
    public function replaceStates(array $states): void
    {
        $this->assertMutable();
        $candidate = $this->snapshot;
        foreach ($states as $namespace => $data) {
            if (!is_string($namespace)) {
                throw new ProjectStateException('Project-state update keys must be registered namespaces.');
            }
            $registration = $this->requireParticipant($namespace);
            $candidate[$namespace] = ['version' => $registration['version'], 'data' => $data];
        }
        $this->snapshot = $this->prepareSnapshot($candidate);
        $this->started = true;
    }

    /** @return array<string, array{version: int, data: array<array-key, mixed>}> */
    public function captureSnapshot(): array
    {
        return $this->prepareSnapshot($this->snapshot);
    }

    /** @return array{participant: ProjectStateParticipantInterface, version: int} */
    private function requireParticipant(string $namespace): array
    {
        return $this->participants[$namespace]
            ?? throw new ProjectStateException(sprintf('No project-state participant is registered for "%s".', $namespace));
    }

    private function assertMutable(): void
    {
        if ($this->preparing) {
            throw new ProjectStateException('Project-state migration and validation callbacks must not mutate or re-enter the registry.');
        }
    }
}
