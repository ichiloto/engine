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
   * Creates a new ConfirmDialogue instance.
   * @inheritDoc
   */
  public function __construct(
    string $name,
    string $text,
    array $face = []
  )
  {
    parent::__construct($name, $text, $face, ['Yes', 'No'], 1);
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
    );
  }
}