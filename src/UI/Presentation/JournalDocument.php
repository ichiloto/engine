<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Journal sections over the shared detail sheet: one source for Terminal text and styled graphical sections. */
final readonly class JournalDocument
{
  public MenuDetailSheet $sheet;
  public string $text;
  /** @var list<array{section: int, heading: bool, icon: ?string}> Indexed like the sheet's entries. */
  private array $entries;

  /** @param list<JournalSection> $sections */
  public function __construct(public array $sections)
  {
    $sheet = [];
    $entries = [];
    foreach ($sections as $index => $section) {
      $items = [MenuDetailEntry::createProse($section->title, 'accent')];
      $entries[] = ['section' => $index, 'heading' => true, 'icon' => $section->icon];
      foreach ($section->entries as $entry) {
        // Authored text may be empty, as a quest without a description is; blank space is not an entry.
        if (trim($entry['text']) === '') { continue; }
        $items[] = isset($entry['label']) ? MenuDetailEntry::createField($entry['label'], $entry['text'], $entry['color'] ?? 'text')
          : MenuDetailEntry::createProse($entry['text'], $entry['color'] ?? 'text');
        $entries[] = ['section' => $index, 'heading' => false, 'icon' => $entry['icon'] ?? null];
      }
      $sheet[] = $items;
    }
    $this->sheet = new MenuDetailSheet($sheet);
    $this->text = $this->sheet->text;
    $this->entries = $entries;
  }

  /** Metadata follows the measured entries, never guessed by parsing translated display text.
   * @return list<array{text: string, section: ?int, heading: bool, icon: ?string, color: string}>
   */
  public function lines(int $columns, string $labelSuffix = ''): array
  {
    $lines = [];
    $seen = [];
    $layout = $this->sheet->layout($columns, $labelSuffix);
    foreach ($layout->getWrappedLines() as $index => [, $text]) {
      $line = $layout->lines[$index];
      $entry = $line->entry === null ? null : $this->entries[$line->entry];
      $first = $line->entry !== null && !isset($seen[$line->entry]);
      if ($line->entry !== null) { $seen[$line->entry] = true; }
      $lines[] = ['text' => $text, 'section' => $entry['section'] ?? null, 'heading' => $entry['heading'] ?? false,
        'icon' => $first ? $entry['icon'] : null, 'color' => $line->color];
    }
    return $lines;
  }
}
