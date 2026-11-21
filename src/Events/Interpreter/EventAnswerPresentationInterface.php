<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * A presentation that can show the answers to someone's question as a list. An event choice authored as an answer
 * (ChoiceKind::ANSWER) needs it; the interpreter refuses such a choice on a presentation without it instead of
 * showing the answers as a decision's buttons.
 */
interface EventAnswerPresentationInterface extends EventPresentationInterface
{
  /**
   * @param string $prompt What is asked.
   * @param string[] $answers The answers, in order.
   * @param string $speaker Who is asking.
   */
  public function beginAnswer(string $prompt, array $answers, string $speaker = ''): void;
}
