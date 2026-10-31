<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Progression;

use Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary;
use Ichiloto\Engine\IO\ActionHints;
use Ichiloto\Engine\IO\ActionHint;
use InvalidArgumentException;

/** Authored first-use presentation, attached to the power's existing identity. */
final readonly class WorldAbilityFirstUse
{
  /** @param list<array{action:string,label:string}> $hints */
  public function __construct(public string $message = '', public array $hints = [], public ?string $effect = null)
  {
    if (!array_is_list($hints)) { throw new InvalidArgumentException('First-use hints must be a list.'); }
    foreach ($hints as $hint) {
      if (!is_array($hint) || array_diff(array_keys($hint), ['action', 'label']) !== []
        || !is_string($hint['action'] ?? null) || !is_string($hint['label'] ?? null)) {
        throw new InvalidArgumentException('A first-use hint declares a semantic action and label.');
      }
      new ActionHint($hint['action'], $hint['label'], null);
    }
    if ($effect !== null) { EffectTimelineLibrary::assertId($effect); }
    if (trim($message) === '' && $hints === [] && $effect === null) {
      throw new InvalidArgumentException('A first-use presentation needs a message, hint or effect.');
    }
  }

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || array_diff(array_keys($data), ['message', 'hints', 'effect']) !== []
      || (isset($data['message']) && !is_string($data['message']))
      || (isset($data['hints']) && !is_array($data['hints']))
      || (isset($data['effect']) && !is_string($data['effect']))) {
      throw new InvalidArgumentException('First-use presentation declares message, hints and an optional effect identity.');
    }
    return new self($data['message'] ?? '', $data['hints'] ?? [], $data['effect'] ?? null);
  }

  /** @return list<ActionHint> One resolved snapshot for measurement and delivery. */
  public function getActionHints(): array
  {
    return array_map(static fn(array $hint): ActionHint => ActionHints::resolve($hint['action'], $hint['label']), $this->hints);
  }
}
