<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Text;

use Ichiloto\Engine\IO\Console\TerminalText;

/** PHP-owned two-line reading position shared by terminal and canvas Info panels. */
final class MenuInfoText
{
  private string $source = '';
  private int $offset = 0;
  /** @var list<array{int, string}> */
  private array $lines = [];
  private ?TextPage $measuredPage = null;
  public ?TextPage $lastPage { get => $this->measuredPage; }

  public function reset(): void
  {
    $this->offset = 0;
    $this->measuredPage = null;
    $this->lines = [];
  }

  /** Reading may measure/reflow or reset changed source, but never advances its position. */
  public function getPage(string $description, ?string $status, int $columns): TextPage
  {
    $source = $description;
    if ($status !== null && $status !== '') { $source .= ($source === '' ? '' : "\n") . $status; }
    $source = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], TerminalText::stripAnsi($source));
    $lines = TextViewport::wrap($source, $columns);
    if ($source !== $this->source) { $this->offset = 0; }
    $this->source = $source;
    $this->lines = $lines;
    return $this->measuredPage = $this->getSnapshot($columns);
  }

  /** Advances a whole page using the last measured layout, cycling after its final partial page. */
  public function advance(): bool
  {
    if ($this->lastPage === null || count($this->lines) <= 2) { return false; }
    $this->offset = $this->getSnapshot($this->lastPage->columns)->nextOffset;
    return true;
  }

  private function getSnapshot(int $columns): TextPage
  {
    $first = 0;
    foreach ($this->lines as $index => [$offset]) {
      if ($offset > $this->offset) { break; }
      $first = $index;
    }
    $first = intdiv($first, 2) * 2;
    $total = count($this->lines);
    return new TextPage($this->source, array_column(array_slice($this->lines, $first, 2), 1),
      $first, $total, $columns, 2, $this->lines[max(0, $first - 2)][0],
      $this->lines[$first + 2 < $total ? $first + 2 : 0][0]);
  }
}
