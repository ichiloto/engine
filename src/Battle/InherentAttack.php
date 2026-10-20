<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\Resolution\CombatRandomSource;
use Ichiloto\Engine\Battle\Resolution\CombatResolver;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use InvalidArgumentException;

/**
 * Owns a battler's inherent attack: the action it takes when it simply attacks.
 *
 * A definition that authors `attackSkill` attacks with that catalogued
 * BasicSkill; one that does not uses the built-in AttackAction. Every path
 * that makes a battler attack (the enemy fallback, the battle simulator and
 * its previews) builds the action here, so balance tools measure the attack
 * the game plays.
 *
 * @package Ichiloto\Engine\Battle
 */
final class InherentAttack
{
  /** The built-in attack's action name. */
  public const string BUILT_IN_NAME = 'Attack';

  /**
   * Resolves an authored `attackSkill` reference.
   *
   * @param mixed $reference The authored skill name, or null for the built-in attack.
   * @param SkillCatalog $skills The catalogue the reference names a skill in.
   * @param string $owner Who authors it, for messages ("actor", "enemy").
   * @return BasicSkill|null The skill, or null when none is authored.
   * @throws InvalidArgumentException If the reference is malformed or names no battle-usable BasicSkill.
   */
  public static function resolveSkill(mixed $reference, SkillCatalog $skills, string $owner): ?BasicSkill
  {
    if ($reference === null) {
      return null;
    }
    if (! is_string($reference) || trim($reference) === '') {
      throw new InvalidArgumentException(sprintf('The %s attackSkill must be a non-empty skill reference.', $owner));
    }
    $skill = $skills->findSkill($reference);
    if (! $skill instanceof BasicSkill) {
      throw new InvalidArgumentException(sprintf(
        'The %s attackSkill "%s" must reference a catalogued BasicSkill.', $owner, $reference));
    }

    return self::requireBattleUsable($skill, $owner);
  }

  /**
   * Refuses an inherent attack skill that cannot be used in battle.
   *
   * @param BasicSkill|null $skill The skill.
   * @param string $owner Who authors it, for messages.
   * @return BasicSkill|null The same skill.
   * @throws InvalidArgumentException If the skill is not usable in battle.
   */
  public static function requireBattleUsable(?BasicSkill $skill, string $owner): ?BasicSkill
  {
    if ($skill !== null && ! in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)) {
      throw new InvalidArgumentException(sprintf('The %s attackSkill must be usable in battle.', $owner));
    }

    return $skill;
  }

  /**
   * Builds the action a battler takes when it attacks.
   *
   * @param CharacterInterface $battler The attacking battler.
   * @param CombatResolver|null $resolver The resolver the action resolves hits with.
   * @param CombatRandomSource|null $random The random source the action rolls with.
   * @return BattleAction The authored attack skill's action, or the built-in attack.
   */
  public static function createAction(
    CharacterInterface $battler,
    ?CombatResolver $resolver = null,
    ?CombatRandomSource $random = null,
  ): BattleAction
  {
    $skill = $battler instanceof InherentAttacker ? $battler->attackSkill : null;

    return $skill === null
      ? new AttackAction(self::BUILT_IN_NAME, $resolver, $random)
      : new SkillBattleAction($skill, $resolver, $random);
  }
}
