<?php

namespace Ichiloto\Engine\IO\Console;

/** Active terminal attributes for independently drawable cells, without obsolete SGR history. */
final class SgrStyleState
{
  /** @var array<int, string> Attributes keyed by their reset group. */
  private array $attributes = [];
  private string $opaque = '';

  public function prefix(): string
  {
    return $this->opaque . implode('', $this->attributes);
  }

  public function apply(string $sequence): void
  {
    if (preg_match('/\A\x1B\[([0-9;]*)m\z/', $sequence, $match) !== 1) {
      $this->preserve($sequence);
      return;
    }

    $values = explode(';', $match[1]);
    $units = [];
    for ($i = 0; $i < count($values); $i++) {
      $code = (int) $values[$i];
      $parameters = (string) $code;
      if (in_array($code, [38, 48, 58], true)) {
        $mode = $values[$i + 1] ?? null;
        $count = match ($mode) { '5' => 1, '2' => 3, default => 0 };
        $components = array_slice($values, $i + 2, $count);
        if ($count === 0 || count($components) !== $count || in_array('', $components, true)) {
          // Do not reinterpret incomplete colour components as independent resets.
          $this->preserve($sequence);
          return;
        }
        $parameters .= ';' . $mode . ';' . implode(';', $components);
        $i += $count + 1;
      }
      $units[] = [$code, "\e[{$parameters}m"];
    }

    foreach ($units as [$code, $unit]) {
      if ($code === 0) {
        $this->attributes = [];
        $this->opaque = '';
        continue;
      }
      if ($this->opaque !== '') {
        $this->opaque .= $unit;
        continue;
      }

      $reset = match ($code) {
        22 => [1, 2], 23 => [3], 24 => [4], 25 => [5],
        27 => [7], 28 => [8], 29 => [9], 39 => [38], 49 => [48],
        59 => [58],
        default => [],
      };
      if ($reset !== []) {
        foreach ($reset as $key) { unset($this->attributes[$key]); }
        continue;
      }

      $group = match (true) {
        $code === 38, $code >= 30 && $code <= 37, $code >= 90 && $code <= 97 => 38,
        $code === 48, $code >= 40 && $code <= 47, $code >= 100 && $code <= 107 => 48,
        $code === 21 => 4,
        $code === 6 => 5,
        in_array($code, [1, 2, 3, 4, 5, 7, 8, 9, 58], true) => $code,
        default => null,
      };
      if ($group === null) {
        $this->preserve($unit);
      } else {
        $this->attributes[$group] = $unit;
      }
    }
  }

  private function preserve(string $sequence): void
  {
    // Unknown controls retain their ordering and legacy replay semantics until a
    // full reset. Never discard terminal features this normalizer cannot model.
    $this->opaque = $this->prefix() . $sequence;
    $this->attributes = [];
  }
}
