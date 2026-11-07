<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Util\PlayerText;
use InvalidArgumentException;

/** A semantic screen target and the successful action or world fact that advances it. */
final readonly class TutorialStep
{
  public const int MAX_INSTRUCTION_CELLS = 72;

  /**
   * @param array{screen: string, view: string, id: string} $target
   * @param array{outcome: string, subject?: string}|array<string, mixed> $until
   */
  private function __construct(
    public array $target,
    public string $action,
    public array $until,
    public ?string $say,
    public ?string $speaker,
    public bool $focus,
  ) {}

  public static function fromArray(mixed $data, string $source): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['target', 'action', 'until', 'say', 'speaker', 'focus']) !== []) {
      throw new InvalidArgumentException($source . ' must declare a tutorial step.');
    }

    $target = $data['target'] ?? null;
    if (!is_array($target) || array_diff(array_keys($target), ['screen', 'view', 'id']) !== []) {
      throw new InvalidArgumentException($source . ' target requires screen, view and id.');
    }
    foreach (['screen', 'view', 'id'] as $field) {
      TutorialDefinition::assertText($target[$field] ?? null, $source . ' target ' . $field);
    }
    TutorialDefinition::assertText($data['action'] ?? null, $source . ' action');

    $until = $data['until'] ?? null;
    if (!is_array($until)) {
      throw new InvalidArgumentException($source . ' requires an outcome or a world condition.');
    }
    if (array_key_exists('outcome', $until)) {
      if (array_diff(array_keys($until), ['outcome', 'subject']) !== []) {
        throw new InvalidArgumentException($source . ' outcome accepts only an outcome id and optional subject.');
      }
      TutorialDefinition::assertText($until['outcome'], $source . ' outcome id');
      if (array_key_exists('subject', $until)) {
        TutorialDefinition::assertText($until['subject'], $source . ' outcome subject');
      }
    } else {
      WorldConditionEvaluator::validateAll([$until], $source . ' completion condition');
    }

    $say = $data['say'] ?? null;
    $speaker = $data['speaker'] ?? null;
    $focus = $data['focus'] ?? false;
    if ($say !== null) {
      TutorialDefinition::assertText($say, $source . ' instruction');
      PlayerText::assertSentence($say, $source . ' instruction');
      if (preg_match('/[\p{Cc}\p{Zl}\p{Zp}]/u', $say) === 1 || TerminalText::displayWidth($say) > self::MAX_INSTRUCTION_CELLS) {
        throw new InvalidArgumentException($source . ' instruction must fit one line of at most ' . self::MAX_INSTRUCTION_CELLS . ' cells.');
      }
    }
    if ($speaker !== null) {
      TutorialDefinition::assertText($speaker, $source . ' speaker');
      if ($say === null) {
        throw new InvalidArgumentException($source . ' speaker requires an instruction.');
      }
    }
    if (!is_bool($focus)) {
      throw new InvalidArgumentException($source . ' focus must be boolean.');
    }

    return new self($target, $data['action'], $until, $say, $speaker, $focus);
  }
}
