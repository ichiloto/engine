<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\BattlerBattleView;
use Ichiloto\Engine\Battle\Insight\ElementalInsight;
use Ichiloto\Engine\Battle\Insight\RevealableBattler;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Localization\Vocabulary;

/**
 * What the player knows about a foe, as the battle shows it: a current
 * projection of the facts revealed about it, read from the battler as it
 * is now, never a copy. Both renderers show the same text: the HP over a
 * targeted foe, and the Info line with its HP, MP, stats and best element.
 *
 * @package Ichiloto\Engine\Battle\Presentation
 */
final class BattlerInsight
{
  /** The combat stats a stats reveal shows, in the order shown. */
  private const array STATS = ['attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'];

  private function __construct() {}

  /** The HP shown over a targeted foe ("HP 34/80"), or null while its HP is unknown. */
  public static function getTargetLine(CharacterInterface $battler): ?string
  {
    if (! $battler instanceof RevealableBattler || ! $battler->isFactRevealed(RevealFact::HP)) {
      return null;
    }

    return self::formatPool('stats.hp', $battler->stats->currentHp, $battler->stats->totalHp);
  }

  /**
   * Everything known about a foe on one line, its name first; '' while nothing is.
   * "Brush Wolf  HP 34/80  MP 0/10  ATK 12  DEF 8 ... Best element: Fire"
   */
  public static function getInfo(CharacterInterface $battler): string
  {
    if (! $battler instanceof RevealableBattler) {
      return '';
    }
    $parts = [];
    if ($battler->isFactRevealed(RevealFact::HP)) {
      $parts[] = self::formatPool('stats.hp', $battler->stats->currentHp, $battler->stats->totalHp);
    }
    if ($battler->isFactRevealed(RevealFact::MP)) {
      $parts[] = self::formatPool('stats.mp', $battler->stats->currentMp, $battler->stats->totalMp);
    }
    if ($battler->isFactRevealed(RevealFact::STATS)) {
      // The values a fight uses now: equipment and stage changes included.
      $view = new BattlerBattleView($battler);
      foreach (self::STATS as $stat) {
        $parts[] = BattlerConditions::getStatLabel($stat) . ' ' . $view->stats->{$stat};
      }
    }
    if ($battler->isFactRevealed(RevealFact::AFFINITIES)) {
      $parts[] = self::formatBestElement(ElementalInsight::findStrongestElements($battler));
    }

    return $parts === [] ? '' : implode('  ', [$battler->name, ...$parts]);
  }

  /**
   * The insight line for the strongest elements: "Best element: Fire", or that none beats ordinary damage.
   *
   * @param list<string> $elements The strongest elements, empty when none beats ordinary damage.
   */
  public static function formatBestElement(array $elements): string
  {
    return $elements === []
      ? Vocabulary::getLabel('battle.insight.none', Vocabulary::DEFAULTS['battle.insight.none'])
      : Vocabulary::getLabel('battle.insight.best', Vocabulary::DEFAULTS['battle.insight.best']) . ': ' . implode(', ', $elements);
  }

  private static function formatPool(string $term, int $current, int $total): string
  {
    return sprintf('%s %d/%d', Vocabulary::getTerm($term), $current, $total);
  }
}
