<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Exception;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialogueContext;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Represents a dialogue.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
  * @phpstan-consistent-constructor
 */
class Dialogue
{
  public private(set) DialogueContext $presentation;
  /** Optional field-direction overrides accompany the page without changing its text or portrait identity. */
  private array $fieldFacing = [];

  /**
   * Creates a new instance of the dialogue.
   *
   * @param string $name The name of the dialogue.
   * @param string $text The text of the dialogue.
   * @param array $face The face of the dialogue.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $text,
    public readonly array $face = [],
  )
  {
    $this->presentation = new DialogueContext();
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
      config(ProjectConfig::class, 'ui.dialogue.window.position', null),
      config(
        ProjectConfig::class,
        'ui.dialogue.speed',
        config(ProjectConfig::class, 'ui.dialogue.message.speed', 20)
      ),
      presentation: $this->presentation,
    );
  }

  /** Prepare the field separately so specialized dialogue/choice show() APIs retain their existing contract. */
  public function prepareFieldPresentation(GameScene $scene): void
  {
    $scene->cinematicController?->prepareDialogue($this->name, $this->fieldFacing);
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
    $presentation = DialogueContext::getFromText($data);
    $dialogue = new static(
      $data['name'] ?? '',
      $data['text'] ?? throw new RequiredFieldException('text'),
      self::loadFaceData($data['face'] ?? ''),
    );
    $dialogue->presentation = $presentation;
    $dialogue->fieldFacing = array_intersect_key($data, array_flip(['speaker', 'addressee', 'facing', 'faceToward']));
    return $dialogue;
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
