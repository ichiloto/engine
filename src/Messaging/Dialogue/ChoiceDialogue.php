<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Override;

class ChoiceDialogue extends Dialogue
{
  /**
   * The index of the selected choice.
   */
  protected(set) int $selectedChoice = -1;

  /**
   * @inheritDoc
   * @param string $name The name of the dialogue.
   * @param string $text The text to display.
   * @param string[] $face The face to display.
   * @param string[] $choices The choices to display.
   * @param int $defaultChoice The default choice.
   */
  public function __construct(
    string $name,
    string $text,
    array $face = [],
    protected array $choices = [],
    protected int $defaultChoice = 0,
  )
  {
    parent::__construct($name, $text, $face);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function show(): void
  {
    $height = count(explode("\n", $this->text)) + count($this->choices) + 2;
    $position = config(ProjectConfig::class, 'ui.dialogue.window.position', WindowPosition::BOTTOM);
    $position = $position->getCoordinates(DEFAULT_DIALOG_WIDTH, $height);
    $this->selectedChoice = select(
      $this->text,
      $this->choices,
      $this->name,
    );
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public static function fromArray(array $data): static
  {
    return new self(
      $data['name'] ?? '',
      $data['text'] ?? throw new RequiredFieldException('text'),
      self::loadFaceData($data['face'] ?? ''),
      $data['choices'] ?? [],
      $data['defaultChoice'] ?? 0,
    );
  }
}