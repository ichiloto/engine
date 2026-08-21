<?php

namespace Ichiloto\Engine\Progress\Knowledge;

/** Authored public definition. No private author truth belongs here. */
final readonly class KnowledgeSubject
{
  /**
   * @param string[] $tags
   * @param string[] $habitats
   * @param array<int, array{type: string, subject: string}> $relationships
   * @param string[] $observationIds
   */
  public function __construct(
    public string $id,
    public string $recordType,
    public string $displayName,
    public string $quickCard,
    public ?string $deepCard = null,
    public ?string $family = null,
    public ?string $species = null,
    public array $tags = [],
    public array $habitats = [],
    public array $relationships = [],
    public array $observationIds = [],
    public int $displayOrder = 0,
    public bool $hidden = false,
  )
  {
  }
}
