<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Text;

use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Console\TerminalText;

/** A character's current conditions as Terminal menu text, from the same projection battle uses. */
final class TerminalConditions
{
  private function __construct() {}

  /**
   * Composes one compact line of condition badges (icon, or label without one).
   *
   * @param CharacterInterface $character The character.
   * @return string The badges, space separated; empty for a character with no conditions.
   */
  public static function composeBadges(CharacterInterface $character): string
  {
    return implode(' ', array_map(static fn(array $entry): string => TerminalText::stabilize($entry['badge']),
      BattlerConditions::getEntries($character)));
  }

  /**
   * Composes one or more lines per condition: its badge, name and description, wrapped to the width.
   *
   * @param CharacterInterface $character The character.
   * @param int $width The display width in cells.
   * @return list<string> The lines; empty for a character with no conditions.
   */
  public static function composeLines(CharacterInterface $character, int $width): array
  {
    $lines = [];

    foreach (BattlerConditions::getEntries($character) as $entry) {
      $heading = TerminalMenuRow::composeIconLabel($entry['badge'], $entry['name']);
      $text = trim($entry['description']) === '' ? $heading : $heading . ': ' . TerminalText::stabilize($entry['description']);
      array_push($lines, ...TerminalText::wrapToWidth($text, max(1, $width)));
    }

    return $lines;
  }
}
