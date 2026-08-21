<?php

namespace Ichiloto\Engine\Events\Interpreter;

/**
 * The non-blocking dialogue/choice surface used by event scripts.
 */
interface EventPresentationInterface
{
  public function beginText(string $text, string $speaker): void;

  /** @param string[] $options The choice labels. */
  public function beginChoice(string $prompt, array $options, string $title = ''): void;

  public function update(): void;

  public function render(): void;

  public function isComplete(): bool;

  public function choiceResult(): ?int;

  public function reset(): void;
}
