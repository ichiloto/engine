<?php

namespace Ichiloto\Engine\IO\Saves;

/**
 * Describes a save slot shown in save/load menus.
 *
 * @package Ichiloto\Engine\IO\Saves
 */
readonly class SaveSlot
{
  /**
   * @param int $slot The 1-based slot number.
   * @param string $path The absolute file path of the slot.
   * @param bool $isEmpty Whether the slot is empty.
   * @param string $locationName The displayed location name.
   * @param string $leaderName The leader name shown in the slot.
   * @param int $leaderLevel The leader level shown in the slot.
   * @param int $playTimeSeconds The stored play time in seconds.
   * @param int|null $savedAt The save timestamp.
   */
  public function __construct(
    public int $slot,
    public string $path,
    public bool $isEmpty,
    public string $locationName,
    public string $leaderName = '',
    public int $leaderLevel = 0,
    public int $playTimeSeconds = 0,
    public ?int $savedAt = null,
  )
  {
  }

  /**
   * Creates an empty save-slot descriptor.
   *
   * @param int $slot The 1-based slot number.
   * @param string $path The absolute file path of the slot.
   * @return self The empty slot descriptor.
   */
  public static function empty(int $slot, string $path): self
  {
    return new self(
      slot: $slot,
      path: $path,
      isEmpty: true,
      locationName: 'Empty File',
    );
  }

  /**
   * Returns the leader summary shown in save/load menus.
   *
   * @return string The leader summary.
   */
  public function getLeaderSummary(): string
  {
    if ($this->leaderName === '') {
      return '';
    }

    return sprintf('%s Lv %d', $this->leaderName, $this->leaderLevel);
  }
}
