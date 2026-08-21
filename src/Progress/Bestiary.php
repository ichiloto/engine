<?php

namespace Ichiloto\Engine\Progress;

use Ichiloto\Engine\Progress\Knowledge\KnowledgeCatalog;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgress;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;

/**
 * Backward-compatible enemy-record view over the generic knowledge spine.
 *
 * New runtime code should use KnowledgeProgressService. This adapter keeps
 * historical integrations and v1-v3 migration readable without making enemy
 * names authoritative persistence identities again.
 */
class Bestiary
{
  public function __construct(
    public readonly KnowledgeProgressService $knowledge = new KnowledgeProgressService(
      new KnowledgeCatalog(),
      new KnowledgeProgress(),
    ),
  )
  {
  }

  public function recordSeen(string $enemyName): bool
  {
    $subject = $this->legacySubject($enemyName);

    if ($subject === null) {
      return false;
    }

    $isFirst = $this->knowledge->discoverSubject($subject, 'legacy.bestiary');
    $this->knowledge->recordOutcome($subject, 'encountered');

    return $isFirst;
  }

  public function recordDefeated(string $enemyName): bool
  {
    $subject = $this->legacySubject($enemyName);

    if ($subject === null) {
      return false;
    }

    if (! $this->knowledge->progress->isDiscovered($subject)) {
      $this->recordSeen($enemyName);
    }

    return $this->knowledge->recordOutcome($subject, 'defeated');
  }

  public function hasSeen(string $enemyName): bool
  {
    $subject = $this->mappedSubject($enemyName);

    return $subject !== null && $this->knowledge->progress->isDiscovered($subject);
  }

  public function timesSeen(string $enemyName): int
  {
    $subject = $this->mappedSubject($enemyName);

    return $subject === null ? 0 : $this->knowledge->progress->outcomeCount($subject, 'encountered');
  }

  public function timesDefeated(string $enemyName): int
  {
    $subject = $this->mappedSubject($enemyName);

    return $subject === null ? 0 : $this->knowledge->progress->outcomeCount($subject, 'defeated');
  }

  public function discoveredCount(): int
  {
    return count($this->knowledge->progress->discoveredSubjectIds());
  }

  /** @return array{seen: array<string, int>, defeated: array<string, int>} */
  public function toArray(): array
  {
    $seen = [];
    $defeated = [];

    foreach ($this->knowledge->catalog->subjects() as $subject) {
      if (! $this->knowledge->progress->isDiscovered($subject->id)) {
        continue;
      }

      $seen[$subject->displayName] = max(1, $this->knowledge->progress->outcomeCount($subject->id, 'encountered'));
      $defeatCount = $this->knowledge->progress->outcomeCount($subject->id, 'defeated');
      if ($defeatCount > 0) {
        $defeated[$subject->displayName] = $defeatCount;
      }
    }

    return ['seen' => $seen, 'defeated' => $defeated];
  }

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data, ?KnowledgeProgressService $knowledge = null): self
  {
    $bestiary = new self($knowledge ?? new KnowledgeProgressService(new KnowledgeCatalog()));

    foreach ((array) ($data['seen'] ?? []) as $enemyName => $count) {
      if (! is_string($enemyName) || trim($enemyName) === '') {
        continue;
      }
      for ($index = 0, $total = max(1, intval($count)); $index < $total; $index++) {
        $subject = $bestiary->legacySubject($enemyName);
        if ($subject !== null) {
          $bestiary->knowledge->discoverSubject($subject, 'legacy.save');
          $bestiary->knowledge->recordOutcome($subject, 'encountered');
        }
      }
    }

    foreach ((array) ($data['defeated'] ?? []) as $enemyName => $count) {
      if (! is_string($enemyName) || trim($enemyName) === '') {
        continue;
      }
      for ($index = 0, $total = max(1, intval($count)); $index < $total; $index++) {
        $subject = $bestiary->legacySubject($enemyName);
        if ($subject !== null) {
          $bestiary->knowledge->recordOutcome($subject, 'defeated');
        }
      }
    }

    return $bestiary;
  }

  private function mappedSubject(string $enemyName): ?string
  {
    $enemyName = trim($enemyName);
    if ($enemyName === '') {
      return null;
    }

    return $this->knowledge->catalog->subjectForEnemy($enemyName)?->id;
  }

  private function legacySubject(string $enemyName): ?string
  {
    $enemyName = trim($enemyName);
    if ($enemyName === '') {
      return null;
    }

    return $this->knowledge->catalog->registerLegacyEnemy($enemyName)->id;
  }
}
