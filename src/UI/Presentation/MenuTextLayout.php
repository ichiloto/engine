<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/** Measures visible Canvas codepoints and projects existing text colours without terminal controls. */
final readonly class MenuTextLayout
{
  /** @var non-empty-list<string> */
  public array $lines;
  /** @var list<array{text: string, foreground: ?PresentationColor, background: ?PresentationColor}> */
  private array $characters;

  public function __construct(string $text, public int $columns)
  {
    if ($columns < 1 || !mb_check_encoding($text, 'UTF-8')) {
      throw new InvalidArgumentException('Menu text requires UTF-8 and a positive cell width.');
    }
    $characters = [];
    $plain = '';
    foreach (TerminalText::visibleSymbols(MenuTextWrap::normalizeText($text)) as $symbol) {
      $style = SgrColorParser::parse($symbol);
      $visible = TerminalText::stripAnsi($symbol);
      $plain .= $visible;
      foreach (mb_str_split($visible, 1, 'UTF-8') as $character) {
        $characters[] = ['text' => $character, ...$style];
      }
    }
    $this->characters = $characters;
    $this->lines = MenuTextWrap::lines($plain, $columns);
  }

  /** @return list<PresentationTextRun> */
  public function getRuns(?PresentationColor $foreground = null, ?PresentationColor $background = null,
    HorizontalAlignment $alignment = HorizontalAlignment::LEFT): array
  {
    $runs = [];
    $offset = 0;
    foreach ($this->lines as $row => $line) {
      $length = mb_strlen($line, 'UTF-8');
      $space = $this->columns - $length;
      $column = match ($alignment) {
        HorizontalAlignment::LEFT => 0,
        HorizontalAlignment::CENTER => intdiv($space, 2),
        HorizontalAlignment::RIGHT => $space,
      };
      $text = '';
      $start = $column;
      $style = ['foreground' => $foreground, 'background' => $background];
      for ($index = 0; $index < $length; $index++, $offset++, $column++) {
        $character = $this->characters[$offset];
        $next = ['foreground' => $character['foreground'] ?? $foreground,
          'background' => $character['background'] ?? $background];
        if ($text !== '' && $next != $style) {
          $runs[] = new PresentationTextRun($row, $start, $text, ...$style);
          $text = '';
        }
        if ($text === '') { $start = $column; }
        $text .= $character['text'];
        $style = $next;
      }
      if ($text !== '') { $runs[] = new PresentationTextRun($row, $start, $text, ...$style); }
      // Soft wrapping preserves every character; only authored paragraph breaks are consumed.
      if (($this->characters[$offset]['text'] ?? null) === "\n") { $offset++; }
    }
    return $runs;
  }
}
