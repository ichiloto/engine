<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Elements\ElementalAffinityTier;
use Ichiloto\Engine\Entities\Elements\ElementRegistry;
use Ichiloto\Engine\Localization\Vocabulary;
use InvalidArgumentException;

/**
 * Detail rows for elemental affinities, shared by every Assess-style reading view (summons now, enemies later).
 * Elements are icon-list values: themes bind `element.<identity>` icons; Terminal and unbound icons show names.
 */
final class ElementalAffinityDetails
{
  public const string ICON_PREFIX = 'element.';
  private const array TIER_LABELS = [
    'weakness' => 'Weaknesses',
    'lesser_resistance' => 'Lesser Resistances',
    'greater_resistance' => 'Greater Resistances',
    'immunity' => 'Immunities',
    'absorption' => 'Absorbed Elements',
  ];

  public static function getIconRole(string $element): string
  {
    return self::ICON_PREFIX . strtolower(trim($element));
  }

  /**
   * Known identities use their canonical project spelling; any other authored name is shown as written.
   *
   * @param list<string> $elements
   * @return list<MenuDetailIcon>
   */
  public static function createIcons(array $elements): array
  {
    $icons = [];
    foreach ($elements as $element) {
      try { $identity = ElementRegistry::canonicalize($element) ?? ''; }
      catch (InvalidArgumentException) { $identity = trim($element); }
      if ($identity !== '' && !isset($icons[strtolower($identity)])) {
        $icons[strtolower($identity)] = new MenuDetailIcon(self::getIconRole($identity), $identity);
      }
    }
    return array_values($icons);
  }

  /**
   * One row per defensive tier, every tier present: an empty row states that none applies.
   *
   * @param array<string, float> $affinities Canonical identities to multipliers.
   * @param array<string, list<string>> $additions Further identities by tier value, such as legacy authored lists.
   * @return list<MenuDetailEntry>
   */
  public static function createResistanceEntries(array $affinities, array $additions = []): array
  {
    $entries = [];
    foreach (ElementalAffinityTier::group($affinities) as $tier => $elements) {
      $entries[] = MenuDetailEntry::createIcons(Vocabulary::getLabel('affinities.' . $tier, self::TIER_LABELS[$tier]),
        self::createIcons([...$elements, ...($additions[$tier] ?? [])]));
    }
    return $entries;
  }

  /** @param list<string> $elements */
  public static function createEffectiveEntry(array $elements): MenuDetailEntry
  {
    return MenuDetailEntry::createIcons(Vocabulary::getLabel('affinities.effective', 'Effective against'), self::createIcons($elements));
  }
}
