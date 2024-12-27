<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

/**
 * Represents a choice in a dialogue.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
class DialogueChoice
{
  /**
   * Creates a new instance of the dialogue choice.
   *
   * @param Dialogue $dialogue The dialogue to display.
   * @param DialogueNode|null $nextNode The next node to traverse to.
   */
  public function __construct(
    protected(set) Dialogue $dialogue,
    protected(set) ?DialogueNode $nextNode = null,
  )
  {
  }
}