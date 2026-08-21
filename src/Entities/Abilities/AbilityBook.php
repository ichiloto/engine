<?php

namespace Ichiloto\Engine\Entities\Abilities;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use InvalidArgumentException;

/**
 * Stores a character's learned and learnable special abilities.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
class AbilityBook
{
  /**
   * The currently learned abilities.
   *
   * Any non-magic skill may be filed here; magic lives in the spellbook. The
   * accessors below are typed to match, so no skill kind can break
   * serialization.
   *
   * @var Skill[]
   */
  protected array $learnedAbilities = [];
  /**
   * @var LearnableAbility[] The known learnable abilities.
   */
  protected array $learnableAbilities = [];

  /**
   * @param Skill[] $learnedAbilities The learned abilities.
   * @param LearnableAbility[] $learnableAbilities The learnable abilities.
   * @param AbilitySortOrder $sortOrder The learned-ability sort order.
   */
  public function __construct(
    array $learnedAbilities = [],
    array $learnableAbilities = [],
    protected AbilitySortOrder $sortOrder = AbilitySortOrder::A_TO_Z,
  )
  {
    foreach ($learnedAbilities as $ability) {
      if ($ability instanceof Skill) {
        $this->guardAgainstMagic($ability);
        $this->learnedAbilities[$ability->name] = $ability;
      }
    }

    foreach ($learnableAbilities as $learnableAbility) {
      if ($learnableAbility instanceof LearnableAbility) {
        $this->learnableAbilities[$learnableAbility->skill->name] = $learnableAbility;
      }
    }

    $this->sortLearnedAbilities();
  }

  /**
   * Creates an ability book instance from serialized data.
   *
   * @param array<string, mixed> $data The serialized ability-book data.
   * @return self The reconstructed ability book.
   */
  public static function fromArray(array $data): self
  {
    $learnedAbilities = [];

    foreach ($data['learned'] ?? [] as $abilityName) {
      $ability = AbilityLibrary::find(strval($abilityName));

      if ($ability instanceof SpecialSkill) {
        $learnedAbilities[] = $ability;
      }
    }

    $learnableAbilities = array_values(array_filter(array_map(
      static fn(array $abilityData): ?LearnableAbility => LearnableAbility::fromArray($abilityData),
      array_filter($data['learnables'] ?? [], 'is_array')
    )));

    $sortOrder = AbilitySortOrder::tryFrom(strval($data['sortOrder'] ?? AbilitySortOrder::A_TO_Z->value))
      ?? AbilitySortOrder::A_TO_Z;

    return new self($learnedAbilities, $learnableAbilities, $sortOrder);
  }

  /**
   * Converts the ability book to a serializable array.
   *
   * @return array<string, mixed> The serialized ability book.
   */
  public function toArray(): array
  {
    return [
      'learned' => array_map(
        static fn(Skill $ability): string => $ability->name,
        $this->getLearnedAbilities()
      ),
      'learnables' => array_map(
        static fn(LearnableAbility $ability): array => $ability->toArray(),
        $this->getLearnableAbilities()
      ),
      'sortOrder' => $this->sortOrder->value,
    ];
  }

  /**
   * Returns the learned abilities in the current sort order.
   *
   * @return Skill[] The learned abilities.
   */
  public function getLearnedAbilities(): array
  {
    return array_values($this->learnedAbilities);
  }

  /**
   * Returns the known learnable abilities.
   *
   * @return LearnableAbility[] The learnable abilities.
   */
  public function getLearnableAbilities(): array
  {
    return array_values($this->learnableAbilities);
  }

  /**
   * Returns the subset of learned abilities that can be used in battle.
   *
   * @return Skill[] The battle-usable abilities.
   */
  public function getBattleUsableAbilities(): array
  {
    return array_values(array_filter(
      $this->getLearnedAbilities(),
      static fn(Skill $ability): bool => in_array($ability->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
    ));
  }

  /**
   * Adds a learned ability to the ability book.
   *
   * Magic belongs in the character's spellbook, not here — the two are
   * stored, serialized and surfaced separately. Use Character::learnSkill()
   * to file a skill without having to know which book owns it.
   *
   * @param Skill $ability The ability to add.
   * @return void
   * @throws InvalidArgumentException If a magic skill is filed as an ability.
   */
  public function addLearnedAbility(Skill $ability): void
  {
    $this->guardAgainstMagic($ability);

    $this->learnedAbilities[$ability->name] = $ability;
    $this->sortLearnedAbilities();
  }

  /**
   * Rejects magic skills, which belong in the character's spellbook.
   *
   * Accepting one here silently corrupts the book: it cannot be serialized,
   * and it would be dropped on load and never surface in the magic menu.
   *
   * @param Skill $ability The skill being filed.
   * @return void
   * @throws InvalidArgumentException If the skill is a magic skill.
   */
  protected function guardAgainstMagic(Skill $ability): void
  {
    if ($ability instanceof MagicSkill) {
      throw new InvalidArgumentException(sprintf(
        'The magic skill "%s" belongs in the spellbook, not the ability book. Use Character::learnSkill().',
        $ability->name
      ));
    }
  }

  /**
   * Sorts the learned abilities according to the selected order.
   *
   * @param AbilitySortOrder|null $sortOrder The sort order to apply.
   * @return void
   */
  public function sortLearnedAbilities(?AbilitySortOrder $sortOrder = null): void
  {
    $this->sortOrder = $sortOrder ?? $this->sortOrder;
    $abilities = $this->learnedAbilities;

    uasort($abilities, static function(Skill $left, Skill $right): int {
      return strcmp($left->name, $right->name);
    });

    if ($this->sortOrder === AbilitySortOrder::Z_TO_A) {
      $abilities = array_reverse($abilities, true);
    }

    $this->learnedAbilities = $abilities;
  }

  /**
   * Returns the current learned-ability sort order.
   *
   * @return AbilitySortOrder The active sort order.
   */
  public function getSortOrder(): AbilitySortOrder
  {
    return $this->sortOrder;
  }

  /**
   * Returns the number of ready-to-learn abilities for the specified character.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return int The number of ready abilities.
   */
  public function getReadyToLearnCount(Character $character, Party $party, array $storyEvents = [], int $playTimeSeconds = 0): int
  {
    return count(array_filter(
      $this->getLearnableAbilities(),
      static fn(LearnableAbility $ability): bool => $ability->isReady($character, $party, $storyEvents, $playTimeSeconds)
    ));
  }

  /**
   * Attempts to learn the provided ability, consuming the relevant shared costs.
   *
   * @param LearnableAbility $learnableAbility The ability to learn.
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return bool True when the ability was learned.
   */
  /**
   * Learns a skill outright, bypassing ability requirements.
   *
   * The level-up path: role `skillsToLearn` grants land here the moment
   * their level is reached.
   *
   * @param Skill $skill The skill to learn.
   * @return bool True when newly learned; false when already known.
   */
  public function learnSkillDirectly(Skill $skill): bool
  {
    $this->guardAgainstMagic($skill);

    if (isset($this->learnedAbilities[$skill->name])) {
      return false;
    }

    $this->learnedAbilities[$skill->name] = $skill;
    $this->sortLearnedAbilities();

    return true;
  }

  public function learn(
    LearnableAbility $learnableAbility,
    Character $character,
    Party $party,
    array $storyEvents = [],
    int $playTimeSeconds = 0
  ): bool
  {
    $registeredAbility = $this->learnableAbilities[$learnableAbility->skill->name] ?? null;

    if (
      ! $registeredAbility instanceof LearnableAbility
      || ! $registeredAbility->isReady($character, $party, $storyEvents, $playTimeSeconds)
    ) {
      return false;
    }

    $registeredAbility->requirement->consumeCosts($party);
    $registeredAbility->isLearned = true;
    $this->learnedAbilities[$registeredAbility->skill->name] = $registeredAbility->skill;
    $this->sortLearnedAbilities();

    return true;
  }
}
