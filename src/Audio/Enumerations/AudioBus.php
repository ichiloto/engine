<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Audio\Enumerations;

/**
 * The buses every sound plays on, beneath the master volume.
 *
 * Each bus has a player switch (`audio.<value>`) and a player volume
 * (`audio.<value>_volume`, 0 to 100). A sound plays at the master volume
 * times its bus volume times its own authored volume, so the player sets the
 * balance between music, sound effects and voices without changing any
 * sound's authored level. A game authors each bus's starting volume in its
 * config; the player's choices are kept in player settings.
 *
 * System sounds and looping sounds play on Effects.
 *
 * @package Ichiloto\Engine\Audio\Enumerations
 */
enum AudioBus: string
{
  /** Background music. */
  case MUSIC = 'music';
  /** Sound effects, system sounds and their loops. */
  case EFFECTS = 'sfx';
  /** Spoken lines. */
  case VOICE = 'voice';

  /** The volume a bus plays at when its game authors none: the master volume, unchanged. */
  public const int DEFAULT_VOLUME = 100;
  /** The quietest bus volume: the bus is silent. */
  public const int MIN_VOLUME = 0;
  /** The loudest bus volume: the bus plays at the master volume. */
  public const int MAX_VOLUME = 100;

  /**
   * Returns the config path of the bus's on/off switch.
   *
   * @return string The config path, e.g. "audio.music".
   */
  public function getEnabledConfigPath(): string
  {
    return "audio.$this->value";
  }

  /**
   * Returns the config path of the bus's volume.
   *
   * @return string The config path, e.g. "audio.music_volume".
   */
  public function getVolumeConfigPath(): string
  {
    return "audio.{$this->value}_volume";
  }

  /**
   * Returns the settings key of the bus's volume in the Options and Config menus.
   *
   * @return string The settings key, e.g. "music_volume".
   */
  public function getVolumeSettingKey(): string
  {
    return "{$this->value}_volume";
  }

  /**
   * Returns whether the bus's switch is on when its game leaves it out.
   * Music and sound effects start off; voices start on.
   *
   * @return bool True when the bus plays by default.
   */
  public function isEnabledByDefault(): bool
  {
    return $this === self::VOICE;
  }
}
