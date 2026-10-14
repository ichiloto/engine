<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Entities\Enumerations\ItemScopeNumber;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use InvalidArgumentException;

/** An explicit grant, not a chance inferred from stats or catalogue membership. */
final readonly class CounterAttackRule
{
  public function __construct(public string $skill)
  {
    if (trim($skill) === '' || $skill !== trim($skill)) {
      throw new InvalidArgumentException('counterAttack.skill must be a non-empty catalogue reference without surrounding whitespace.');
    }
  }

  public static function fromArray(mixed $data): ?self
  {
    if ($data === null) { return null; }
    if (!is_array($data) || array_keys($data) !== ['skill'] || !is_string($data['skill'])) {
      throw new InvalidArgumentException('counterAttack must be null or [skill => catalogue reference].');
    }
    return new self($data['skill']);
  }

  /** @return array{skill: string} */
  public function toArray(): array
  {
    return ['skill' => $this->skill];
  }

  /** Used by runtime and authoring to reject unsupported responses before execution. */
  public function resolveSkill(SkillCatalog $catalog): Skill
  {
    $skill = $catalog->findSkill($this->skill);
    if ((!$skill instanceof BasicSkill && !$skill instanceof SpecialSkill)
      || !in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
      || $skill->scope->side !== ItemScopeSide::ENEMY
      || $skill->scope->number !== ItemScopeNumber::ONE
      || $skill->scope->status !== ItemScopeStatus::ALIVE
      || ($skill->scope->targetCount !== null && $skill->scope->targetCount !== 1)
      || $skill->cost < 0 || $skill->requiredWeapons !== []
      || $catalog->isSummonAction($skill->name)) {
      throw new InvalidArgumentException(sprintf(
        'counterAttack.skill "%s" must reference a battle-usable basic or special skill targeting one living opponent, without summon or required-weapon conditions.',
        $this->skill));
    }
    return $skill;
  }
}
