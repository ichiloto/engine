<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;

/** Shared measured pages keep navigation and native composition in agreement. */
final class BattleResultsContent
{
  public static function pageCount(BattleResultsPlayback $playback): int
  {
    if ($playback->currentStage()['kind'] !== 'primary') {
      return max(1, (int)ceil(count(self::eventLines($playback)) / self::eventPageSize($playback)));
    }
    return max(1, (int)ceil(count(self::partyRows($playback->rewards)) / 4),
      (int)ceil(count(self::itemLines($playback->rewards)) / 4),
      (int)ceil(count(self::summaryLines($playback->rewards)) / 3));
  }

  public static function eventPageSize(BattleResultsPlayback $playback): int
  {
    return $playback->currentStage()['kind'] === 'special' ? 6 : 11;
  }

  /** Very long identities continue on another card instead of losing text. */
  public static function partyRows(BattleRewards $rewards): array
  {
    $rows = [];
    foreach ($rewards->progression as $actor => $award) {
      $gainWidth = max(98, strlen('+' . $award->experienceAwarded) * 13);
      $nameColumns = min(34, (int)floor((582 - $gainWidth - 8) / 14));
      foreach (array_chunk(self::wrap($award->after->name, $nameColumns), 2) as $part => $name) {
        $rows[] = ['actor' => $actor, 'name' => $name, 'continued' => $part > 0,
          'nameColumns' => $nameColumns, 'gainWidth' => $gainWidth];
      }
    }
    return $rows;
  }

  public static function itemLines(BattleRewards $rewards): array
  {
    $lines = [];
    foreach ($rewards->items as $item) {
      $quantity = 'x' . $item['quantity'];
      $width = max(1, 31 - mb_strlen($quantity) - 1);
      foreach (self::wrap($item['name'], $width) as $part => $label) {
        $lines[] = ['name' => $label, 'quantity' => $part === 0 ? $quantity : '', 'continued' => $part > 0];
      }
      if (isset($item['received']) && $item['received'] !== $item['quantity']) {
        foreach (self::wrap('Retained: ' . $item['received'] . ' of ' . $item['quantity'], 29) as $label) {
          $lines[] = ['name' => $label, 'quantity' => '', 'continued' => true];
        }
      }
    }
    return $lines;
  }

  public static function summaryLines(BattleRewards $rewards): array
  {
    $lines = [];
    foreach ($rewards->summary as $label => $value) {
      array_push($lines, ...self::wrap($label . ': ' . $value, 37));
    }
    foreach ($rewards->specialRewards as $event) {
      array_push($lines, ...self::wrap('Special: ' . $event['title'], 37));
    }
    return $lines;
  }

  /** @return list<array{text: string, tone: string}> */
  public static function eventLines(BattleResultsPlayback $playback): array
  {
    $stage = $playback->currentStage();
    $lines = [];
    $add = static function (string $text, string $tone = 'text') use (&$lines): void {
      foreach (self::wrap($text, 50) as $line) { $lines[] = ['text' => $line, 'tone' => $tone]; }
    };
    if ($stage['kind'] === 'special') {
      $event = $playback->rewards->specialRewards[$stage['detail']];
      $add($event['title'], 'accent');
      $add($event['description']);
      return $lines;
    }
    $actor = $stage['actor'];
    if ($actor === null) { return []; }
    $award = $playback->rewards->progression[$actor];
    $before = $award->before;
    $after = $award->after;
    assert($before !== null && $after !== null);
    $add($after->name, 'accent');
    if ($stage['kind'] === 'level') {
      $add('Level ' . $before->level . "  \u{2192}  " . $after->level, 'accent');
      foreach ($after->stats as $stat => $value) {
        if (!array_key_exists($stat, $before->stats)) { continue; }
        $old = $before->stats[$stat];
        $delta = $value - $old;
        $label = match ($stat) {
          'maxHp' => 'HP', 'maxMp' => 'MP',
          default => ucfirst((string)preg_replace('/([a-z])([A-Z])/', '$1 $2', $stat)),
        };
        $numbers = $old . " \u{2192} " . $value . ' (' . ($delta > 0 ? '+' : '') . $delta . ')';
        $tone = $delta > 0 ? 'positive' : ($delta < 0 ? 'negative' : 'muted');
        if (mb_strlen($label . $numbers) < 49) {
          $add($label . str_repeat(' ', 50 - mb_strlen($label . $numbers)) . $numbers, $tone);
        } else {
          $add($label, $tone);
          $add($numbers, $tone);
        }
      }
    } else {
      $entries = $playback->abilities($actor);
      $entry = $entries[$stage['detail']];
      $add($entry['name'], 'accent');
      $add(ucfirst($entry['kind']) . '  ' . ($stage['detail'] + 1) . '/' . count($entries), 'muted');
      if (isset($entry['cost'])) {
        $add('Cost: ' . $entry['cost'] . ' MP');
      }
      $add($entry['description']);
    }
    return $lines;
  }

  /** Scalar-cell wrapping matches CanvasTextLayer, with no truncation or ANSI leakage. */
  public static function wrap(string $text, int $columns): array
  {
    $text = str_replace(["\r", "\t"], ['', ' '], TerminalText::stripAnsi($text));
    $text = (string)preg_replace('/(?!\n)\p{Cc}/u', '', $text);
    $lines = [];
    foreach (explode("\n", $text) as $line) {
      if ($line === '') { $lines[] = ''; continue; }
      while (mb_strlen($line, 'UTF-8') > $columns) {
        $chunk = mb_substr($line, 0, $columns, 'UTF-8');
        $space = mb_strrpos($chunk, ' ', 0, 'UTF-8');
        $cut = $space !== false && $space >= intdiv($columns, 2) ? $space : $columns;
        $lines[] = mb_substr($line, 0, $cut, 'UTF-8');
        $line = ltrim(mb_substr($line, $cut, null, 'UTF-8'));
      }
      if ($line !== '') { $lines[] = $line; }
    }
    return $lines === [] ? [''] : $lines;
  }
}
