<?php

namespace Ichiloto\Engine\Entities\Magic;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;

/**
 * Tracks a spell that can be learned along with its requirement progress.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
class LearnableSpell
{
  /**
   * @param MagicSkill $skill The spell that can be learned.
   * @param SpellLearningRequirement $requirement The requirement bundle for learning the spell.
   * @param int $trainingHours The accumulated training progress.
   * @param bool $isLearned Whether the spell has already been learned.
   * @param string $note Optional flavor or source note.
   */
  public function __construct(
    public MagicSkill $skill,
    public SpellLearningRequirement $requirement = new SpellLearningRequirement(),
    public int $trainingHours = 0,
    public bool $isLearned = false,
    public string $note = '',
  )
  {
  }

  /**
   * Creates a learnable spell from serialized data.
   *
   * @param array<string, mixed> $data The serialized learnable-spell data.
   * @return self|null The reconstructed learnable spell, if the spell exists.
   */
  public static function fromArray(array $data): ?self
  {
    $skillName = strval($data['skill'] ?? '');
    $skill = MagicLibrary::find($skillName);

    if (! $skill instanceof MagicSkill) {
      return null;
    }

    return new self(
      $skill,
      SpellLearningRequirement::fromArray($data['requirement'] ?? []),
      intval($data['trainingHours'] ?? 0),
      boolval($data['isLearned'] ?? false),
      strval($data['note'] ?? '')
    );
  }

  /**
   * Converts the learnable spell to a serializable array.
   *
   * @return array<string, mixed> The serialized learnable spell.
   */
  public function toArray(): array
  {
    return [
      'skill' => $this->skill->name,
      'requirement' => $this->requirement->toArray(),
      'trainingHours' => $this->trainingHours,
      'isLearned' => $this->isLearned,
      'note' => $this->note,
    ];
  }

  /**
   * Determines whether the spell is ready to be learned.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @return bool True when the spell can be learned.
   */
  public function isReady(Character $character, Party $party): bool
  {
    return ! $this->isLearned && $this->requirement->isSatisfiedBy($character, $party, $this->trainingHours);
  }

  /**
   * Returns a short status label for list rendering.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @return string The status label.
   */
  public function getStatusLabel(Character $character, Party $party): string
  {
    if ($this->isLearned) {
      return 'Learned';
    }

    return $this->isReady($character, $party) ? 'Ready' : 'In Progress';
  }
}
