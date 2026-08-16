<?php

namespace Ichiloto\Engine\Progress\Knowledge;

use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Util\Interfaces\ConfigInterface;
use InvalidArgumentException;
use Throwable;

/** Validated project-owned subject, mapping, and report definitions. */
final class KnowledgeCatalog implements ConfigInterface
{
  /** @var array<string, true> */
  private array $recordTypes = [];
  /** @var array<string, KnowledgeSubject> */
  private array $subjects = [];
  /** @var array<string, KnowledgeReport> */
  private array $reports = [];
  /** @var array<string, string> Normalized runtime enemy name to subject ID. */
  private array $enemyMappings = [];

  /** @param array<string, mixed> $data */
  public function __construct(array $data = [])
  {
    $this->hydrate($data);
  }

  public static function fromProject(): self
  {
    try {
      $data = asset('Data/knowledge.php', true);
    } catch (Throwable) {
      $data = [];
    }

    return new self(is_array($data) ? $data : []);
  }

  public static function empty(): self
  {
    return new self();
  }

  /** @return KnowledgeSubject[] */
  public function subjects(): array
  {
    $subjects = array_values($this->subjects);
    usort($subjects, static fn(KnowledgeSubject $a, KnowledgeSubject $b): int => [$a->displayOrder, $a->displayName] <=> [$b->displayOrder, $b->displayName]);

    return $subjects;
  }

  public function subject(string $id): ?KnowledgeSubject
  {
    return $this->subjects[trim($id)] ?? null;
  }

  public function report(string $id): ?KnowledgeReport
  {
    return $this->reports[trim($id)] ?? null;
  }

  /** @return KnowledgeReport[] */
  public function reportsFor(string $subjectId): array
  {
    $reports = array_values(array_filter(
      $this->reports,
      static fn(KnowledgeReport $report): bool => $report->subjectId === $subjectId,
    ));
    usort($reports, static fn(KnowledgeReport $a, KnowledgeReport $b): int => [$a->displayOrder, $a->title] <=> [$b->displayOrder, $b->title]);

    return $reports;
  }

  public function subjectForEnemy(Enemy|string $enemy): ?KnowledgeSubject
  {
    $subjectId = $enemy instanceof Enemy ? trim($enemy->knowledgeSubjectId ?? '') : '';
    $enemyName = $enemy instanceof Enemy ? $enemy->name : $enemy;
    $subjectId = $subjectId !== '' ? $subjectId : ($this->enemyMappings[strtolower(trim($enemyName))] ?? '');

    return $subjectId !== '' ? $this->subject($subjectId) : null;
  }

  /** Compatibility seam for historical name-keyed Bestiary callers only. */
  public function registerLegacyEnemy(string $enemyName): KnowledgeSubject
  {
    $enemyName = trim($enemyName);

    if ($enemyName === '') {
      throw new InvalidArgumentException('Legacy enemy name cannot be empty.');
    }

    $mapped = $this->subjectForEnemy($enemyName);
    if ($mapped instanceof KnowledgeSubject) {
      return $mapped;
    }

    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $enemyName) ?? '', '-')) ?: 'enemy';
    $id = 'legacy.enemy.' . $base;
    for ($suffix = 2; isset($this->subjects[$id]); $suffix++) {
      $id = 'legacy.enemy.' . $base . '-' . $suffix;
    }

    $this->recordTypes['legacy-enemy'] = true;
    $subject = new KnowledgeSubject($id, 'legacy-enemy', $enemyName, $enemyName);
    $this->subjects[$id] = $subject;
    $this->enemyMappings[strtolower($enemyName)] = $id;

    return $subject;
  }

  public function get(string $path, mixed $default = null): mixed
  {
    return $this->subject($path) ?? $this->report($path) ?? $default;
  }

  public function set(string $path, mixed $value): void
  {
    throw new InvalidArgumentException('Knowledge catalog definitions are project-authored and immutable at runtime.');
  }

  public function has(string $path): bool
  {
    return isset($this->subjects[$path]) || isset($this->reports[$path]);
  }

  public function persist(): void
  {
  }

  /** @param array<string, mixed> $data */
  private function hydrate(array $data): void
  {
    $this->assertNoPrivateTruth($data);

    foreach ((array) ($data['recordTypes'] ?? []) as $type) {
      $this->recordTypes[KnowledgeIdentity::require(strval($type), 'record type')] = true;
    }

    foreach ((array) ($data['subjects'] ?? []) as $entry) {
      if (! is_array($entry)) {
        throw new InvalidArgumentException('Knowledge subjects must be arrays.');
      }

      $id = KnowledgeIdentity::require(strval($entry['id'] ?? ''), 'subject id');
      $type = KnowledgeIdentity::require(strval($entry['recordType'] ?? ''), 'record type');
      if (! isset($this->recordTypes[$type]) || isset($this->subjects[$id])) {
        throw new InvalidArgumentException("Knowledge subject {$id} has a duplicate ID or unknown record type.");
      }

      $relationships = [];
      foreach ((array) ($entry['relationships'] ?? []) as $relationship) {
        if (! is_array($relationship)) {
          throw new InvalidArgumentException("Knowledge subject {$id} has a malformed relationship.");
        }
        $relationships[] = [
          'type' => KnowledgeIdentity::require(strval($relationship['type'] ?? ''), 'relationship type'),
          'subject' => KnowledgeIdentity::require(strval($relationship['subject'] ?? ''), 'related subject id'),
        ];
      }

      $this->subjects[$id] = new KnowledgeSubject(
        $id,
        $type,
        $this->requiredText($entry['displayName'] ?? null, "subject {$id} display name"),
        $this->requiredText($entry['quickCard'] ?? null, "subject {$id} quick card"),
        $this->optionalText($entry['deepCard'] ?? null),
        $this->optionalText($entry['family'] ?? null),
        $this->optionalText($entry['species'] ?? null),
        $this->stableIds($entry['tags'] ?? [], 'tag'),
        $this->stableIds($entry['habitats'] ?? [], 'habitat'),
        $relationships,
        $this->stableIds($entry['observations'] ?? [], 'observation'),
        intval($entry['displayOrder'] ?? 0),
        (bool) ($entry['hidden'] ?? false),
      );
    }

    foreach ($this->subjects as $subject) {
      foreach ($subject->relationships as $relationship) {
        if (! isset($this->subjects[$relationship['subject']])) {
          throw new InvalidArgumentException("Knowledge subject {$subject->id} references an unknown related subject.");
        }
      }
    }

    foreach ((array) ($data['enemyMappings'] ?? []) as $enemyName => $subjectId) {
      $subjectId = KnowledgeIdentity::require(strval($subjectId), 'mapped subject id');
      if (! is_string($enemyName) || trim($enemyName) === '' || ! isset($this->subjects[$subjectId])) {
        throw new InvalidArgumentException('Knowledge enemy mappings require a non-empty enemy name and existing subject.');
      }
      $key = strtolower(trim($enemyName));
      if (isset($this->enemyMappings[$key])) {
        throw new InvalidArgumentException("Duplicate knowledge enemy mapping: {$enemyName}.");
      }
      $this->enemyMappings[$key] = $subjectId;
    }

    foreach ((array) ($data['reports'] ?? []) as $entry) {
      if (! is_array($entry)) {
        throw new InvalidArgumentException('Knowledge reports must be arrays.');
      }
      $id = KnowledgeIdentity::require(strval($entry['id'] ?? ''), 'report id');
      $subjectId = KnowledgeIdentity::require(strval($entry['subject'] ?? ''), 'report subject id');
      if (isset($this->reports[$id]) || ! isset($this->subjects[$subjectId])) {
        throw new InvalidArgumentException("Knowledge report {$id} has a duplicate ID or unknown subject.");
      }
      $this->reports[$id] = new KnowledgeReport(
        $id,
        $subjectId,
        $this->requiredText($entry['title'] ?? null, "report {$id} title"),
        $this->requiredText($entry['summary'] ?? null, "report {$id} summary"),
        $this->optionalText($entry['details'] ?? null),
        $this->stableIds($entry['disagreesWith'] ?? [], 'report id'),
        intval($entry['displayOrder'] ?? 0),
      );
    }

    foreach ($this->reports as $report) {
      foreach ($report->disagreesWith as $otherId) {
        if (! isset($this->reports[$otherId])) {
          throw new InvalidArgumentException("Knowledge report {$report->id} disagrees with unknown report {$otherId}.");
        }
      }
    }
  }

  private function assertNoPrivateTruth(array $data): void
  {
    foreach ($data as $key => $value) {
      if (is_string($key) && in_array(strtolower($key), ['private', 'privatetruth', 'authortruth', 'spoilertruth'], true)) {
        throw new InvalidArgumentException('Private author truth cannot be stored in the public knowledge catalog.');
      }
      if (is_array($value)) {
        $this->assertNoPrivateTruth($value);
      }
    }
  }

  private function requiredText(mixed $value, string $label): string
  {
    $value = trim(strval($value));
    if ($value === '') {
      throw new InvalidArgumentException(ucfirst($label) . ' cannot be empty.');
    }
    return $value;
  }

  private function optionalText(mixed $value): ?string
  {
    $value = trim(strval($value ?? ''));
    return $value !== '' ? $value : null;
  }

  /** @return string[] */
  private function stableIds(mixed $values, string $label): array
  {
    $ids = [];
    foreach (is_array($values) ? $values : [] as $value) {
      $id = KnowledgeIdentity::require(strval($value), $label);
      if (in_array($id, $ids, true)) {
        throw new InvalidArgumentException("Duplicate {$label}: {$id}.");
      }
      $ids[] = $id;
    }
    return $ids;
  }
}
