<?php

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPDamageSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\HPRecoverSkillEffect;
use Ichiloto\Engine\Entities\Effects\SkillEffects\MPDamageSkillEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\ItemScope;
use Ichiloto\Engine\Entities\Magic\MagicEffectType;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\SkillEffectContext;
use Ichiloto\Engine\Entities\Skills\SkillInvocation;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Stats;

describe('basic skills', function() {
  it('can create a Basic Skill', function() {
    $skillName = 'Attack';
    $skillDescription = 'Deals damage to the target.';
    $skillIcon = '🗡️';

    $skill = new BasicSkill(
      $skillName,
      $skillDescription,
      $skillIcon,
      0,
      1,
      new ItemScope(),
      Occasion::ALWAYS,
      new SkillInvocation('%1 attacks %2.', 0, 100, 1)
    );

    expect($skill)
      ->toBeInstanceOf(BasicSkill::class)
      ->toHaveProperties(['name', 'description', 'icon', 'cost', 'cooldown', 'scope', 'occasion', 'invocation'])
      ->and($skill->name)
      ->toBe($skillName)
      ->and($skill->description)
      ->toBe($skillDescription)
      ->and($skill->icon)
      ->toBe($skillIcon);
  });

  beforeEach(function() {
    $this->userHp = 544;
    $this->userAttack = 31;
    $this->user = new Character('User', 0, new Stats(currentHp: $this->userHp, attack: $this->userAttack));
    $this->targetHp = 544;
    $this->targetMp = 50;
    $this->targetDefence = 20;
    $this->target = new Character('Target', 0, new Stats(currentHp: $this->targetHp, currentMp: $this->targetMp, defence: $this->targetDefence));
    $this->skillEffectContext = new SkillEffectContext($this->user, $this->target);
  });

  it('can deal HP damage', function() {
    $userAttack = 31;
    $this->user->stats->attack = $userAttack;

    /** @var Character $target */
    $target = $this->target;
    $targetHp = 544;
    $targetDefence = 20;
    $target->stats->currentHp = $targetHp;
    $target->stats->defence = $targetDefence;

    $rawMagnitude = $userAttack * 4;
    $mitigationRate = $targetDefence / ($targetDefence + 240);
    $expectedDamage = intval(round($rawMagnitude * (1 - $mitigationRate), 0, PHP_ROUND_HALF_UP));

    $damageFormula = '$user->stats->attack * 4';
    $hpDamageEffect = new HPDamageSkillEffect($damageFormula, variance: 0.0);
    $hpDamageEffect->apply($this->skillEffectContext);

    expect($target->stats->currentHp)
      ->toBe($targetHp - $expectedDamage);
  });

  it('can deal MP damage', function() {
    $targetMp = 50;
    $this->skillEffectContext->target->stats->currentMp = $targetMp;
    /** @var Character $target */
    $target = $this->skillEffectContext->target;

    $expectedDamage = $target->stats->currentMp * 0.1;

    $damageFormula = '$target->stats->currentMp * .1';
    $mpDamageEffect = new MPDamageSkillEffect($damageFormula);
    $mpDamageEffect->apply($this->skillEffectContext);

    // One apply() is one roll: the formula reads currentMp, which apply()
    // just drained, so re-rolling getValue() would use shifted inputs.
    // Bounds are floored to ints to match the engine's roll.
    $minValue = intval($expectedDamage * (1 - $mpDamageEffect->variance));
    $maxValue = intval($expectedDamage * (1 + $mpDamageEffect->variance));

    expect($target->stats->currentMp)
      ->toBeBetween($targetMp - $maxValue, $targetMp - $minValue);
  });

  it('deals at least 1 HP damage when the formula yields a negative value', function() {
    /** @var Character $target */
    $target = $this->target;
    $targetHp = 544;
    $target->stats->currentHp = $targetHp;

    // A heavily-armoured target would otherwise be healed by the negative result.
    $damageFormula = '$user->stats->attack - $target->stats->defence * 10';
    $hpDamageEffect = new HPDamageSkillEffect($damageFormula);
    $hpDamageEffect->apply($this->skillEffectContext);

    expect($target->stats->currentHp)->toBeLessThan($targetHp);
  });

  it('drains at least 1 MP when the formula yields a negative value', function() {
    /** @var Character $target */
    $target = $this->target;
    $targetMp = 50;
    $target->stats->currentMp = $targetMp;

    $damageFormula = '-25';
    $mpDamageEffect = new MPDamageSkillEffect($damageFormula);
    $mpDamageEffect->apply($this->skillEffectContext);

    expect($target->stats->currentMp)->toBeLessThan($targetMp);
  });

  it('can recover HP', function() {
    $targetHp = 544;
    /** @var Character $target */
    $target = $this->skillEffectContext->target;
    // The heal must have headroom below the HP ceiling, or clamping eats it.
    $target->stats->totalHp = 2000;
    $target->stats->currentHp = $targetHp;

    $expectedRecovery = 100;

    $recoveryFormula = '100';
    $hpRecoveryEffect = new HPRecoverSkillEffect($recoveryFormula);
    $hpRecoveryEffect->apply($this->skillEffectContext);

    // One apply() is one roll; bounds floored to ints to match the engine.
    $minValue = intval($expectedRecovery * (1 - $hpRecoveryEffect->variance));
    $maxValue = intval($expectedRecovery * (1 + $hpRecoveryEffect->variance));

    expect($target->stats->currentHp)
      ->toBeBetween($targetHp + $minValue, $targetHp + $maxValue);
  });
});

describe('magic skills', function() {
  it('can create a Magic Skill', function() {
    $skillName = 'Heal';
    $skillDescription = 'Restores an ally’s HP.';
    $skillIcon = '🩹';

    $skill = new MagicSkill(
      $skillName,
      $skillDescription,
      $skillIcon,
      0,
      1,
      new ItemScope(),
      Occasion::ALWAYS,
      new SkillInvocation('%1 casts %2.', 0, 100, 1)
    );

    expect($skill)
      ->toBeInstanceOf(MagicSkill::class)
      ->toHaveProperties(['name', 'description', 'icon', 'cost', 'cooldown', 'scope', 'occasion', 'invocation'])
      ->and($skill->name)
      ->toBe($skillName)
      ->and($skill->description)
      ->toBe($skillDescription)
      ->and($skill->icon)
      ->toBe($skillIcon);
  });

  it('infers restorative magic effect types from recovery effects', function() {
    $skill = new MagicSkill(
      'Heal',
      'Restores an ally’s HP.',
      '🩹',
      4,
      0,
      new ItemScope(),
      Occasion::ALWAYS,
      effects: [
        new HPRecoverSkillEffect('25'),
      ],
    );

    expect($skill->effectType)->toBe(MagicEffectType::RESTORATIVE);
  });

  it('allows explicit magic effect types to override inferred ones', function() {
    $skill = new MagicSkill(
      'Barrier',
      'Raises a shield.',
      '🛡️',
      6,
      0,
      new ItemScope(),
      Occasion::BATTLE_SCREEN,
      effects: [
        new HPRecoverSkillEffect('20'),
      ],
      effectType: MagicEffectType::BUFF,
    );

    expect($skill->effectType)->toBe(MagicEffectType::BUFF);
  });
});

describe('special skills', function() {
  it('can create a Special Skill', function() {
    $skillName = 'Slash';
    $skillDescription = 'Attacks all enemies.';
    $skillIcon = '🔪';

    $skill = new SpecialSkill(
      $skillName,
      $skillDescription,
      $skillIcon,
      0,
      1,
      new ItemScope(
        ItemScopeSide::ENEMY,
        ItemScopeNumber::ALL,
        ItemScopeStatus::ALIVE
      ),
      Occasion::ALWAYS,
      new SkillInvocation('%1 performs %2.', 0, 100, 1)
    );

    expect($skill)
      ->toBeInstanceOf(SpecialSkill::class)
      ->toHaveProperties(['name', 'description', 'icon', 'cost', 'cooldown', 'scope', 'occasion', 'invocation'])
      ->and($skill->name)
      ->toBe($skillName)
      ->and($skill->description)
      ->toBe($skillDescription)
      ->and($skill->icon)
      ->toBe($skillIcon);
  });
});
