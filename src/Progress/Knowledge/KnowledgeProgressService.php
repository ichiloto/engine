<?php

namespace Ichiloto\Engine\Progress\Knowledge;

use Ichiloto\Engine\Entities\Enemies\Enemy;
use InvalidArgumentException;

/** One validated operation boundary for battle and noncombat knowledge. */
final class KnowledgeProgressService
{
  public const array OPERATIONS = [
    'discover', 'observe', 'unlock_report', 'amend_report',
    'record_outcome', 'withdraw_report', 'supersede_report',
  ];

  public function __construct(
    public readonly KnowledgeCatalog $catalog,
    public readonly KnowledgeProgress $progress = new KnowledgeProgress(),
  )
  {
  }

  public function discoverSubject(string $subjectId, string $source = 'unknown'): bool
  {
    $subject = $this->requireSubject($subjectId);
    $changed = $this->progress->setDepth($subject->id, KnowledgeDepth::DISCOVERED);
    if (trim($source) !== '') {
      $this->progress->recordObservation($subject->id, 'discovery', $source, 1.0);
    }
    return $changed;
  }

  public function discoverEnemy(Enemy $enemy, string $source = 'battle.encounter'): bool
  {
    $subject = $this->catalog->subjectForEnemy($enemy);
    if (! $subject instanceof KnowledgeSubject) {
      return false;
    }
    $changed = $this->discoverSubject($subject->id, $source);
    $this->progress->recordOutcome($subject->id, 'encountered');
    return $changed;
  }

  public function recordEnemyOutcome(Enemy $enemy, string $outcomeId, string $source = 'battle.result'): bool
  {
    $subject = $this->catalog->subjectForEnemy($enemy);
    if (! $subject instanceof KnowledgeSubject) {
      return false;
    }
    $this->discoverSubject($subject->id, $source);
    return $this->recordOutcome($subject->id, $outcomeId);
  }

  public function recordObservation(
    string $subjectId,
    string $observationId,
    string $source,
    float $confidence = 1.0,
  ): bool
  {
    $subject = $this->requireSubject($subjectId);
    $observationId = KnowledgeIdentity::require($observationId, 'observation id');
    if ($subject->observationIds !== [] && ! in_array($observationId, $subject->observationIds, true)) {
      throw new InvalidArgumentException("Observation {$observationId} is not authored for subject {$subjectId}.");
    }
    $confidence = $this->requireConfidence($confidence);
    $this->progress->setDepth($subjectId, KnowledgeDepth::OBSERVED);
    return $this->progress->recordObservation($subjectId, $observationId, $this->requireSource($source), $confidence);
  }

  public function unlockReport(string $subjectId, string $reportId, string $source, float $confidence = 1.0): bool
  {
    $report = $this->requireReport($subjectId, $reportId);
    $this->progress->setDepth($subjectId, KnowledgeDepth::SUPPORTED);
    return $this->progress->writeReport(
      $subjectId,
      $report->id,
      KnowledgeReportStatus::ACTIVE,
      $this->requireSource($source),
      $this->requireConfidence($confidence),
    );
  }

  public function amendReport(string $subjectId, string $reportId, string $source, float $confidence = 1.0): bool
  {
    $report = $this->requireReport($subjectId, $reportId);
    if ($this->progress->reportState($subjectId, $reportId) === null) {
      throw new InvalidArgumentException("Knowledge report {$reportId} cannot be amended before it is unlocked.");
    }
    return $this->progress->writeReport(
      $subjectId,
      $report->id,
      KnowledgeReportStatus::AMENDED,
      $this->requireSource($source),
      $this->requireConfidence($confidence),
      incrementRevision: true,
    );
  }

  public function recordOutcome(string $subjectId, string $outcomeId): bool
  {
    $this->requireSubject($subjectId);
    $outcomeId = KnowledgeIdentity::require($outcomeId, 'outcome id');
    $this->progress->setDepth($subjectId, KnowledgeDepth::DISCOVERED);
    return $this->progress->recordOutcome($subjectId, $outcomeId);
  }

  public function withdrawReport(string $subjectId, string $reportId, string $source): bool
  {
    $this->requireReport($subjectId, $reportId);
    if ($this->progress->reportState($subjectId, $reportId) === null) {
      throw new InvalidArgumentException("Knowledge report {$reportId} cannot be withdrawn before it is unlocked.");
    }
    $this->progress->setDepth($subjectId, KnowledgeDepth::CONTESTED);
    $this->progress->writeReport(
      $subjectId,
      $reportId,
      KnowledgeReportStatus::WITHDRAWN,
      $this->requireSource($source),
      1.0,
    );
    return true;
  }

  public function supersedeReport(string $subjectId, string $reportId, string $replacementId, string $source): bool
  {
    $this->requireReport($subjectId, $reportId);
    $this->requireReport($subjectId, $replacementId);
    if ($reportId === $replacementId || $this->progress->reportState($subjectId, $reportId) === null) {
      throw new InvalidArgumentException('A report must be unlocked and superseded by a different report.');
    }
    $source = $this->requireSource($source);
    $this->progress->setDepth($subjectId, KnowledgeDepth::CONTESTED);
    $this->progress->writeReport($subjectId, $reportId, KnowledgeReportStatus::SUPERSEDED, $source, 1.0, $replacementId);
    return $this->progress->writeReport($subjectId, $replacementId, KnowledgeReportStatus::ACTIVE, $source, 1.0);
  }

  /** @param array<string, mixed> $command */
  public function apply(string $operation, array $command): bool
  {
    if (! in_array($operation, self::OPERATIONS, true)) {
      throw new InvalidArgumentException("Unknown knowledge operation: {$operation}.");
    }
    $subject = strval($command['subject'] ?? '');
    $source = strval($command['source'] ?? 'story.event');
    $confidence = is_numeric($command['confidence'] ?? null) ? floatval($command['confidence']) : 1.0;

    return match ($operation) {
      'discover' => $this->discoverSubject($subject, $source),
      'observe' => $this->recordObservation($subject, strval($command['observation'] ?? ''), $source, $confidence),
      'unlock_report' => $this->unlockReport($subject, strval($command['report'] ?? ''), $source, $confidence),
      'amend_report' => $this->amendReport($subject, strval($command['report'] ?? ''), $source, $confidence),
      'record_outcome' => $this->recordOutcome($subject, strval($command['outcome'] ?? '')),
      'withdraw_report' => $this->withdrawReport($subject, strval($command['report'] ?? ''), $source),
      'supersede_report' => $this->supersedeReport($subject, strval($command['report'] ?? ''), strval($command['replacement'] ?? ''), $source),
    };
  }

  /** @return KnowledgeSubject[] */
  public function discoveredSubjects(): array
  {
    return array_values(array_filter(
      $this->catalog->subjects(),
      fn(KnowledgeSubject $subject): bool => $this->progress->isDiscovered($subject->id),
    ));
  }

  private function requireSubject(string $subjectId): KnowledgeSubject
  {
    $subjectId = KnowledgeIdentity::require($subjectId, 'subject id');
    return $this->catalog->subject($subjectId)
      ?? throw new InvalidArgumentException("Unknown knowledge subject: {$subjectId}.");
  }

  private function requireReport(string $subjectId, string $reportId): KnowledgeReport
  {
    $this->requireSubject($subjectId);
    $reportId = KnowledgeIdentity::require($reportId, 'report id');
    $report = $this->catalog->report($reportId);
    if (! $report instanceof KnowledgeReport || $report->subjectId !== $subjectId) {
      throw new InvalidArgumentException("Unknown report {$reportId} for knowledge subject {$subjectId}.");
    }
    return $report;
  }

  private function requireSource(string $source): string
  {
    return KnowledgeIdentity::require($source, 'knowledge source');
  }

  private function requireConfidence(float $confidence): float
  {
    if (! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
      throw new InvalidArgumentException('Knowledge confidence must be between 0 and 1.');
    }
    return $confidence;
  }
}
