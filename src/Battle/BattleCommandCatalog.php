<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Actions\CrisisSkillBattleAction;
use Ichiloto\Engine\Battle\Actions\GuardAction;
use Ichiloto\Engine\Battle\Actions\ItemBattleAction;
use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneDefinition;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enumerations\Occasion;
use Ichiloto\Engine\Entities\Inventory\Items\Item;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Skills\BasicSkill;
use Ichiloto\Engine\Entities\Skills\MagicSkill;
use Ichiloto\Engine\Entities\Skills\Skill;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Entities\Skills\SpecialSkill;
use Ichiloto\Engine\Localization\Vocabulary;
use Throwable;

/**
 * Builds submenu options for battle commands using project assets and party state.
 *
 * @package Ichiloto\Engine\Battle
 */
final class BattleCommandCatalog
{
  private static ?SummonCutsceneLibrary $battleSummons = null;
  private static ?AnimationLibrary $battleAnimations = null;
  /** @var array<int, true> */
  private static array $missingAnimationIds = [];

  /** Cache for standalone callers that have no battle scene or project owner. */
  public static function beginBattle(): void
  {
    self::$battleSummons = new SummonCutsceneLibrary(cacheForBattle: true);
    self::$battleAnimations = new AnimationLibrary(cacheForBattle: true);
    self::$missingAnimationIds = [];
  }

  public static function endBattle(): void
  {
    self::$battleSummons = null;
    self::$battleAnimations = null;
    self::$missingAnimationIds = [];
  }

  public static function getBattleSummonLibrary(): ?SummonCutsceneLibrary
  {
    return self::$battleSummons;
  }

  public static function getBattleAnimationLibrary(): ?AnimationLibrary
  {
    return self::$battleAnimations;
  }

  /** Suppresses duplicate missing-id warnings only within the active battle. */
  public static function recordMissingAnimationId(int $id): bool
  {
    if (self::$battleAnimations === null) { return true; }
    if (isset(self::$missingAnimationIds[$id])) { return false; }
    self::$missingAnimationIds[$id] = true;
    return true;
  }

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
   * @param BattleCommandType|string $commandName The semantic command, or a legacy name/id.
   * @param array<string, int> $reservedItemCounts Already queued item counts keyed by stable definition id.
   * @return BattleCommandOption[] The submenu options for the command.
   */
  public static function buildOptions(
    Character $character,
    Party $party,
    BattleCommandType|string $commandName,
    array $reservedItemCounts = [],
    ?GameState $gameState = null,
    ?BattleContent $content = null,
  ): array
  {
    $content = self::resolveContent($character, $content);
    $type = $commandName instanceof BattleCommandType ? $commandName : BattleCommandType::fromCommandName($commandName);
    if ($character->battleCommandLoadout?->allowsCommand($type) === false) {
      return [];
    }
    return match ($type) {
      BattleCommandType::ATTACK => self::buildAttackOptions($character, $party, $gameState, $content),
      BattleCommandType::SKILL => self::buildSkillOptions($character, $content),
      BattleCommandType::MAGIC => self::buildMagicOptions($character, $content),
      BattleCommandType::SUMMON => self::buildSummonOptions($character, $party, $gameState, $content),
      BattleCommandType::ITEM => self::buildItemOptions($party, $reservedItemCounts),
      default => [],
    };
  }

  /**
   * Builds the visible top-level commands, hiding Summon when it has no
   * currently usable option for this character.
   *
   * @return BattleCommand[]
   */
  public static function buildCommands(
    Character $character,
    Party $party,
    ?GameState $gameState = null,
    EscapePolicy $escapePolicy = EscapePolicy::ALLOWED,
    ?BattleContent $content = null,
  ): array
  {
    $content = self::resolveContent($character, $content);
    return array_values(array_filter(
      $character->commandAbilities,
      static function (BattleCommand $command) use ($character, $party, $gameState, $escapePolicy, $content): bool {
        $type = $command->type;

        if ($character->battleCommandLoadout?->allowsCommand($type) === false) {
          return false;
        }

        if ($type === BattleCommandType::ESCAPE) {
          return $escapePolicy === EscapePolicy::ALLOWED;
        }

        if ($type !== BattleCommandType::SUMMON) {
          return true;
        }

        return self::buildSummonOptions($character, $party, $gameState, $content) !== [];
      },
    ));
  }

  /**
   * Builds physical attack options.
   *
   * @return BattleCommandOption[] The available attack options.
   */
  protected static function buildAttackOptions(Character $character, ?Party $party, ?GameState $gameState,
    ?BattleContent $content): array
  {
    $skills = [];
    if ($character->attackSkill !== null) {
      $skills[$character->attackSkill->name] = $character->attackSkill;
    }

    foreach ($character->abilityBook->getBattleUsableAbilities() as $skill) {
      if (! $skill instanceof BasicSkill) {
        continue;
      }
      $skills[$skill->name] = $skill;
    }

    $options = array_values(array_map(static fn(Skill $skill): BattleCommandOption => self::createSkillOption($skill, content: $content), $skills));
    if ($character->attackSkill === null) {
      array_unshift($options, new BattleCommandOption(
        BattleCommandType::ATTACK->label(),
        'Strike a single enemy with a physical attack.',
        new AttackAction(BattleCommandType::ATTACK->label()),
        type: BattleCommandType::ATTACK,
      ));
    }

    $crisis = self::getAvailableCrisisOption($character, $party, $gameState, $content);
    if ($crisis !== null) { $options[] = $crisis; }
    return $options;
  }

  /** One live actor/form technique, shared by held command selection and event-only submenus. */
  public static function getAvailableCrisisOption(Character $character, ?Party $party = null,
    ?GameState $gameState = null, ?BattleContent $content = null): ?BattleCommandOption
  {
    $ability = $character->crisisAbility;
    if ($ability === null || !$ability->isActive($character)) { return null; }

    $content = self::resolveContent($character, $content);
    $catalog = $content?->getSkillCatalog() ?? SkillCatalog::getProjectCatalog($character->projectAssetRoot);
    $skill = $ability->getUnlockedSkill($character, $gameState, $party, $catalog);

    return $skill === null ? null : self::createSkillOption($skill,
      new CrisisSkillBattleAction($skill, $ability, $character->actorId, $gameState, $party, $catalog), $content);
  }

  /**
   * Builds special-skill options.
   *
   * @param Character $character The active character whose ability book should be inspected.
   * @return BattleCommandOption[] The available skill options.
   */
  protected static function buildSkillOptions(Character $character, ?BattleContent $content): array
  {
    return array_values(array_map(
      static fn(Skill $skill): BattleCommandOption => self::createSkillOption($skill, content: $content),
      array_filter(
        $character->abilityBook->getBattleUsableAbilities(),
        static fn(Skill $skill): bool => $skill instanceof SpecialSkill
          && !self::isSummonActionId($skill->name, $content?->getSummons()),
      ),
    ));
  }

  /**
   * Builds magic-skill options.
   *
   * @param Character $character The active character whose spellbook should be inspected.
   * @return BattleCommandOption[] The available magic options.
   */
  protected static function buildMagicOptions(Character $character, ?BattleContent $content): array
  {
    return array_values(array_map(
      static fn(Skill $skill): BattleCommandOption => self::createSkillOption($skill, content: $content),
      $character->spellbook->getBattleUsableSpells(),
    ));
  }

  /**
   * Builds summon options from authored summon cutscenes.
   *
   * A summon with a wielder policy only appears for characters that satisfy
   * the policy AND currently hold the summon; a summon without a policy is
   * openly usable by everyone.
   *
   * @param Character $character The active party character.
   * @return BattleCommandOption[] The available summon options.
   */
  protected static function buildSummonOptions(
    Character $character,
    Party $party,
    ?GameState $gameState,
    ?BattleContent $content,
  ): array
  {
    $options = [];
    $definitionsByActionId = [];

    foreach (self::loadSummonDefinitions($content?->getSummons()) as $definition) {
      if ($definition->linkedActionId !== null) {
        $definitionsByActionId[$definition->linkedActionId] = $definition;
      }
    }

    foreach (self::loadBattleSkills($content) as $skill) {
      $definition = $definitionsByActionId[$skill->name] ?? null;

      if ($definition !== null && self::isSummonAvailable($character, $party, $definition, $gameState)) {
        $options[] = self::createSkillOption($skill, content: $content);
      }
    }

    return $options;
  }

  /**
   * Rechecks a summon action at resolution time so a queued action cannot
   * bypass ownership or a world gate that has since become false.
   */
  public static function canUseSummonAction(
    Character $character,
    Party $party,
    string $actionId,
    ?GameState $gameState = null,
    ?BattleContent $content = null,
  ): bool
  {
    $content = self::resolveContent($character, $content);
    $definition = self::findSummonDefinitionByActionId($actionId, $content?->getSummons());

    return $definition !== null && self::isSummonAvailable($character, $party, $definition, $gameState);
  }

  private static function isSummonAvailable(Character $character, Party $party, SummonCutsceneDefinition $definition,
    ?GameState $gameState): bool
  {
    if ($character->battleCommandLoadout?->allowsCommand(BattleCommandType::SUMMON) === false) {
      return false;
    }

    if ($definition->availability !== null
      && !$character->battleCommandLoadout?->grantsSummonAvailability($definition->id)
      && ($gameState === null || ! $definition->isAvailable($gameState, $party))
    ) {
      return false;
    }

    $policy = $definition->wielders;

    if ($policy === null) {
      return true;
    }

    return $policy->isValid()
      && $policy->allowsCharacter($character)
      && $character->hasSummon($definition->id)
      && (! $policy->isExclusive() || $party->getSummonHolders($definition->id) === [$character]);
  }

  /** Returns whether the action id belongs to an authored summon. */
  public static function isSummonActionId(string $actionId, ?SummonCutsceneLibrary $summons = null): bool
  {
    return self::findSummonDefinitionByActionId($actionId, $summons) instanceof SummonCutsceneDefinition;
  }

  /** One semantic category serves command menus and execution presentation. */
  public static function getActionType(?BattleAction $action): BattleCommandType
  {
    return match (true) {
      $action instanceof CrisisSkillBattleAction => BattleCommandType::ATTACK,
      $action instanceof GuardAction => BattleCommandType::GUARD,
      $action instanceof ItemBattleAction => BattleCommandType::ITEM,
      $action instanceof SkillBattleAction && self::isSummonActionId($action->skill->name, $action->getSummonLibrary()) => BattleCommandType::SUMMON,
      $action instanceof SkillBattleAction && $action->skill instanceof BasicSkill => BattleCommandType::ATTACK,
      $action instanceof SkillBattleAction && $action->skill instanceof MagicSkill => BattleCommandType::MAGIC,
      $action instanceof AttackAction, $action === null => BattleCommandType::ATTACK,
      default => BattleCommandType::SKILL,
    };
  }

  private static function resolveContent(Character $character, ?BattleContent $content): ?BattleContent
  {
    return $content ?? ($character->projectAssetRoot === null ? null : new BattleContent($character->projectAssetRoot));
  }

  protected static function findSummonDefinitionByActionId(string $actionId, ?SummonCutsceneLibrary $summons): ?SummonCutsceneDefinition
  {
    $normalized = trim($actionId);

    foreach (self::loadSummonDefinitions($summons) as $definition) {
      if ($definition->linkedActionId === $normalized) {
        return $definition;
      }
    }

    return null;
  }

  /**
   * Creates a submenu option from a skill.
   *
   * @param Skill $skill The skill to convert.
   * @return BattleCommandOption The submenu option.
   */
  protected static function createSkillOption(Skill $skill, ?SkillBattleAction $action = null,
    ?BattleContent $content = null): BattleCommandOption
  {
    $costs = [];
    if ($skill->cost > 0) {
      $costs[] = sprintf('%d %s', $skill->cost, Vocabulary::getTerm('stats.mp', 'MP'));
    }
    if ($skill->apCost > 0) {
      $costs[] = sprintf('%d %s', $skill->apCost, Vocabulary::getTerm('stats.ap'));
    }
    $costLabel = $costs === [] ? '' : ' (' . implode(', ', $costs) . ')';
    $action ??= new SkillBattleAction($skill);
    if ($content !== null) { $action->bindSummonLibrary($content->getSummons()); }
    $scope = $action->targetScope;

    return new BattleCommandOption(
      $skill->name . $costLabel,
      $skill->description,
      $action,
      $scope->side,
      $scope->status,
      $skill,
      max(0, $skill->cost),
      $scope->number,
      self::getActionType($action),
      $skill->apCost,
    );
  }

  /**
   * Builds item options from the current party inventory.
   *
   * @param Party $party The party whose inventory should be inspected.
   * @param array<string, int> $reservedItemCounts Already queued item counts keyed by stable definition id.
   * @return BattleCommandOption[] The available item options.
   */
  protected static function buildItemOptions(Party $party, array $reservedItemCounts): array
  {
    $options = [];

    foreach ($party->inventory->items->toArray() as $item) {
      if (! $item instanceof Item || ! in_array($item->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)) {
        continue;
      }

      $reservedCount = $reservedItemCounts[$item->id] ?? 0;
      $availableQuantity = max(0, $item->quantity - $reservedCount);

      if ($availableQuantity < 1) {
        continue;
      }

      $action = new ItemBattleAction($item, $party->inventory);
      $scope = $action->targetScope;
      $options[] = new BattleCommandOption(
        sprintf('%s x%d', $item->name, $availableQuantity),
        $item->description,
        $action,
        $scope->side,
        $scope->status,
        $item,
        targetNumber: $scope->number,
        type: BattleCommandType::ITEM,
      );
    }

    return $options;
  }

  /**
   * Loads all authored summon cutscene definitions.
   *
   * @return SummonCutsceneDefinition[] The authored summon definitions.
   */
  protected static function loadSummonDefinitions(?SummonCutsceneLibrary $summons): array
  {
    try {
      return ($summons ?? self::getBattleSummonLibrary() ?? new SummonCutsceneLibrary())->load();
    } catch (Throwable) {
      return [];
    }
  }

  /**
   * Loads battle-usable skills from the project's shared catalogue.
   *
   * @return Skill[] The loaded skills.
   */
  protected static function loadBattleSkills(?BattleContent $content): array
  {
    $catalog = $content?->getSkillCatalog() ?? SkillCatalog::getProjectCatalog();
    return array_values(array_filter(
      $catalog->getSkills(),
      static fn(Skill $skill): bool =>
        in_array($skill->occasion, [Occasion::ALWAYS, Occasion::BATTLE_SCREEN], true)
    ));
  }
}
