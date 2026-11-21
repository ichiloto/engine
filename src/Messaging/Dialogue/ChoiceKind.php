<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue;

use Ichiloto\Engine\UI\Modal\ModalChoiceStyle;
use InvalidArgumentException;

/**
 * What an authored choice is, by who is asking. Andrew, 21 November 2026: each choice is marked "as an NPC answer
 * (list) or a decision (buttons) in its data", set "by who is asking". It is authored, never inferred from a title
 * or a speaker's name: the Waymeet field post's question names its Receptionist and is still a decision.
 */
enum ChoiceKind: string
{
  /** The party answers someone in a conversation: the answers are a list with the cursor. */
  case ANSWER = 'answer';
  /** The party makes a decision of its own: the choices are buttons. */
  case DECISION = 'decision';

  /** How every renderer draws choices of this kind. */
  public function getModalChoiceStyle(): ModalChoiceStyle
  {
    return match ($this) {
      self::ANSWER => ModalChoiceStyle::LIST,
      self::DECISION => ModalChoiceStyle::BUTTONS,
    };
  }

  /**
   * An authored kind; a choice that names none takes the default its owner documents.
   *
   * @throws InvalidArgumentException When the value names no kind.
   */
  public static function readAuthored(mixed $value, self $default): self
  {
    if ($value === null) { return $default; }
    return is_string($value) ? (self::tryFrom($value) ?? throw self::refuse($value))
      : throw new InvalidArgumentException('A choice kind must be "answer" or "decision".');
  }

  private static function refuse(string $value): InvalidArgumentException
  {
    return new InvalidArgumentException(sprintf('Unknown choice kind "%s"; use "answer" or "decision".', $value));
  }
}
