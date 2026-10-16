<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Localization;

use InvalidArgumentException;

/** Discoverable Engine message defaults shared by runtime and project editors. */
final class EngineMessageTerms
{
  /** @var array<string, array{default:string, placeholders:array<string,string>, description:string, vocabularyDefault?:string}> */
  private const array TERMS = [
    'ability.empty_discovered' => ['default' => 'No discovered %1.', 'placeholders' => ['%1' => 'Ability command label'], 'description' => 'No abilities have been discovered.'],
    'ability.empty_learned' => ['default' => 'No learned %1.', 'placeholders' => ['%1' => 'Ability command label'], 'description' => 'No abilities have been learned.'],
    'battle.announcements.attack' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Fallback attack callout. Blank is silent; skills use their own invocation message.'],
    'battle.announcements.skill' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Fallback skill callout. Blank is silent; skills use their own invocation message.'],
    'battle.announcements.magic' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Fallback magic callout. Blank is silent; skills use their own invocation message.'],
    'battle.announcements.summon' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Fallback summon callout. Blank is silent; skills use their own invocation message.'],
    'battle.announcements.item' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Item callout. Blank is silent.'],
    'battle.announcements.guard' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Guard callout. Blank is silent.'],
    'battle.announcements.escape' => ['default' => '%2', 'placeholders' => ['%1' => 'Actor name', '$1' => 'Actor name', '%2' => 'Action name', '$2' => 'Action name'], 'description' => 'Escape action callout. Blank is silent.'],
    'battle.currency_at_capacity' => ['default' => '%1 at capacity', 'placeholders' => ['%1' => 'Currency label'], 'description' => 'Battle reward currency has reached its capacity.'],
    'battle.insufficient_resource' => ['default' => '%1 cannot use %2: not enough %3.', 'placeholders' => ['%1' => 'Actor name', '%2' => 'Action name', '%3' => 'Resource label'], 'description' => 'An action cannot execute because its resource is insufficient.'],
    'battle.insufficient_resource_selection' => ['default' => 'Not enough %5! %1 needs %2 %5, %3 has %4.', 'placeholders' => ['%1' => 'Action name', '%2' => 'Resource cost', '%3' => 'Actor name', '%4' => 'Current resource', '%5' => 'Resource label'], 'description' => 'An action cannot be selected because its resource is insufficient.'],
    'battle.level_gained' => ['default' => '%1 grew to %2 %3!', 'placeholders' => ['%1' => 'Character name', '%2' => 'Level label', '%3' => 'New level'], 'description' => 'A character gains a level after battle.'],
    'confirm.quit' => ['default' => 'Are you sure you want to quit?', 'placeholders' => [], 'description' => 'Confirmation before exiting the game.'],
    'confirm.to_title' => ['default' => 'Return to title?', 'placeholders' => [], 'description' => 'Confirmation before returning to the title screen.'],
    'exp_next' => ['default' => 'To Next %1', 'placeholders' => ['%1' => 'Level label'], 'description' => 'Experience required for the next level.'],
    'exp_total' => ['default' => 'Current %1', 'placeholders' => ['%1' => 'Experience label'], 'description' => 'Current experience heading; inherits vocab.shop.exp_total when present.', 'vocabularyDefault' => 'shop.exp_total'],
    'file' => ['default' => 'File', 'placeholders' => [], 'description' => 'Numbered save-slot label prefix.'],
    'inventory.empty' => ['default' => 'Inventory is empty.', 'placeholders' => [], 'description' => 'The party inventory has no items.'],
    'inventory.possession' => ['default' => 'Possession', 'placeholders' => [], 'description' => 'Owned quantity heading; inherits vocab.shop.possession when present.', 'vocabularyDefault' => 'shop.possession'],
    'magic.empty_discovered' => ['default' => 'No discovered %1.', 'placeholders' => ['%1' => 'Magic command label'], 'description' => 'No spells have been discovered.'],
    'magic.empty_learned' => ['default' => 'No learned %1.', 'placeholders' => ['%1' => 'Magic command label'], 'description' => 'No spells have been learned.'],
    'magic.insufficient_mp' => ['default' => 'Not enough %1 for %2.', 'placeholders' => ['%1' => 'Resource label', '%2' => 'Spell name'], 'description' => 'A field spell has insufficient resources.'],
    'menu.back' => ['default' => 'Back', 'placeholders' => [], 'description' => 'Return from the credits menu.'],
    'new_game.failed' => ['default' => 'The game could not start. Please try again.', 'placeholders' => [], 'description' => 'Starting a new game failed.'],
    'new_game.unavailable_title' => ['default' => '%1 Unavailable', 'placeholders' => ['%1' => 'New-game command label'], 'description' => 'Heading when a new game cannot start.'],
    'obtained_exp' => ['default' => '%1 %2 obtained!', 'placeholders' => ['%1' => 'Experience amount', '%2' => 'Experience label'], 'description' => 'Battle experience reward.'],
    'obtained_gold' => ['default' => '%1 %2 found!', 'placeholders' => ['%1' => 'Currency amount', '%2' => 'Currency symbol'], 'description' => 'Currency received from a chest or battle.'],
    'obtained_item' => ['default' => '%1 found!', 'placeholders' => ['%1' => 'Item reward text'], 'description' => 'Item received from a chest.'],
    'prompt.load' => ['default' => 'Choose a save file to continue from.', 'placeholders' => [], 'description' => 'Load-menu selection prompt.'],
    'prompt.save' => ['default' => 'Which file would you like to save to?', 'placeholders' => [], 'description' => 'Save-menu selection prompt.'],
    'quest.level_gained' => ['default' => '%1 reached %2 %3.', 'placeholders' => ['%1' => 'Character name', '%2' => 'Level label', '%3' => 'New level'], 'description' => 'A character gains a level from a quest reward.'],
    'save.complete_title' => ['default' => '%1 Complete', 'placeholders' => ['%1' => 'Save command label'], 'description' => 'Heading after saving succeeds.'],
    'save.event_in_progress' => ['default' => 'Please wait until the current event finishes before saving.', 'placeholders' => [], 'description' => 'Saving is blocked while an event is running.'],
    'save.load_failed' => ['default' => 'This save could not be loaded. Please choose another save.', 'placeholders' => [], 'description' => 'A save failed to load.'],
    'save.newer_version' => ['default' => 'This save needs a newer version of the game.', 'placeholders' => [], 'description' => 'A save uses a newer unsupported version.'],
    'save.unavailable_title' => ['default' => '%1 Unavailable', 'placeholders' => ['%1' => 'Save command label'], 'description' => 'Heading when saving is unavailable.'],
    'save.unreadable' => ['default' => 'This save could not be read. Please choose another save.', 'placeholders' => [], 'description' => 'A save is unreadable or damaged.'],
    'save.write_failed' => ['default' => 'Your progress could not be saved. Please try again.', 'placeholders' => [], 'description' => 'A save could not be written.'],
    'save.wrong_game' => ['default' => 'This save belongs to a different game.', 'placeholders' => [], 'description' => 'A save belongs to another project.'],
    'shop.insufficient_funds' => ['default' => 'Not enough %1!', 'placeholders' => ['%1' => 'Currency label'], 'description' => 'The party cannot afford a shop purchase.'],
    'title.credits' => ['default' => 'Credits', 'placeholders' => [], 'description' => 'Credits heading and title-menu command.'],
    'title.credits_description' => ['default' => 'See who made this game.', 'placeholders' => [], 'description' => 'Title-menu help for the credits command.'],
    'travel.unavailable' => ['default' => 'That area is unavailable right now. Please try again.', 'placeholders' => [], 'description' => 'A map transfer could not complete.'],
    'travel.unavailable_title' => ['default' => 'Travel Unavailable', 'placeholders' => [], 'description' => 'Heading when a map transfer fails.'],
  ];

  private function __construct() {}

  /**
   * Keys are paths beneath messages.; entries include defaults, accepted tokens and help.
   * This metadata is independent of runtime config. vocabularyDefault identifies
   * a legacy fallback beneath vocab.; editors resolve it in their own workspace.
   *
   * @return array<string, array{default:string, placeholders:array<string,string>, description:string, vocabularyDefault?:string}>
   */
  public static function listTerms(): array
  {
    $terms = self::TERMS;
    ksort($terms);
    return $terms;
  }

  /** Returns the same default used by runtime lookup and the editor. */
  public static function getDefault(string $path): string
  {
    $term = self::TERMS[$path] ?? throw new InvalidArgumentException("Unknown Engine message term: $path");
    return isset($term['vocabularyDefault'])
      ? Vocabulary::getTerm($term['vocabularyDefault'], $term['default']) : $term['default'];
  }
}
