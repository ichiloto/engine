<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use InvalidArgumentException;

/** Word-aware wrapping for the existing one-codepoint-per-cell Canvas contract. */
final class MenuTextWrap
{
  /** @return non-empty-list<string> Complete text, with whitespace retained at wrap boundaries. */
  public static function lines(string $text, int $cells): array
  {
    if ($cells < 1 || !mb_check_encoding($text, 'UTF-8')) {
      throw new InvalidArgumentException('Menu text requires UTF-8 and a positive cell width.');
    }
    $lines = [];
    $text = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", '    '], $text);
    foreach (explode("\n", $text) as $paragraph) {
      // Nonbreaking spaces remain inside their word. Hyphens do not introduce a break.
      preg_match_all('/[^\S\x{00A0}\x{202F}]+|[\S\x{00A0}\x{202F}]+/u', $paragraph, $matches);
      $line = '';
      $width = 0;
      foreach ($matches[0] as $token) {
        $length = mb_strlen($token, 'UTF-8');
        $whitespace = preg_match('/\A[^\S\x{00A0}\x{202F}]+\z/u', $token) === 1;
        if (!$whitespace && $width > 0 && $width + $length > $cells
          && ($length <= $cells || preg_match('/\S/u', $line) === 1)) {
          $lines[] = $line;
          $line = '';
          $width = 0;
        }
        for ($offset = 0; $offset < $length;) {
          if ($width === $cells) {
            $lines[] = $line;
            $line = '';
            $width = 0;
          }
          $take = min($cells - $width, $length - $offset);
          $line .= mb_substr($token, $offset, $take, 'UTF-8');
          $width += $take;
          $offset += $take;
        }
      }
      $lines[] = $line;
    }
    return $lines;
  }
}
