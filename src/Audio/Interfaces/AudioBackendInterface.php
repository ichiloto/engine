<?php

namespace Ichiloto\Engine\Audio\Interfaces;

/**
 * Describes a command line audio playback backend.
 *
 * A backend wraps a specific audio player executable that may or may not be
 * installed on the host system. It knows which audio formats that player can
 * decode and how to assemble the argv list used to play a file. Backends never
 * spawn processes themselves — the AudioManager owns all process lifecycles.
 *
 * @package Ichiloto\Engine\Audio\Interfaces
 */
interface AudioBackendInterface
{
  /**
   * Returns the name of the player executable this backend wraps.
   *
   * @return string The executable name, e.g. "mpv" or "afplay".
   */
  public function getExecutableName(): string;

  /**
   * Determines whether the player executable is installed on this system.
   *
   * @return bool True when the executable was found on the PATH.
   */
  public function isAvailable(): bool;

  /**
   * Determines whether the player can loop a track by itself.
   *
   * When a backend cannot loop natively, the AudioManager emulates looping by
   * respawning the player each time the process exits.
   *
   * @return bool True when the player supports native looping.
   */
  public function supportsNativeLooping(): bool;

  /**
   * Determines whether the player can decode the given audio file.
   *
   * @param string $filePath The path of the audio file.
   * @return bool True when the file format is supported.
   */
  public function supports(string $filePath): bool;

  /**
   * Determines whether the player can start playback at an arbitrary offset.
   *
   * Seeking lets the AudioManager restart a track mid-way — e.g. resuming at
   * the current position after a volume change — instead of from the top.
   *
   * @return bool True when the player supports a start offset.
   */
  public function supportsSeeking(): bool;

  /**
   * Builds the argv list used to play the given file.
   *
   * @param string $filePath The absolute path of the audio file.
   * @param float $volume The normalized playback volume between 0.0 and 1.0.
   * @param bool $loop Whether the player should loop the track natively. Only
   *   honoured when supportsNativeLooping() returns true.
   * @param float $startAtSeconds The offset to start playback from. Only
   *   honoured when supportsSeeking() returns true.
   * @return string[] The argv list, executable first.
   */
  public function buildCommand(string $filePath, float $volume, bool $loop, float $startAtSeconds = 0.0): array;
}
