<?php

namespace Ichiloto\Engine\Messaging\Dialogue;

use Exception;

/**
 * Represents a dialogue tree. The dialogue tree is a collection of dialogue nodes that are connected by choices.
 *
 * @package Ichiloto\Engine\Messaging\Dialogue
 */
class DialogueTree
{
  /**
   * DialogueTree constructor.
   *
   * @param DialogueNode $rootNode The root node of the dialogue tree.
   */
  public function __construct(
    protected(set) DialogueNode $rootNode
  )
  {
  }

  /**
   * Traverse the dialogue tree.
   *
   * @param DialogueNode|null $node
   * @return int The index of the choice selected.
   * @throws Exception if the dialogue cannot be shown.
   */
  public function traverse(?DialogueNode $node = null): int
  {
    $node = $node ?? $this->rootNode;
    $node->dialogue->show();

    $choices = [];
    foreach ($node->choices as $index => $choice) {
      $choices[] = sprintf("%d. %s", $index + 1, $choice->dialogue->text);
    }

    return select("Choose an option:", $choices);
  }

  /**
   * Get the next node in the dialogue tree.
   *
   * @param DialogueNode $node The current node.
   * @param int $choiceIndex The index of the choice selected.
   * @return DialogueNode|null The next node in the dialogue tree.
   */
  public function getNextNode(DialogueNode $node, int $choiceIndex): ?DialogueNode
  {
    return $node->choices[$choiceIndex]->nextNode ?? null;
  }
}