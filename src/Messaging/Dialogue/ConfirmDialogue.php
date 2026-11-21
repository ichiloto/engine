<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\Exceptions\RequiredFieldException;
use Override;

/**
 * A dialogue that asks the user to confirm an action.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
class ConfirmDialogue extends ChoiceDialogue
{
  /**
   * Creates a new ConfirmDialogue instance. Andrew, 21 November 2026: the starting choice is "Authored per
   * confirmation"; one that names none starts on Yes, as confirmations always have.
   *
   * @param int $defaultChoice The choice the focus starts on: 0 for Yes, 1 for No.
   * @inheritDoc
   */
  public function __construct(
    string $name,
    string $text,
    array $face = [],
    ChoiceKind $kind = ChoiceKind::ANSWER,
    int $defaultChoice = 0,
  )
  {
    parent::__construct($name, $text, $face, ['Yes', 'No'], $defaultChoice, $kind);
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
      ChoiceKind::readAuthored($data['kind'] ?? null, ChoiceKind::ANSWER),
      self::readDefaultChoice($data['defaultChoice'] ?? 0),
    );
  }
}