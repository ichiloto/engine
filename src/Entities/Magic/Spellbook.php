<?php

namespace Ichiloto\Engine\Entities\Magic;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\MagicSkill;

/**
 * Stores a character's learned and learnable magic.
 *
 * @package Ichiloto\Engine\Entities\Magic
 */
class Spellbook
{
  /**
   * @var MagicSkill[] The currently learned spells.
   */
  protected array $learnedSpells = [];
  /**
   * @var LearnableSpell[] The known learnable spells.
   */
  protected array $learnableSpells = [];

  /**
   * @param MagicSkill[] $learnedSpells The learned spells.
   * @param LearnableSpell[] $learnableSpells The learnable spells.
   * @param SpellSortOrder $sortOrder The learned-spell sort order.
   */
  public function __construct(
    array $learnedSpells = [],
    array $learnableSpells = [],
    protected SpellSortOrder $sortOrder = SpellSortOrder::A_TO_Z,
  )
  {
    foreach ($learnedSpells as $spell) {
      if ($spell instanceof MagicSkill) {
        $this->learnedSpells[$spell->name] = $spell;
      }
    }

    foreach ($learnableSpells as $learnableSpell) {
      if ($learnableSpell instanceof LearnableSpell) {
        $this->learnableSpells[$learnableSpell->skill->name] = $learnableSpell;
      }
    }

    $this->sortLearnedSpells();
  }

  /**
   * Creates a spellbook instance from serialized data.
   *
   * @param array<string, mixed> $data The serialized spellbook data.
   * @return self The reconstructed spellbook.
   */
  public static function fromArray(array $data): self
  {
    $learnedSpells = [];

    foreach ($data['learned'] ?? [] as $spellName) {
      $spell = MagicLibrary::find(strval($spellName));

      if ($spell instanceof MagicSkill) {
        $learnedSpells[] = $spell;
      }
    }

    $learnableSpells = array_values(array_filter(array_map(
      static fn(array $spellData): ?LearnableSpell => LearnableSpell::fromArray($spellData),
      array_filter($data['learnables'] ?? [], 'is_array')
    )));

    $sortOrder = SpellSortOrder::tryFrom(strval($data['sortOrder'] ?? SpellSortOrder::A_TO_Z->value)) ?? SpellSortOrder::A_TO_Z;

    return new self($learnedSpells, $learnableSpells, $sortOrder);
  }

  /**
   * Converts the spellbook to a serializable array.
   *
   * @return array<string, mixed> The serialized spellbook.
   */
  public function toArray(): array
  {
    return [
      'learned' => array_map(
        static fn(MagicSkill $spell): string => $spell->name,
        $this->getLearnedSpells()
      ),
      'learnables' => array_map(
        static fn(LearnableSpell $spell): array => $spell->toArray(),
        $this->getLearnableSpells()
      ),
      'sortOrder' => $this->sortOrder->value,
    ];
  }

  /**
   * Returns the learned spells in the current sort order.
   *
   * @return MagicSkill[] The learned spells.
   */
  public function getLearnedSpells(): array
  {
    return array_values($this->learnedSpells);
  }

  /**
   * Returns the known learnable spells.
   *
   * @return LearnableSpell[] The learnable spells.
   */
  public function getLearnableSpells(): array
  {
    return array_values($this->learnableSpells);
  }

  /**
   * Returns the subset of learned spells that can be used from the field menu.
   *
   * @return MagicSkill[] The field-usable spells.
   */
  public function getFieldUsableSpells(): array
  {
    return array_values(array_filter(
      $this->getLearnedSpells(),
      static fn(MagicSkill $spell): bool => in_array($spell->occasion, [Occasion::ALWAYS, Occasion::MENU_SCREEN], true)
    ));
  }

  /**
   * Adds a learned spell to the spellbook.
   *
   * @param MagicSkill $spell The spell to add.
   * @return void
   */
  public function addLearnedSpell(MagicSkill $spell): void
  {
    $this->learnedSpells[$spell->name] = $spell;
    $this->sortLearnedSpells();
  }

  /**
   * Sorts the learned spells according to the selected order.
   *
   * @param SpellSortOrder|null $sortOrder The sort order to apply.
   * @return void
   */
  public function sortLearnedSpells(?SpellSortOrder $sortOrder = null): void
  {
    $this->sortOrder = $sortOrder ?? $this->sortOrder;
    $spells = $this->learnedSpells;

    uasort($spells, static function(MagicSkill $left, MagicSkill $right): int {
      return strcmp($left->name, $right->name);
    });

    if ($this->sortOrder === SpellSortOrder::Z_TO_A) {
      $spells = array_reverse($spells, true);
    }

    $this->learnedSpells = $spells;
  }

  /**
   * Returns the current learned-spell sort order.
   *
   * @return SpellSortOrder The active sort order.
   */
  public function getSortOrder(): SpellSortOrder
  {
    return $this->sortOrder;
  }

  /**
   * Returns the number of ready-to-learn spells for the specified character.
   *
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @return int The number of ready spells.
   */
  public function getReadyToLearnCount(Character $character, Party $party): int
  {
    return count(array_filter(
      $this->getLearnableSpells(),
      static fn(LearnableSpell $spell): bool => $spell->isReady($character, $party)
    ));
  }

  /**
   * Attempts to learn the provided spell, consuming the relevant shared costs.
   *
   * @param LearnableSpell $learnableSpell The spell to learn.
   * @param Character $character The learning character.
   * @param Party $party The party that may pay shared costs.
   * @return bool True when the spell was learned.
   */
  public function learn(LearnableSpell $learnableSpell, Character $character, Party $party): bool
  {
    $registeredSpell = $this->learnableSpells[$learnableSpell->skill->name] ?? null;

    if (! $registeredSpell instanceof LearnableSpell || ! $registeredSpell->isReady($character, $party)) {
      return false;
    }

    $registeredSpell->requirement->consumeCosts($party);
    $registeredSpell->isLearned = true;
    $this->learnedSpells[$registeredSpell->skill->name] = $registeredSpell->skill;
    $this->sortLearnedSpells();

    return true;
  }
}
