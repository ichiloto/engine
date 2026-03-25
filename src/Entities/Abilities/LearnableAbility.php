<?php

namespace Ichiloto\Engine\Entities\Abilities;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;

/**
 * Tracks an ability that can be learned along with its unlock requirements.
 *
 * @package Ichiloto\Engine\Entities\Abilities
 */
class LearnableAbility
{
  /**
   * @param SpecialSkill $skill The ability that can be learned.
   * @param AbilityLearningRequirement $requirement The requirement bundle for learning the ability.
   * @param bool $isLearned Whether the ability has already been learned.
   * @param string $note Optional flavor or source note.
   */
  public function __construct(
    public SpecialSkill $skill,
    public AbilityLearningRequirement $requirement = new AbilityLearningRequirement(),
    public bool $isLearned = false,
    public string $note = '',
  )
  {
  }

  /**
   * Creates a learnable ability from serialized data.
   *
   * @param array<string, mixed> $data The serialized learnable-ability data.
   * @return self|null The reconstructed learnable ability, if it exists.
   */
  public static function fromArray(array $data): ?self
  {
    $skillName = strval($data['skill'] ?? '');
    $skill = AbilityLibrary::find($skillName);

    if (! $skill instanceof SpecialSkill) {
      return null;
    }

    return new self(
      $skill,
      AbilityLearningRequirement::fromArray($data['requirement'] ?? []),
      boolval($data['isLearned'] ?? false),
      strval($data['note'] ?? '')
    );
  }

  /**
   * Converts the learnable ability to a serializable array.
   *
   * @return array<string, mixed> The serialized learnable ability.
   */
  public function toArray(): array
  {
    return [
      'skill' => $this->skill->name,
      'requirement' => $this->requirement->toArray(),
      'isLearned' => $this->isLearned,
      'note' => $this->note,
    ];
  }

  /**
   * Determines whether the ability is ready to be learned.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return bool True when the ability can be learned.
   */
  public function isReady(Character $character, Party $party, array $storyEvents = [], int $playTimeSeconds = 0): bool
  {
    return ! $this->isLearned && $this->requirement->isSatisfiedBy($character, $party, $storyEvents, $playTimeSeconds);
  }

  /**
   * Returns a short status label for list rendering.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @param string[] $storyEvents The recorded story-event flags.
   * @param int $playTimeSeconds The elapsed play time in seconds.
   * @return string The status label.
   */
  public function getStatusLabel(Character $character, Party $party, array $storyEvents = [], int $playTimeSeconds = 0): string
  {
    if ($this->isLearned) {
      return 'Learned';
    }

    return $this->isReady($character, $party, $storyEvents, $playTimeSeconds)
      ? 'Ready'
      : 'Locked';
  }
}
