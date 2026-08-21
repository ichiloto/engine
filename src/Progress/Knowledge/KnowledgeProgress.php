<?php

namespace Ichiloto\Engine\Progress\Knowledge;

/** Serializable player-earned knowledge state backed only by stable IDs. */
final class KnowledgeProgress
{
  /** @var array<string, array<string, mixed>> */
  private array $subjects = [];

  /** @param array<string, mixed> $data */
  public static function fromArray(array $data): self
  {
    $progress = new self();

    foreach (is_array($data['subjects'] ?? null) ? $data['subjects'] : [] as $subjectId => $entry) {
      if (! is_string($subjectId) || ! is_array($entry)) {
        continue;
      }

      try {
        $subjectId = KnowledgeIdentity::require($subjectId, 'subject progress id');
      } catch (\InvalidArgumentException) {
        continue;
      }

      $depth = KnowledgeDepth::tryFrom(intval($entry['depth'] ?? 0)) ?? KnowledgeDepth::UNKNOWN;
      $observations = [];
      foreach (is_array($entry['observations'] ?? null) ? $entry['observations'] : [] as $id => $observation) {
        if (! is_string($id) || ! is_array($observation)) {
          continue;
        }
        try {
          $id = KnowledgeIdentity::require($id, 'observation progress id');
        } catch (\InvalidArgumentException) {
          continue;
        }
        $observations[$id] = self::evidenceState($observation);
      }

      $outcomes = [];
      foreach (is_array($entry['outcomes'] ?? null) ? $entry['outcomes'] : [] as $id => $count) {
        if (! is_string($id)) {
          continue;
        }
        try {
          $id = KnowledgeIdentity::require($id, 'outcome progress id');
        } catch (\InvalidArgumentException) {
          continue;
        }
        $outcomes[$id] = min(999_999, max(1, intval($count)));
      }

      $reports = [];
      foreach (is_array($entry['reports'] ?? null) ? $entry['reports'] : [] as $id => $report) {
        if (! is_string($id) || ! is_array($report)) {
          continue;
        }
        try {
          $id = KnowledgeIdentity::require($id, 'report progress id');
        } catch (\InvalidArgumentException) {
          continue;
        }
        $status = KnowledgeReportStatus::tryFrom(strval($report['status'] ?? ''));
        if ($status === null) {
          continue;
        }
        $reports[$id] = [
          ...self::evidenceState($report),
          'status' => $status->value,
          'revision' => min(999, max(1, intval($report['revision'] ?? 1))),
          'supersededBy' => is_string($report['supersededBy'] ?? null) ? trim($report['supersededBy']) : null,
        ];
      }

      $progress->subjects[$subjectId] = [
        'depth' => $depth->value,
        'observations' => $observations,
        'outcomes' => $outcomes,
        'reports' => $reports,
      ];
    }

    return $progress;
  }

  /** @return array{subjects: array<string, array<string, mixed>>} */
  public function toArray(): array
  {
    return ['subjects' => $this->subjects];
  }

  public function depth(string $subjectId): KnowledgeDepth
  {
    return KnowledgeDepth::tryFrom(intval($this->subjects[$subjectId]['depth'] ?? 0)) ?? KnowledgeDepth::UNKNOWN;
  }

  public function isDiscovered(string $subjectId): bool
  {
    return $this->depth($subjectId) !== KnowledgeDepth::UNKNOWN;
  }

  /** @return array<string, mixed> */
  public function subjectState(string $subjectId): array
  {
    return $this->subjects[$subjectId] ?? [];
  }

  /** @return string[] */
  public function discoveredSubjectIds(): array
  {
    return array_keys(array_filter(
      $this->subjects,
      static fn(array $entry): bool => intval($entry['depth'] ?? 0) > 0,
    ));
  }

  public function setDepth(string $subjectId, KnowledgeDepth $depth): bool
  {
    $current = $this->depth($subjectId);
    if ($depth->value <= $current->value) {
      return false;
    }
    $this->ensureSubject($subjectId);
    $this->subjects[$subjectId]['depth'] = $depth->value;
    return true;
  }

  public function recordObservation(string $subjectId, string $observationId, string $source, float $confidence): bool
  {
    $this->ensureSubject($subjectId);
    $isFirst = ! isset($this->subjects[$subjectId]['observations'][$observationId]);
    $state = $this->subjects[$subjectId]['observations'][$observationId] ?? [
      'count' => 0,
      'confidence' => 0.0,
      'provenance' => [],
    ];
    $state['count'] = min(999, intval($state['count']) + 1);
    $state['confidence'] = max(floatval($state['confidence']), $confidence);
    $state['provenance'] = self::appendSource($state['provenance'], $source);
    $this->subjects[$subjectId]['observations'][$observationId] = $state;
    return $isFirst;
  }

  public function recordOutcome(string $subjectId, string $outcomeId): bool
  {
    $this->ensureSubject($subjectId);
    $isFirst = ! isset($this->subjects[$subjectId]['outcomes'][$outcomeId]);
    $this->subjects[$subjectId]['outcomes'][$outcomeId] = min(
      999_999,
      intval($this->subjects[$subjectId]['outcomes'][$outcomeId] ?? 0) + 1,
    );
    return $isFirst;
  }

  public function outcomeCount(string $subjectId, string $outcomeId): int
  {
    return intval($this->subjects[$subjectId]['outcomes'][$outcomeId] ?? 0);
  }

  /** @return array<string, mixed>|null */
  public function reportState(string $subjectId, string $reportId): ?array
  {
    $state = $this->subjects[$subjectId]['reports'][$reportId] ?? null;
    return is_array($state) ? $state : null;
  }

  public function writeReport(
    string $subjectId,
    string $reportId,
    KnowledgeReportStatus $status,
    string $source,
    float $confidence,
    ?string $supersededBy = null,
    bool $incrementRevision = false,
  ): bool
  {
    $this->ensureSubject($subjectId);
    $existing = $this->reportState($subjectId, $reportId);
    $isFirst = $existing === null;
    $revision = max(1, intval($existing['revision'] ?? 1) + ($incrementRevision ? 1 : 0));
    $this->subjects[$subjectId]['reports'][$reportId] = [
      'count' => min(999, intval($existing['count'] ?? 0) + 1),
      'confidence' => max(floatval($existing['confidence'] ?? 0.0), $confidence),
      'provenance' => self::appendSource((array) ($existing['provenance'] ?? []), $source),
      'status' => $status->value,
      'revision' => $revision,
      'supersededBy' => $supersededBy,
    ];
    return $isFirst;
  }

  private function ensureSubject(string $subjectId): void
  {
    $this->subjects[$subjectId] ??= [
      'depth' => KnowledgeDepth::UNKNOWN->value,
      'observations' => [],
      'outcomes' => [],
      'reports' => [],
    ];
  }

  /** @param array<string, mixed> $state @return array{count: int, confidence: float, provenance: string[]} */
  private static function evidenceState(array $state): array
  {
    $sources = [];
    foreach (is_array($state['provenance'] ?? null) ? $state['provenance'] : [] as $source) {
      $source = trim(strval($source));
      if ($source !== '' && ! in_array($source, $sources, true) && count($sources) < 32) {
        $sources[] = $source;
      }
    }
    return [
      'count' => min(999, max(1, intval($state['count'] ?? 1))),
      'confidence' => min(1.0, max(0.0, floatval($state['confidence'] ?? 0.0))),
      'provenance' => $sources,
    ];
  }

  /** @param string[] $sources @return string[] */
  private static function appendSource(array $sources, string $source): array
  {
    $source = trim($source);
    if ($source !== '' && ! in_array($source, $sources, true) && count($sources) < 32) {
      $sources[] = $source;
    }
    return array_values($sources);
  }
}
