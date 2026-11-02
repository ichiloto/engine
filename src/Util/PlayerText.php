<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Util;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** Source validation for authored sentence prose; names, labels and controls have their own contracts. */
final class PlayerText
{
  private function __construct() {}

  public static function assertSentence(string $text, string $context, bool $allowEmpty = false): void
  {
    if (!mb_check_encoding($text, 'UTF-8')) { throw new InvalidArgumentException($context . ' must be UTF-8 sentence text.'); }
    $plain = trim(TerminalText::stripAnsi(TerminalText::formatStyles($text)));
    if ($plain === '' && $allowEmpty) { return; }
    if ($plain === '') { throw new InvalidArgumentException($context . ' requires a visible sentence.'); }
    foreach (preg_split('/\R\s*\R/u', $plain) as $paragraph) {
      if (preg_match('/\A[^\p{L}\p{N}]*(\p{Ll})/u', $paragraph) === 1) {
        throw new InvalidArgumentException($context . ' must start each paragraph in sentence case.');
      }
      if (preg_match('/[.!?…。！？][\p{Pe}\p{Pf}"\x{27}\s]*\z/u', $paragraph) !== 1) {
        throw new InvalidArgumentException($context . ' must end each paragraph with sentence punctuation.');
      }
    }
  }
}
