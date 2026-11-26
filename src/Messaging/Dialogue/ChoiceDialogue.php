<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Exceptions\RequiredFieldException;
use InvalidArgumentException;
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
   * @param int $defaultChoice The choice the focus starts on, as authored: the first unless the author names another.
   * @param ChoiceKind $kind Who is asking: an answer to the speaker (a list), unless authored as the party's own
   *   decision (buttons). A dialogue is someone's line, so a choice that names no kind is an answer.
   */
  public function __construct(
    string $name,
    string $text,
    array $face = [],
    protected array $choices = [],
    protected int $defaultChoice = 0,
    public readonly ChoiceKind $kind = ChoiceKind::ANSWER,
  )
  {
    if ($choices !== [] && ! isset(array_values($choices)[$defaultChoice])) {
      throw new InvalidArgumentException(sprintf('The starting choice %d is not one of the %d choices.', $defaultChoice, count($choices)));
    }
    parent::__construct($name, $text, $face);
  }

  /**
   * @inheritDoc
   */
  #[Override]
  public function showForGame(Game $game): void
  {
    $modals = $game->getModalManager();
    $this->selectedChoice = $this->kind === ChoiceKind::ANSWER
      ? $modals->selectDialogueAnswer($this->text, $this->choices, $this->name, $this->defaultChoice)
      : $modals->select($this->text, $this->choices, $this->name, $this->defaultChoice);
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
      self::readDefaultChoice($data['defaultChoice'] ?? 0),
      ChoiceKind::readAuthored($data['kind'] ?? null, ChoiceKind::ANSWER),
    );
  }

  /**
   * An authored starting choice: the index of a choice.
   *
   * @throws InvalidArgumentException When it is not a whole number.
   */
  protected static function readDefaultChoice(mixed $value): int
  {
    return is_int($value) ? $value : throw new InvalidArgumentException('A starting choice is the number of a choice, from 0.');
  }
}