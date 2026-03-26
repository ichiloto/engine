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
    public bool $isLoadable = true,
    public ?string $statusMessage = null,
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
   * Creates an incompatible save-slot descriptor.
   *
   * @param int $slot The 1-based slot number.
   * @param string $path The absolute file path of the slot.
   * @param string $statusMessage The reason the slot cannot be loaded.
   * @return self The incompatible slot descriptor.
   */
  public static function incompatible(int $slot, string $path, string $statusMessage): self
  {
    return new self(
      slot: $slot,
      path: $path,
      isEmpty: false,
      locationName: 'Incompatible Save',
      isLoadable: false,
      statusMessage: $statusMessage,
    );
  }

  /**
   * Returns the leader summary shown in save/load menus.
   *
   * @return string The leader summary.
   */
  public function getLeaderSummary(): string
  {
    if (! $this->isLoadable) {
      return '';
    }

    if ($this->leaderName === '') {
      return '';
    }

    return sprintf('%s Lv %d', $this->leaderName, $this->leaderLevel);
  }

  /**
   * Serializes the save slot into a stable array payload.
   *
   * @return array<string, mixed> The serialized save-slot data.
   */
  public function __serialize(): array
  {
    return [
      'slot' => $this->slot,
      'path' => $this->path,
      'isEmpty' => $this->isEmpty,
      'locationName' => $this->locationName,
      'leaderName' => $this->leaderName,
      'leaderLevel' => $this->leaderLevel,
      'playTimeSeconds' => $this->playTimeSeconds,
      'savedAt' => $this->savedAt,
      'isLoadable' => $this->isLoadable,
      'statusMessage' => $this->statusMessage,
    ];
  }

  /**
   * Restores the save slot from serialized data.
   *
   * Older save files may not include the newer compatibility fields, so this
   * method defaults them when absent.
   *
   * @param array<string, mixed> $data The serialized save-slot data.
   * @return void
   */
  public function __unserialize(array $data): void
  {
    $this->slot = $data['slot'];
    $this->path = $data['path'];
    $this->isEmpty = $data['isEmpty'];
    $this->locationName = $data['locationName'];
    $this->leaderName = $data['leaderName'] ?? '';
    $this->leaderLevel = $data['leaderLevel'] ?? 0;
    $this->playTimeSeconds = $data['playTimeSeconds'] ?? 0;
    $this->savedAt = $data['savedAt'] ?? null;
    $this->isLoadable = $data['isLoadable'] ?? true;
    $this->statusMessage = $data['statusMessage'] ?? null;
  }
}
