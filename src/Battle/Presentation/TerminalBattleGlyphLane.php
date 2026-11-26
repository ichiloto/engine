<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectHorizontalFacing;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;

/** One cell-art lance, stretched by shaft tiling rather than copying a whole arrow per target. */
final class TerminalBattleGlyphLane
{
  public static function compose(TerminalBattleLaneSnapshot $snapshot, array $payload, ?string $color,
    int $left, int $top, int $width, int $height, ?int $recipient = null): array
  {
    $reach = $payload['reach'] ?? 1.0;
    if ($reach <= 0.0) { return []; }
    $sign = $recipient === null ? $snapshot->getDirectionSign() : $snapshot->getGroundDirectionSign($recipient);
    $target = $recipient === null ? $snapshot->getEndpoint() : $snapshot->getTargetGround($recipient);
    $start = $recipient === null ? $snapshot->caster : $snapshot->casterGround;
    $spanX = $target['x'] - $start['x'];
    $spanY = $target['y'] - $start['y'];
    $tipX = (int)round($start['x'] + $spanX * $reach);
    $tipY = (int)round($start['y'] + $spanY * ($spanX === 0 ? $reach : ($tipX - $start['x']) / $spanX));
    if ($reach < 1.0 && $spanX !== 0) {
      // A wide head must fit the revealed interval without extending behind the caster.
      $right = min($left + $width, max($start['x'], $tipX) + 1);
      $left = max($left, min($start['x'], $tipX));
      $width = max(0, $right - $left);
    }
    $head = $payload['head']; $shaft = $payload['shaft'];
    if ($sign !== EffectHorizontalFacing::from($payload['facing'])->getSign()) {
      $head = self::mirrorRows($head); $shaft = self::mirrorRows($shaft);
    }
    $headWidth = TerminalText::displayWidth($head[0]); $halfHeight = intdiv(count($head), 2);
    $headX = $sign < 0 ? $tipX : $tipX - $headWidth + 1;
    $rearX = $sign < 0 ? $headX + $headWidth : $headX - 1;
    $minimum = max($left, min($start['x'], $rearX));
    $maximum = min($left + $width - 1, max($start['x'], $rearX));
    $paint = $occupied = [];
    $put = static function (int $x, int $y, string $glyph) use (&$paint, &$occupied, $snapshot, $left, $top, $width, $height): void {
      if ($glyph === ' ' || $glyph === NormalizedRow::CONTINUATION) { return; }
      $span = NormalizedRow::symbolWidth($glyph);
      if ($x < $left || $x + $span > $left + $width || $y < $top || $y >= $top + $height || $snapshot->isOccluded($x, $y, $span)) { return; }
      // A replacement never leaves the continuation half of an earlier wide glyph behind.
      for ($column = $x; $column < $x + $span; $column++) {
        $at = $occupied[$y][$column] ?? null;
        if ($at === null) { continue; }
        $oldSpan = NormalizedRow::symbolWidth($paint[$y][$at]);
        for ($old = $at; $old < $at + $oldSpan; $old++) { unset($occupied[$y][$old]); }
        unset($paint[$y][$at]);
      }
      $paint[$y][$x] = $glyph;
      for ($column = $x; $column < $x + $span; $column++) { $occupied[$y][$column] = $x; }
    };
    $rows = array_map(static fn(string $row): array => NormalizedRow::fromText($row)->cells, $shaft);
    $tileWidth = count($rows[0]);
    if (($tipX - $start['x']) * $sign >= $headWidth) {
      for ($x = $minimum; $x <= $maximum; $x++) {
        $fraction = ($x - $start['x']) / $spanX;
        $middleY = (int)round($start['y'] + $spanY * $fraction);
        $column = (($x - $start['x']) % $tileWidth + $tileWidth) % $tileWidth;
        foreach ($rows as $row => $cells) {
          $glyph = $cells[$column];
          if ($x + NormalizedRow::symbolWidth($glyph) - 1 <= $maximum) { $put($x, $middleY + $row - $halfHeight, $glyph); }
        }
      }
    }
    foreach ($head as $row => $line) {
      foreach (NormalizedRow::fromText($line)->cells as $column => $glyph) {
        $put($headX + $column, $tipY + $row - $halfHeight, $glyph);
      }
    }
    ksort($paint); $draws = [];
    foreach ($paint as $y => $row) {
      ksort($row); $run = ''; $runX = $nextX = null;
      $flush = static function () use (&$draws, &$run, &$runX, $y, $color): void {
        if ($run !== '') { $draws[] = ['text' => TerminalBattleEffects::formatLine($run, TerminalBattleEffects::resolveNamedColor($color)), 'x' => $runX, 'y' => $y]; }
        $run = '';
      };
      foreach ($row as $x => $glyph) {
        if ($nextX !== $x) { $flush(); $runX = $x; }
        $run .= $glyph; $nextX = $x + NormalizedRow::symbolWidth($glyph);
      }
      $flush();
    }
    return $draws;
  }

  private static function mirrorRows(array $rows): array
  {
    return array_map(static function (string $line): string {
      preg_match_all('/\X/u', $line, $matches);
      return strtr(implode('', array_reverse($matches[0])), ['/' => '\\', '\\' => '/', '(' => ')', ')' => '(', '[' => ']', ']' => '[', '<' => '>', '>' => '<']);
    }, $rows);
  }
}
