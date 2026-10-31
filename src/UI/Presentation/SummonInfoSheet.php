<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Entities\Elements\ElementalAffinityTier;
use Ichiloto\Engine\Localization\Vocabulary;

/**
 * A summon's information as every menu shows it, from its definition alone:
 * Summon Info, and a Bestiary record of a summon met in battle.
 */
final class SummonInfoSheet
{
  private function __construct() {}

  /**
   * Lore reads as prose, then Move, Profile and Resistances sections. Elements are icon-list values from the
   * summon's affinity contract; the legacy strengths and weaknesses lists still read as Effective against and
   * Weaknesses until their sources are migrated.
   *
   * @return list<list<MenuDetailEntry>> The sections, some possibly empty; MenuDetailSheet omits those.
   */
  public static function createSections(SummonCutsceneDefinition $definition): array
  {
    $move = [MenuDetailEntry::createHeading(Vocabulary::getLabel('summons.info.move', 'Move'))];
    if ($definition->moveName !== null) { $move[] = MenuDetailEntry::createProse($definition->moveName); }
    if ($definition->description !== '') { $move[] = MenuDetailEntry::createProse($definition->description); }
    $effective = $definition->effectiveAgainst === null && $definition->strengths === [] ? null
      : [...$definition->effectiveAgainst ?? [], ...$definition->strengths];
    $move[] = ElementalAffinityDetails::createEffectiveEntry($effective);
    $profile = [];
    if ($definition->element !== '') {
      $profile[] = MenuDetailEntry::createIcons(Vocabulary::getLabel('summons.info.element', 'Element'),
        ElementalAffinityDetails::createIcons([$definition->element]));
    }
    foreach ($definition->attributes as $label => $value) {
      if (!is_scalar($value) || trim((string)$value) === '' || trim((string)$label) === '') { continue; }
      $profile[] = is_int($value) || is_float($value)
        ? MenuDetailEntry::createNumber((string)$label, (string)$value)
        : MenuDetailEntry::createField((string)$label, (string)$value);
    }
    // The summary names the summon; the body leads with its lore.
    return [
      $definition->lore !== '' ? [MenuDetailEntry::createProse($definition->lore)] : [],
      $move,
      $profile === [] ? [] : [MenuDetailEntry::createHeading(Vocabulary::getLabel('summons.info.profile', 'Profile')), ...$profile],
      [MenuDetailEntry::createHeading(Vocabulary::getLabel('affinities.resistances', 'Resistances')),
        ...ElementalAffinityDetails::createResistanceEntries($definition->elementAffinities,
          [ElementalAffinityTier::WEAKNESS->value => $definition->weaknesses])],
    ];
  }
}
