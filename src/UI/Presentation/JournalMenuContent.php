<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Fresh, already spoiler-filtered display values from the journal/records owner. */
final readonly class JournalMenuContent
{
  /** @param list<string> $tabs
   * @param list<array{label: string, values: list<string>, icon?: string}> $rows
   */
  public function __construct(
    public string $title,
    public array $tabs,
    public int $tabIndex,
    public string $summary,
    public array $rows,
    public int $index,
    public string $emptyText,
    public string $entryKey,
    public string $detailText,
    public string $previewText,
    public bool $detailsOpen,
    public bool $journal,
    public ?JournalDocument $document = null,
  ) {}
}
