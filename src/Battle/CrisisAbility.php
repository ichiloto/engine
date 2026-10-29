<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use InvalidArgumentException;

/** Project-owned crisis activation and form-dependent techniques. Live HP and states own availability. */
final readonly class CrisisAbility
{
  /**
   * @param list<string> $states Any of these states also activates the ability.
   * @param list<array{skill: string, conditions: list<array<string, mixed>>}> $variants
   */
  private function __construct(
    public string $name,
    public string $label,
    public string $description,
    public ?float $hpBelowPercent,
    public array $states,
    public array $variants,
  ) {}

  public static function fromArray(mixed $data): ?self
  {
    if ($data === null) { return null; }
    if (!is_array($data)) { throw new InvalidArgumentException('crisisAbility must be an array.'); }
    foreach (array_keys($data) as $key) {
      if (!in_array($key, ['name', 'label', 'description', 'hpBelowPercent', 'states', 'variants'], true)) {
        throw new InvalidArgumentException(sprintf('Unknown crisisAbility field "%s".', $key));
      }
    }
    $name = self::requireReference($data['name'] ?? null, 'name');
    $label = $data['label'] ?? mb_substr(mb_strtoupper($name), 0, 3);
    $description = $data['description'] ?? '';
    if (!is_string($label) || trim($label) === '' || !is_string($description)) {
      throw new InvalidArgumentException('crisisAbility label must be nonempty text and description must be text.');
    }
    $threshold = $data['hpBelowPercent'] ?? null;
    if ($threshold !== null && ((!is_int($threshold) && !is_float($threshold))
      || !is_finite((float) $threshold) || $threshold <= 0 || $threshold > 100)) {
      throw new InvalidArgumentException('crisisAbility.hpBelowPercent must be greater than 0 and at most 100.');
    }
    $states = $data['states'] ?? [];
    if (!is_array($states) || !array_is_list($states)) {
      throw new InvalidArgumentException('crisisAbility.states must be a list of state references.');
    }
    foreach ($states as $state) { self::requireReference($state, 'states'); }
    if (count(array_unique($states)) !== count($states) || ($threshold === null && $states === [])) {
      throw new InvalidArgumentException('crisisAbility requires an activation condition and unique state references.');
    }
    $variants = $data['variants'] ?? null;
    if (!is_array($variants) || !array_is_list($variants) || $variants === []) {
      throw new InvalidArgumentException('crisisAbility.variants must be a nonempty list.');
    }
    $normalized = [];
    foreach ($variants as $variant) {
      if (!is_array($variant) || array_diff(array_keys($variant), ['skill', 'conditions']) !== []) {
        throw new InvalidArgumentException('Each crisisAbility variant contains a skill and optional world conditions.');
      }
      $skill = self::requireReference($variant['skill'] ?? null, 'variants.skill');
      $conditions = $variant['conditions'] ?? [];
      if (!is_array($conditions) || !array_is_list($conditions)) {
        throw new InvalidArgumentException('crisisAbility variant conditions must be a list.');
      }
      WorldConditionEvaluator::validateAll($conditions, 'crisisAbility variant conditions');
      $normalized[] = ['skill' => $skill, 'conditions' => $conditions];
    }
    return new self($name, trim($label), $description, $threshold === null ? null : (float) $threshold, $states, $normalized);
  }

  public function isActive(Character $actor): bool
  {
    if (!$actor->isFighting) { return false; }
    if ($this->hpBelowPercent !== null && $actor->stats->totalHp > 0
      && $actor->stats->currentHp * 100 < $actor->stats->totalHp * $this->hpBelowPercent) { return true; }
    foreach ($this->states as $state) {
      if ($actor->hasState($state)) { return true; }
    }
    return false;
  }

  /** Resolve current form through the shared world predicates; overlapping forms are an authoring error. */
  public function getAvailableSkill(Character $actor, ?GameState $gameState = null, ?Party $party = null,
    ?SkillCatalog $catalog = null): ?Skill
  {
    if (!$this->isActive($actor)) { return null; }
    $selected = null;
    foreach ($this->variants as $variant) {
      if ($variant['conditions'] !== [] && ($gameState === null
        || !WorldConditionEvaluator::allHold($variant['conditions'], $gameState, $party))) { continue; }
      if ($selected !== null) {
        throw new InvalidArgumentException(sprintf('Actor "%s" has overlapping %s variants.', $actor->actorId, $this->name));
      }
      $selected = $variant['skill'];
    }
    return $selected === null ? null : $this->resolveSkill($selected, $catalog ?? SkillCatalog::getProjectCatalog());
  }

  /** Used by authoring validation before any battle state changes. */
  public function validateSkills(SkillCatalog $catalog): void
  {
    foreach ($this->variants as $variant) { $this->resolveSkill($variant['skill'], $catalog); }
  }

  /** @return array<string, mixed> */
  public function toArray(): array
  {
    return ['name' => $this->name, 'label' => $this->label, 'description' => $this->description,
      ...($this->hpBelowPercent === null ? [] : ['hpBelowPercent' => $this->hpBelowPercent]),
      'states' => $this->states, 'variants' => $this->variants];
  }

  private function resolveSkill(string $reference, SkillCatalog $catalog): Skill
  {
    $skill = $catalog->findSkill($reference);
    if ((!$skill instanceof BasicSkill && !$skill instanceof SpecialSkill)
      || !in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
      || $catalog->isSummonAction($reference)) {
      throw new InvalidArgumentException(sprintf('crisisAbility skill "%s" must reference a battle-usable basic or special skill.', $reference));
    }
    return $skill;
  }

  private static function requireReference(mixed $value, string $field): string
  {
    if (!is_string($value) || trim($value) === '' || trim($value) !== $value) {
      throw new InvalidArgumentException(sprintf('crisisAbility.%s requires a nonempty reference without surrounding whitespace.', $field));
    }
    return $value;
  }
}
