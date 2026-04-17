<?php

namespace Ichiloto\Engine\Battle;

use Assegai\Util\Path;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Effects\HPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\MPRecoveryEffect;
use Ichiloto\Engine\Entities\Effects\ResurrectionEffect;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeSide;
use Ichiloto\Engine\Entities\Enumerations\ItemScopeStatus;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Throwable;

/**
 * Builds submenu options for battle commands using project assets and party state.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattleCommandCatalog
{
  /**
   * BattleCommandCatalog constructor.
   */
  private function __construct()
  {
  }

  /**
   * Builds submenu options for the selected top-level battle command.
   *
   * @param Character $character The active party character.
   * @param Party $party The party whose inventory should be inspected.
   * @param string $commandName The selected top-level command name.
   * @param array<string, int> $reservedItemCounts Already queued item counts keyed by item name.
   * @return BattleCommandOption[] The submenu options for the command.
   */
  public static function buildOptions(
    Character $character,
    Party $party,
    string $commandName,
    array $reservedItemCounts = []
  ): array
  {
    $normalized = strtolower(trim($commandName));

    return match ($normalized) {
      'attack' => self::buildAttackOptions(),
      'skill' => self::buildSkillOptions($character),
      'magic' => self::buildMagicOptions($character),
      'summon' => self::buildSummonOptions(),
      'item' => self::buildItemOptions($party, $reservedItemCounts),
      default => [],
    };
  }

  /**
   * Builds physical attack options.
   *
   * @return BattleCommandOption[] The available attack options.
   */
  protected static function buildAttackOptions(): array
  {
    $options = [];
    $hasBasicAttack = false;

    foreach (self::loadBattleSkills() as $skill) {
      if (! $skill instanceof BasicSkill) {
        continue;
      }

      $hasBasicAttack = $hasBasicAttack || strtolower($skill->name) === 'attack';
      $options[] = self::createSkillOption($skill);
    }

    if (! $hasBasicAttack) {
      array_unshift(
        $options,
        new BattleCommandOption(
          'Attack',
          'Strike a single enemy with a physical attack.',
          new AttackAction('Attack')
        )
      );
    }

    return $options;
  }

  /**
   * Builds special-skill options.
   *
   * @param Character $character The active character whose ability book should be inspected.
   * @return BattleCommandOption[] The available skill options.
   */
  protected static function buildSkillOptions(Character $character): array
  {
    $learnedAbilities = $character->abilityBook->getBattleUsableAbilities();
    $knownAbilities = $character->abilityBook->getLearnedAbilities();
    $discoverableAbilities = $character->abilityBook->getLearnableAbilities();

    if (! empty($learnedAbilities)) {
      return array_values(array_map(self::createSkillOption(...), $learnedAbilities));
    }

    if (! empty($knownAbilities) || ! empty($discoverableAbilities)) {
      return [];
    }

    return array_values(array_map(
      self::createSkillOption(...),
      array_filter(self::loadBattleSkills(), static fn(Skill $skill): bool => $skill instanceof SpecialSkill)
    ));
  }

  /**
   * Builds magic-skill options.
   *
   * @param Character $character The active character whose spellbook should be inspected.
   * @return BattleCommandOption[] The available magic options.
   */
  protected static function buildMagicOptions(Character $character): array
  {
    $learnedMagic = array_values(array_filter(
      $character->spellbook->getLearnedSpells(),
      static fn(MagicSkill $skill): bool => in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
    ));

    if (! empty($learnedMagic)) {
      return array_values(array_map(self::createSkillOption(...), $learnedMagic));
    }

    return array_values(array_map(
      self::createSkillOption(...),
      array_filter(self::loadBattleSkills(), static fn(Skill $skill): bool => $skill instanceof MagicSkill)
    ));
  }

  /**
   * Builds summon options from authored summon cutscenes.
   *
   * @return BattleCommandOption[] The available summon options.
   */
  protected static function buildSummonOptions(): array
  {
    $options = [];
    $linkedActionIds = self::loadSummonActionNames();

    foreach (self::loadBattleSkills() as $skill) {
      if (! in_array($skill->name, $linkedActionIds, true)) {
        continue;
      }

      $options[] = self::createSkillOption($skill);
    }

    return $options;
  }

  /**
   * Creates a submenu option from a skill.
   *
   * @param Skill $skill The skill to convert.
   * @return BattleCommandOption The submenu option.
   */
  protected static function createSkillOption(Skill $skill): BattleCommandOption
  {
    $costLabel = $skill->cost > 0 ? sprintf(' (%d MP)', $skill->cost) : '';
    $displayName = self::isSummonSkill($skill)
      ? $skill->name . $costLabel
      : trim(sprintf('%s %s%s', $skill->icon, $skill->name, $costLabel));

    return new BattleCommandOption(
      $displayName,
      $skill->description,
      new SkillBattleAction($skill),
      $skill->scope->side,
      $skill->scope->status,
      $skill
    );
  }

  /**
   * Determines whether the given skill is backed by an authored summon cutscene.
   *
   * @param Skill $skill The skill being inspected.
   * @return bool True when the skill is a summon.
   */
  protected static function isSummonSkill(Skill $skill): bool
  {
    static $summonActionIds = null;
    $summonActionIds ??= self::loadSummonActionNames();

    return in_array($skill->name, $summonActionIds, true);
  }

  /**
   * Builds item options from the current party inventory.
   *
   * @param Party $party The party whose inventory should be inspected.
   * @param array<string, int> $reservedItemCounts Already queued item counts keyed by item name.
   * @return BattleCommandOption[] The available item options.
   */
  protected static function buildItemOptions(Party $party, array $reservedItemCounts): array
  {
    $options = [];

    foreach ($party->inventory->items->toArray() as $item) {
      if (! $item instanceof Item || ! in_array($item->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)) {
        continue;
      }

      $reservedCount = $reservedItemCounts[$item->name] ?? 0;
      $availableQuantity = max(0, $item->quantity - $reservedCount);

      if ($availableQuantity < 1) {
        continue;
      }

      [$targetSide, $targetStatus] = self::resolveItemTargeting($item);
      $options[] = new BattleCommandOption(
        sprintf('%s %s x%d', $item->icon, $item->name, $availableQuantity),
        $item->description,
        new ItemBattleAction($item),
        $targetSide,
        $targetStatus,
        $item
      );
    }

    return $options;
  }

  /**
   * Infers a sensible targeting side and status for the given item.
   *
   * @param Item $item The item being inspected.
   * @return array{0: ItemScopeSide, 1: ItemScopeStatus} The inferred target side and status.
   */
  protected static function resolveItemTargeting(Item $item): array
  {
    foreach ($item->effects as $effect) {
      if ($effect instanceof ResurrectionEffect) {
        return [ItemScopeSide::ALLY, ItemScopeStatus::DEAD];
      }

      if ($effect instanceof HPRecoveryEffect || $effect instanceof MPRecoveryEffect) {
        return [ItemScopeSide::ALLY, ItemScopeStatus::ALIVE];
      }
    }

    return [$item->scope->side, $item->scope->status];
  }

  /**
   * @return string[]
   */
  protected static function loadSummonActionNames(): array
  {
    return array_values(array_filter(array_map(
      static fn($definition): ?string => $definition->linkedActionId,
      (new SummonCutsceneLibrary())->load()
    )));
  }

  /**
   * Loads all battle-usable skills from the current project's skill asset file.
   *
   * @return Skill[] The loaded skills.
   */
  protected static function loadBattleSkills(): array
  {
    $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Data', 'skills.php');

    if (! file_exists($filename)) {
      return [];
    }

    try {
      $skills = asset('Data/skills.php', true);
    } catch (Throwable) {
      return [];
    }

    if (! is_array($skills)) {
      return [];
    }

    return array_values(array_filter(
      $skills,
      static fn(mixed $skill): bool =>
        $skill instanceof Skill &&
        in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
    ));
  }
}
