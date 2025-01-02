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

    $expectedDamage = $userAttack * 4 - $targetDefence * 2;

    $damageFormula = '$user->stats->attack * 4 - $target->stats->defence * 2';
    $hpDamageEffect = new HPDamageSkillEffect($damageFormula);
    $hpDamageEffect->apply($this->skillEffectContext);

    $minMultiplier = 1 - $hpDamageEffect->variance;
    $maxMultiplier = 1 + $hpDamageEffect->variance;
    $minValue = $expectedDamage * $minMultiplier;
    $maxValue = $expectedDamage * $maxMultiplier;
    $minHp = $targetHp - $maxValue;
    $maxHp = $targetHp - $minValue;

    expect($hpDamageEffect->getValue($this->skillEffectContext))
      ->toBeBetween($minValue, $maxValue)
      ->and($target->stats->currentHp)
      ->toBeBetween($minHp, $maxHp);
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

    $minMultiplier = 1 - $mpDamageEffect->variance;
    $maxMultiplier = 1 + $mpDamageEffect->variance;
    $minValue = $expectedDamage * $minMultiplier;
    $maxValue = $expectedDamage * $maxMultiplier;
    $minMp = $targetMp - $maxValue;
    $maxMp = $targetMp - $minValue;

    $actualDamageValue = $mpDamageEffect->getValue($this->skillEffectContext);
    $actualTargetMp = $target->stats->currentMp;
    expect($actualDamageValue)
      ->toBeBetween($minValue, $maxValue)
      ->and($actualTargetMp)
      ->toBeBetween($minMp, $maxMp);
  });

  it('can recover HP', function() {
    $targetHp = 544;
    $this->skillEffectContext->target->stats->currentHp = $targetHp;
    /** @var Character $target */
    $target = $this->skillEffectContext->target;

    $expectedRecovery = 100;

    $recoveryFormula = '100';
    $hpRecoveryEffect = new HPRecoverSkillEffect($recoveryFormula);
    $hpRecoveryEffect->apply($this->skillEffectContext);

    $minMultiplier = 1 - $hpRecoveryEffect->variance;
    $maxMultiplier = 1 + $hpRecoveryEffect->variance;
    $minValue = $expectedRecovery * $minMultiplier;
    $maxValue = $expectedRecovery * $maxMultiplier;
    $minHp = $targetHp + $minValue;
    $maxHp = $targetHp + $maxValue;

    $actualRecoveryValue = $hpRecoveryEffect->getValue($this->skillEffectContext);
    $actualTargetHp = $target->stats->currentHp;
    expect($actualRecoveryValue)
      ->toBeBetween($minValue, $maxValue)
      ->and($actualTargetHp)
      ->toBeBetween($minHp, $maxHp);
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