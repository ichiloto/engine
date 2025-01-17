<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

/**
 * Represents a node in a dialogue tree.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
class DialogueNode
{
  /**
   * @var int The total number of choices available to the player.
   */
  protected(set) int $totalChoices = 0;

  /**
   * DialogueNode constructor.
   *
   * @param Dialogue $dialogue The dialogue this node belongs to.
   * @param DialogueChoice[] $choices The choices available to the player.
   */
  public function __construct(
    protected(set) Dialogue $dialogue,
    protected(set) array $choices = [] {
      set {
        $this->choices = $value;
        $this->totalChoices++;
      }
    }
  )
  {
  }

  /**
   * Adds a choice to the dialogue node.
   *
   * @param mixed $choice
   */
  public function addChoice(DialogueChoice $choice): void
  {
    $choices = $this->choices;
    $choices[] = $choice;
    $this->choices = $choices;
  }
}