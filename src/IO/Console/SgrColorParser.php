<?php

namespace Ichiloto\Engine\IO\Console;

use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use InvalidArgumentException;

/** Extracts colour at a canonical cell's glyph, not terminal control behaviour. */
final class SgrColorParser
{
  /** @return array{foreground: ?PresentationColor, background: ?PresentationColor} */
  public static function parse(string $cell): array
  {
    $foreground = $background = null;
    $standardForeground = null;
    $intense = false;
    preg_match_all('/\x1B\[[0-9;?]*[ -\/]*[@-~]|[^\x1B]+/u', $cell, $tokens);
    foreach ($tokens[0] as $token) {
      if (!str_starts_with($token, "\x1B[")) { break; }
      if (!str_ends_with($token, 'm')) { continue; }
      $parameters = substr($token, 2, -1);
      if (preg_match('/\A[0-9;]*\z/', $parameters) !== 1) {
        throw new InvalidArgumentException('Malformed canonical SGR colour parameters.');
      }
      $values = explode(';', $parameters);
      for ($i = 0; $i < count($values); $i++) {
        $code = (int)$values[$i];
        if ($code === 0) {
          $foreground = $background = $standardForeground = null;
          $intense = false;
        } elseif ($code === 1 || $code === 22) {
          $intense = $code === 1;
          if ($standardForeground !== null) {
            $foreground = PresentationColor::ansi16($standardForeground + ($intense ? 8 : 0));
          }
        } elseif (($code >= 30 && $code <= 37) || ($code >= 90 && $code <= 97)) {
          $standardForeground = $code < 90 ? $code - 30 : null;
          $foreground = PresentationColor::ansi16($code < 90 ? $code - 30 + ($intense ? 8 : 0) : $code - 90 + 8);
        } elseif (($code >= 40 && $code <= 47) || ($code >= 100 && $code <= 107)) {
          $background = PresentationColor::ansi16($code < 100 ? $code - 40 : $code - 100 + 8);
        } elseif ($code === 39) {
          $foreground = $standardForeground = null;
        } elseif ($code === 49) {
          $background = null;
        } elseif ($code === 38 || $code === 48) {
          $mode = $values[++$i] ?? null;
          $count = match ($mode) { '5' => 1, '2' => 3, default => 0 };
          $components = array_slice($values, $i + 1, $count);
          if ($count === 0 || count($components) !== $count
            || array_any($components, static fn(string $value) => $value === '' || !ctype_digit($value))) {
            throw new InvalidArgumentException('Malformed extended canonical SGR colour.');
          }
          $components = array_map(intval(...), $components);
          $color = $mode === '5' ? PresentationColor::ansi256($components[0])
            : PresentationColor::rgb($components[0], $components[1], $components[2]);
          $i += $count;
          if ($code === 38) {
            $foreground = $color;
            $standardForeground = null;
          } else {
            $background = $color;
          }
        }
        // Non-colour SGR attributes have no v2 representation; terminal cells retain them.
      }
    }
    return ['foreground' => $foreground, 'background' => $background];
  }
}
