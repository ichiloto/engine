<?php

namespace Ichiloto\Engine\Battle;

use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Stable semantic ids for top-level battle commands.
 *
 * The visible labels are project vocabulary and may change per game. The
 * battle flow should route by these semantic ids instead of display text.
 *
 * @package Ichiloto\Engine\Battle
 */
enum BattleCommandType: string
{
  case ATTACK = 'attack';
  case SKILL = 'skill';
  case MAGIC = 'magic';
  case SUMMON = 'summon';
  case ITEM = 'item';
  case GUARD = 'guard';
  case ESCAPE = 'escape';

  /**
   * Resolves a command type from a visible command label or command id.
   *
   * @param string $commandName The visible command label or semantic id.
   * @return self|null The resolved command type.
   */
  public static function fromCommandName(string $commandName): ?self
  {
    $normalized = self::normalize($commandName);

    foreach (self::cases() as $type) {
      if (
        $normalized === $type->value ||
        $normalized === self::normalize($type->defaultLabel()) ||
        $normalized === self::normalize($type->label())
      ) {
        return $type;
      }

      foreach ($type->roleLabels() as $roleLabel) {
        if ($normalized === self::normalize($roleLabel)) {
          return $type;
        }
      }
    }

    return null;
  }

  /**
   * Returns the configured player-facing label for this command.
   *
   * @return string The command label.
   */
  public function label(): string
  {
    $label = ConfigStore::has(ProjectConfig::class)
      ? config(ProjectConfig::class, 'vocab.command.' . $this->value, $this->defaultLabel())
      : $this->defaultLabel();

    $label = is_string($label) ? trim($label) : '';

    return $label !== '' ? $label : $this->defaultLabel();
  }

  /**
   * Returns the player-facing label for a character with the given role.
   *
   * Games can rename a command per role via project config — e.g. lore where
   * one tribe "Summons" while another "Petitions":
   *
   * ```php
   * 'vocab' => [
   *   'command' => [
   *     'summon' => 'Summon',
   *     'summon_by_role' => [
   *       'Oracle' => 'Petition',
   *       'Vanguard' => 'Request',
   *     ],
   *   ],
   * ],
   * ```
   *
   * @param string|null $roleName The character's role name.
   * @return string The command label for that role.
   */
  public function labelForRole(?string $roleName): string
  {
    if ($roleName === null || trim($roleName) === '') {
      return $this->label();
    }

    $normalizedRole = self::normalize($roleName);

    foreach ($this->roleLabels() as $configuredRole => $roleLabel) {
      if (self::normalize((string)$configuredRole) === $normalizedRole) {
        return $roleLabel;
      }
    }

    return $this->label();
  }

  /**
   * Returns the configured per-role labels for this command.
   *
   * @return array<string, string> Role name to label.
   */
  public function roleLabels(): array
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      return [];
    }

    $labels = config(ProjectConfig::class, 'vocab.command.' . $this->value . '_by_role', []);

    if (! is_array($labels)) {
      return [];
    }

    $normalized = [];

    foreach ($labels as $roleName => $label) {
      if (is_string($label) && trim($label) !== '') {
        $normalized[(string)$roleName] = trim($label);
      }
    }

    return $normalized;
  }

  /**
   * Returns the default player-facing label.
   *
   * @return string The default command label.
   */
  public function defaultLabel(): string
  {
    return match ($this) {
      self::ATTACK => 'Attack',
      self::SKILL => 'Skill',
      self::MAGIC => 'Magic',
      self::SUMMON => 'Summon',
      self::ITEM => 'Item',
      self::GUARD => 'Guard',
      self::ESCAPE => 'Escape',
    };
  }

  /**
   * Returns concise help for this command.
   *
   * @return string The help text.
   */
  public function helpText(): string
  {
    return match ($this) {
      self::ATTACK => 'Choose a physical attack to strike an enemy.',
      self::SKILL => 'Use one of this character\'s battle abilities.',
      self::MAGIC => 'Cast a learned spell that can be used in battle.',
      self::SUMMON => sprintf('Use one of this character\'s %s actions.', $this->label()),
      self::ITEM => 'Use a battle item from the party inventory.',
      self::GUARD => 'Brace for impact and take half damage until your next turn.',
      self::ESCAPE => 'Attempt to flee the battle.',
    };
  }

  /**
   * Returns the empty-state message for this command's submenu.
   *
   * @return string The empty-state message.
   */
  public function emptyMessage(): string
  {
    return match ($this) {
      self::ATTACK => 'No attacks.',
      self::SKILL => 'No skills.',
      self::MAGIC => 'No magic.',
      self::SUMMON => sprintf('No %s actions.', $this->label()),
      self::ITEM => 'No items.',
      self::GUARD => 'Cannot guard.',
      self::ESCAPE => 'Cannot escape.',
    };
  }

  /**
   * Normalizes a label for command matching.
   *
   * @param string $value The raw label.
   * @return string The normalized label.
   */
  private static function normalize(string $value): string
  {
    return strtolower(trim($value));
  }
}
