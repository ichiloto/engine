<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Text\TextViewport;

/** One source for plain terminal text and independently styled graphical sections. */
final readonly class JournalDocument
{
  public string $text;
  /** @var list<array{start: int, end: int, section: int, heading: bool, icon: ?string, color: string}> */
  private array $spans;

  /** @param list<JournalSection> $sections */
  public function __construct(public array $sections)
  {
    $text = '';
    $spans = [];
    foreach ($sections as $index => $section) {
      if ($index > 0) { $text .= "\n"; }
      $entries = [['text' => $section->title, 'icon' => $section->icon, 'color' => 'accent'], ...$section->entries];
      foreach ($entries as $entryIndex => $entry) {
        $value = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], TerminalText::stripAnsi($entry['text']));
        $start = strlen($text);
        $text .= $value;
        $spans[] = ['start' => $start, 'end' => strlen($text), 'section' => $index,
          'heading' => $entryIndex === 0, 'icon' => $entry['icon'] ?? null, 'color' => $entry['color'] ?? 'text'];
        $text .= "\n";
      }
    }
    $this->text = rtrim($text, "\n");
    $this->spans = $spans;
  }

  /** Metadata follows source offsets, never guessed by parsing translated display text.
   * @return list<array{text: string, section: ?int, heading: bool, icon: ?string, color: string}>
   */
  public function lines(int $columns): array
  {
    $lines = [];
    $cursor = 0;
    foreach (TextViewport::wrap($this->text, $columns) as [$offset, $text]) {
      while (isset($this->spans[$cursor]) && $offset > $this->spans[$cursor]['end']) { $cursor++; }
      $span = $this->spans[$cursor] ?? null;
      if ($span !== null && $offset < $span['start']) { $span = null; }
      $lines[] = ['text' => $text, 'section' => $span['section'] ?? null, 'heading' => $span['heading'] ?? false,
        'icon' => $span !== null && $offset === $span['start'] ? $span['icon'] : null, 'color' => $span['color'] ?? 'text'];
    }
    return $lines;
  }
}
