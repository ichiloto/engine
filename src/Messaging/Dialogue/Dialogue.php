<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Exception;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/**
 * Represents a dialogue.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
readonly class Dialogue
{
  /**
   * Creates a new instance of the dialogue.
   *
   * @param string $name The name of the dialogue.
   * @param string $text The text of the dialogue.
   * @param array $face The face of the dialogue.
   */
  public function __construct(
    public string $name,
    public string $text,
    public array $face = [],
  )
  {
  }

  /**
   * Show the dialogue.
   *
   * @throws Exception If the dialogue cannot be shown.
   */
  public function show(): void
  {
    show_text(
      $this->text,
      $this->name,
      '',
      config(ProjectConfig::class, 'ui.dialogue.window.position', WindowPosition::BOTTOM),
      config(ProjectConfig::class, 'ui.dialogue.message.speed', 20),
    );
  }

  /**
   * Convert the dialogue to an array.
   *
   * @param array{name: string, text: string, face: string} $data The data.
   * @return static The dialogue instance.
   * @throws RequiredFieldException If the required field is missing.
   */
  public static function fromArray(array $data): static
  {
    return new static(
      $data['name'] ?? '',
      $data['text'] ?? throw new RequiredFieldException('text'),
      self::loadFaceData($data['face'] ?? ''),
    );
  }

  /**
   * Convert the dialogue to an object.
   *
   * @param object{name: string, text: string, face: string} $data The data.
   * @return static The dialogue instance.
   * @throws RequiredFieldException If the required field is missing.
   */
  public static function fromObject(object $data): static
  {
    return self::fromArray((array) $data);
  }

  /**
   * Load the face data.
   *
   * @param string $faceFilename The face filename.
   *
   * @return string[] The face data.
   */
  protected static function loadFaceData(string $faceFilename): array
  {
    $faceData = [];

    if (!$faceFilename) {
      return [];
    }

    // Load the face data from the file.

    return $faceData;
  }
}