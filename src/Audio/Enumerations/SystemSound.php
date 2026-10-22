<?php

namespace Ichiloto\Engine\Audio\Enumerations;

/**
 * The system sound effects the engine can play at built-in interaction
 * points, in the spirit of RPG Maker's system sound list.
 *
 * Games opt in per sound by declaring a track for the corresponding
 * `audio.sounds.<value>` key in their project config; sounds without a
 * configured track are silent. The enum value doubles as the config key.
 * Each sound also has its own authored volume, `audio.sound_volumes.<value>`
 * (0 to 100, scaled by the master volume), so a dense sound can sit under
 * the music without the game editing the file.
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
  /** A battler begins a basic physical attack. */
  case BATTLE_ATTACK = 'battle_attack';
  /** A battler begins a non-magical battle skill. */
  case BATTLE_SKILL = 'battle_skill';
  /** A battler begins destructive or debilitating magic. */
  case BATTLE_MAGIC_DESTRUCTIVE = 'battle_magic_destructive';
  /** A battler begins restorative or supportive magic. */
  case BATTLE_MAGIC_SUPPORT = 'battle_magic_support';
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
   * Loops while an on-screen number counts up or down (a points countdown),
   * and for any kind of count whose own loop the game leaves unbound. The
   * track should be a seamless loop; one counting loop plays however many
   * counts run together and stops when the last one ends.
   */
  case COUNTER = 'counter';
  /**
   * Loops while battle results dispense experience points (the EXP gauges
   * and level fills), in place of the counter. A game that binds no track
   * for it hears the counter instead. The track should be a seamless loop.
   */
  case EXP_TALLY = 'exp_tally';

  /** The authored volume a sound plays at when its project leaves it out. */
  public const int DEFAULT_VOLUME = 100;
  /** The quietest authored volume: the sound is silent. */
  public const int MIN_VOLUME = 0;
  /** The loudest authored volume: the sound plays at the master volume. */
  public const int MAX_VOLUME = 100;

  /**
   * Returns the project config path holding this sound's track.
   *
   * @return string The config path, e.g. "audio.sounds.cursor".
   */
  public function getConfigPath(): string
  {
    return "audio.sounds.$this->value";
  }

  /**
   * Returns the project config path holding this sound's authored volume.
   *
   * @return string The config path, e.g. "audio.sound_volumes.counter".
   */
  public function getVolumeConfigPath(): string
  {
    return "audio.sound_volumes.$this->value";
  }
}
