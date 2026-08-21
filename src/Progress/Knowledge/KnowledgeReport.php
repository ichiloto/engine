<?php

namespace Ichiloto\Engine\Progress\Knowledge;

/** Authored report text; saves retain only this record's stable ID and state. */
final readonly class KnowledgeReport
{
  /** @param string[] $disagreesWith */
  public function __construct(
    public string $id,
    public string $subjectId,
    public string $title,
    public string $summary,
    public ?string $details = null,
    public array $disagreesWith = [],
    public int $displayOrder = 0,
  )
  {
  }
}
