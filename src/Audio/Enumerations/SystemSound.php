<?php

namespace Ichiloto\Engine\Audio\Enumerations;

/**
 * The system sound effects the engine can play at built-in interaction
 * points, in the spirit of RPG Maker's system sound list.
 *
 * Games opt in per sound by declaring a track for the corresponding
 * `audio.sounds.<value>` key in their project config; sounds without a
 * configured track are silent. The enum value doubles as the config key.
 *
 * @package Ichiloto\Engine\Audio\Enumerations
 */
enum SystemSound: string
{
  /** Cursor movement in menus, windows and choice lists. */
  case CURSOR = 'cursor';
  /** Confirming/activating a selection. */
  case CONFIRM = 'confirm';
  /** Backing out of a menu or window. */
  case CANCEL = 'cancel';
  /** An invalid or unavailable action. */
  case BUZZER = 'buzzer';
  /** The game was saved. */
  case SAVE = 'save';
  /** A battle is starting. */
  case BATTLE_START = 'battle_start';
  /** The party escaped from battle. */
  case ESCAPE = 'escape';
  /** A party member took damage. */
  case ACTOR_DAMAGE = 'actor_damage';
  /** An enemy took damage. */
  case ENEMY_DAMAGE = 'enemy_damage';
  /** An enemy was defeated. */
  case ENEMY_COLLAPSE = 'enemy_collapse';
  /** An item or collectable was picked up on the field. */
  case ITEM_GET = 'item_get';
  /** Currency changed hands (shops, rewards). */
  case SHOP = 'shop';
  /**
   * Played when a notification appears.
   */
  case NOTIFICATION = 'notification';
  /**
   * Played when a party member gains a level.
   */
  case LEVEL_UP = 'level_up';

  /**
   * Returns the project config path holding this sound's track.
   *
   * @return string The config path, e.g. "audio.sounds.cursor".
   */
  public function getConfigPath(): string
  {
    return "audio.sounds.$this->value";
  }
}
