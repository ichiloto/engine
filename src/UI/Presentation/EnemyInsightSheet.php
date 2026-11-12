<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Battle\Insight\ElementalInsight;
use Ichiloto\Engine\Battle\Insight\RevealFact;
use Ichiloto\Engine\Battle\Presentation\BattlerConditions;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Localization\Vocabulary;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeProgressService;
use Ichiloto\Engine\Progress\Knowledge\KnowledgeSubject;

/**
 * What battles have revealed about a kind of foe, as its record shows it:
 * only the facts a reveal has recorded for the record's subject, read from
 * each enemy the subject records as it is authored now, so a rebalanced
 * enemy reads its new values.
 */
final class EnemyInsightSheet
{
  private const array STATS = ['attack', 'defence', 'magicAttack', 'magicDefence', 'speed', 'grace', 'evasion'];

  private function __construct() {}

  /**
   * @param list<Enemy> $enemies The enemies the subject is the record of, as authored.
   * @return list<list<MenuDetailEntry>> One section per enemy; none while nothing is revealed.
   */
  public static function createSections(KnowledgeProgressService $knowledge, KnowledgeSubject $subject, array $enemies): array
  {
    $known = array_values(array_filter(RevealFact::cases(),
      static fn(RevealFact $fact): bool => $knowledge->isFactRecorded($subject->id, $fact)));
    if ($known === [] || $enemies === []) {
      return [];
    }
    $sections = [];
    foreach ($enemies as $enemy) {
      $heading = count($enemies) > 1 ? $enemy->name : Vocabulary::getLabel('records.analysis', Vocabulary::DEFAULTS['records.analysis']);
      $entries = [MenuDetailEntry::createHeading($heading)];
      if (in_array(RevealFact::HP, $known, true)) {
        $entries[] = MenuDetailEntry::createNumber(Vocabulary::getTerm('stats.hp'), (string)$enemy->stats->totalHp);
      }
      if (in_array(RevealFact::MP, $known, true)) {
        $entries[] = MenuDetailEntry::createNumber(Vocabulary::getTerm('stats.mp'), (string)$enemy->stats->totalMp);
      }
      if (in_array(RevealFact::STATS, $known, true)) {
        foreach (self::STATS as $stat) {
          $entries[] = MenuDetailEntry::createNumber(BattlerConditions::getStatLabel($stat), (string)$enemy->stats->{$stat});
        }
      }
      if (in_array(RevealFact::AFFINITIES, $known, true)) {
        $elements = ElementalInsight::findStrongestElements($enemy);
        $entries[] = $elements === []
          ? MenuDetailEntry::createProse(Vocabulary::getLabel('battle.insight.none', Vocabulary::DEFAULTS['battle.insight.none']))
          : MenuDetailEntry::createIcons(Vocabulary::getLabel('battle.insight.best', Vocabulary::DEFAULTS['battle.insight.best']),
            ElementalAffinityDetails::createIcons($elements));
      }
      $sections[] = $entries;
    }

    return $sections;
  }
}
