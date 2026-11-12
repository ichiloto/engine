<?php

namespace Ichiloto\Engine\Progress\Knowledge;

use InvalidArgumentException;

/**
 * A kind of record the project's knowledge catalogue declares, such as its
 * creatures or the summons met in battle. The project names it for players
 * with its label; an unlabelled type is shown by its id.
 */
final class KnowledgeRecordType
{
  /** What players see the type called. */
  public string $name {
    get => $this->label ?? $this->id;
  }

  public function __construct(
    public readonly string $id,
    public readonly ?string $label = null,
  )
  {
  }

  /**
   * Reads one `recordTypes` entry: its id alone, or `['id' => ..., 'label' => ...]`.
   *
   * @throws InvalidArgumentException When the entry is neither, or its id is not a stable id.
   */
  public static function fromEntry(mixed $entry): self
  {
    if (is_string($entry)) {
      return new self(KnowledgeIdentity::require($entry, 'record type'));
    }
    if (! is_array($entry) || array_diff(array_keys($entry), ['id', 'label']) !== []
      || ! is_string($entry['id'] ?? null) || ! (is_string($entry['label'] ?? null) || ($entry['label'] ?? null) === null)) {
      throw new InvalidArgumentException('A record type is its id, or an id with a label.');
    }
    $label = trim($entry['label'] ?? '');

    return new self(KnowledgeIdentity::require($entry['id'], 'record type'), $label !== '' ? $label : null);
  }
}
