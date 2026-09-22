<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;

/** Authored credit order and wording shared by rolling and paged presentations. */
final readonly class CreditsContent
{
  /** @var list<array{title: string, lines: list<string>}> */
  public array $sections;

  public function __construct(array $sections)
  {
    $copy = [];
    foreach ($sections as $section) {
      if (!is_array($section)) { continue; }
      $lines = array_values(array_filter((array)($section['lines'] ?? []), 'is_string'));
      if ($lines === []) { continue; }
      $copy[] = ['title' => is_string($section['title'] ?? null) ? $section['title'] : '', 'lines' => $lines];
    }
    $this->sections = $copy;
  }

  /** @return list<array{text: string, color: string}> */
  public function getLines(int $columns): array
  {
    $lines = [];
    foreach ($this->sections as $section) {
      if ($lines !== []) { $lines[] = ['text' => '', 'color' => 'text']; }
      if ($section['title'] !== '') {
        foreach (TerminalText::wrapToWidth($section['title'], max(1, $columns)) as $line) {
          $lines[] = ['text' => $line, 'color' => 'accent'];
        }
      }
      foreach ($section['lines'] as $text) {
        foreach (TerminalText::wrapToWidth($text, max(1, $columns)) as $line) {
          $lines[] = ['text' => $line, 'color' => 'text'];
        }
      }
    }
    return $lines;
  }

  /** @return non-empty-list<string> */
  public function getPages(int $columns, int $rows): array
  {
    $lines = array_column($this->getLines($columns), 'text');
    return array_map(static fn(array $page): string => implode("\n", $page),
      array_chunk($lines, max(1, $rows))) ?: [''];
  }
}
