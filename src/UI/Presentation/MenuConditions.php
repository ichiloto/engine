<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Sprites\PngAssetPreflight;

/**
 * A character's current conditions in graphical menus, from the same
 * projection battle uses. Summaries (party cards, item targets) show compact
 * badges: the theme's `status.state.<id>` icon, or the state's label in its
 * polarity colour without one. The Status menu lists each condition as a row
 * with its icon, name and description.
 */
final class MenuConditions
{
  private function __construct() {}

  /**
   * Builds one record per condition.
   *
   * @param CharacterInterface $character The character.
   * @param bool $describe True to carry each description as the row's value.
   * @return list<MenuRow> The rows; empty for a character with no conditions.
   */
  public static function getRows(CharacterInterface $character, bool $describe): array
  {
    return array_map(static fn(array $entry): MenuRow => new MenuRow(
      'condition-' . hash('xxh3', $entry['key']),
      $entry['name'],
      $describe ? [new MenuRowValue($entry['description'])] : [],
      icon: $entry['iconKey'],
    ), BattlerConditions::getEntries($character));
  }

  /**
   * Draws compact condition badges left to right, wrapping within the bounds.
   * Badges that cannot fit are counted in a final "+N" so none is hidden silently.
   *
   * @param MenuCanvas $view The canvas.
   * @param string $id The stable id prefix.
   * @param CharacterInterface $character The character.
   * @param CanvasRectangle $bounds Where the badges may go.
   * @return void
   */
  public static function renderBadges(MenuCanvas $view, string $id, CharacterInterface $character, CanvasRectangle $bounds): void
  {
    $entries = BattlerConditions::getEntries($character);

    if ($entries === []) {
      return;
    }

    $m = $view->theme->metrics;
    $size = self::getBadgeHeight($view->theme);
    $gap = $m->sectionGap;
    $right = $bounds->x + $bounds->width;
    $bottom = $bounds->y + $bounds->height;
    $placed = [];
    $x = $bounds->x;
    $y = $bounds->y;

    foreach ($entries as $entry) {
      $width = self::getBadgeWidth($view, $entry, $size);
      if ($x > $bounds->x && $x + $width > $right) {
        $x = $bounds->x;
        $y += $size + $gap;
      }
      if ($y + $size > $bottom) {
        break;
      }
      $placed[] = [$entry, new CanvasRectangle($x, $y, $width, $size)];
      $x += $width + $gap;
    }

    // Badges that cannot fit are counted, never hidden: drop badges until the count fits after the last one.
    $more = null;
    while (count($placed) < count($entries) && $placed !== []) {
      $text = '+' . (count($entries) - count($placed));
      [, $last] = $placed[array_key_last($placed)];
      $width = mb_strlen($text, 'UTF-8') * $m->cellWidth;
      if ($last->x + $last->width + $gap + $width <= $right) {
        $more = [$text, new CanvasRectangle($last->x + $last->width + $gap, $last->y + ($size - $m->cellHeight) / 2, $width, $m->cellHeight)];
        break;
      }
      array_pop($placed);
    }

    foreach ($placed as [$entry, $box]) {
      $badgeId = $id . '-' . hash('xxh3', $entry['key']);
      if (! $view->icon($badgeId, $entry['iconKey'], $box)) {
        $view->textLines($badgeId, [['text' => $entry['label'], 'color' => self::getPolarityColor($entry['polarity'])]],
          new CanvasRectangle($box->x, $box->y + ($size - $m->cellHeight) / 2, $box->width, $m->cellHeight));
      }
    }

    if ($more !== null) {
      $view->textLines($id . '-more', [['text' => $more[0], 'color' => 'disabled']], $more[1]);
    }
  }

  /** The height one line of badges takes. */
  public static function getBadgeHeight(MenuPresentationCatalog $theme): int
  {
    return max($theme->metrics->cellHeight, (int)ceil($theme->rows->metrics->iconHeight));
  }

  /** @param array{iconKey: string, label: string} $entry */
  private static function getBadgeWidth(MenuCanvas $view, array $entry, int $size): float
  {
    // Only the condition's own art; a theme's generic fallback icon would hide which condition it is.
    $asset = $view->theme->icons?->icons[$entry['iconKey']] ?? null;
    return $asset !== null && PngAssetPreflight::getAvailableSize($view->theme->assetRoot, $asset) !== null
      ? $size
      : mb_strlen($entry['label'], 'UTF-8') * $view->theme->metrics->cellWidth;
  }

  private static function getPolarityColor(string $polarity): string
  {
    return match ($polarity) {
      'positive' => 'increase',
      'negative' => 'decrease',
      default => 'text',
    };
  }
}
