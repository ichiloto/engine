<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;

/** Terminal projection of the same facts and stage order as the graphical view. */
final class BattleResultsText
{
  /** @return list<string> */
  public static function getLines(BattleResultsPlayback $playback, int $width): array
  {
    if ($width < 1) { throw new InvalidArgumentException('Results content width must be positive.'); }
    $stage = $playback->currentStage();
    $rewards = $playback->rewards;
    if ($stage['kind'] !== 'primary') {
      $lines = [];
      foreach (BattleResultsContent::getEventRows($playback) as $row) {
        $content = isset($row['value'])
          ? self::formatLabelValue($row['text'], $row['value'], $width)
          : self::wrapLine($row['text'], $width);
        array_push($lines, ...$content);
      }
      return $lines;
    }
    switch ($stage['kind']) {
      case 'primary':
        $lines = [sprintf('EXP per member: %d   Gold: %d G', $rewards->experiencePerMember, $rewards->gold), 'Party Progress'];
        foreach ($rewards->progression as $member) {
          if ($member->after === null) { continue; }
          array_push($lines, ...self::formatLabelValue($member->after->name . ':',
            sprintf('Lv %d -> %d  +%d EXP', $member->oldLevel, $member->newLevel, $member->experienceAwarded),
            $width));
        }
        $lines[] = 'Rewards';
        foreach ($rewards->items as $item) {
          $lines[] = sprintf('%s x%d', $item['name'], $item['quantity'])
            . (isset($item['received']) && $item['received'] !== $item['quantity']
              ? sprintf(' (retained %d)', $item['received']) : '');
        }
        if ($rewards->items === []) { $lines[] = 'No item drops.'; }
        foreach ($rewards->summary as $label => $value) { $lines[] = $label . ': ' . $value; }
        return array_merge(...array_map(static fn(string $line): array => self::wrapLine($line, $width), $lines));
      default:
        return [];
    }
  }

  /** Overflow continues on separate lines, never squeezing or clipping either column. */
  private static function formatLabelValue(string $label, string $value, int $width): array
  {
    $labels = self::wrapLine($label, $width);
    $values = self::wrapLine($value, $width);
    $gap = $width - TerminalText::displayWidth($labels[0]) - TerminalText::displayWidth($values[0]);
    if (count($labels) === 1 && count($values) === 1 && $gap >= 2) {
      return [$labels[0] . str_repeat(' ', $gap) . $values[0]];
    }
    return [...$labels, ...array_map(static fn(string $line): string => TerminalText::padLeft($line, $width), $values)];
  }

  private static function wrapLine(string $text, int $width): array
  {
    $text = str_replace(["\r", "\t"], ['', ' '], TerminalText::stripAnsi($text));
    $text = (string)preg_replace('/(?!\n)\p{Cc}/u', '', $text);
    return TerminalText::wrapParagraphsToWidth($text, $width);
  }
}
