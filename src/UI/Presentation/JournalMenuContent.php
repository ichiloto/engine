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
    public MenuDetailSheet $details,
    public string $previewText,
    public bool $detailsOpen,
    public bool $journal,
    public ?JournalDocument $document = null,
    public ?string $confirmLabel = 'View details',
    /** @var list<string> Label heading followed by one heading for each value column. */
    public array $columnHeadings = [],
  ) {
    if (!array_is_list($columnHeadings)
      || array_any($columnHeadings, static fn(mixed $heading): bool => !is_string($heading) || trim($heading) === '')
      || ($columnHeadings !== [] && array_any($rows,
        static fn(array $row): bool => count($row['values']) !== count($columnHeadings) - 1))) {
      throw new \InvalidArgumentException('A journal table declares one heading for its label and each value column.');
    }
  }
}
