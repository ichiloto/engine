<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Modal;

use Ichiloto\Engine\Audio\Enumerations\SystemSound;
use Ichiloto\Engine\IO\ActionHint;
use Ichiloto\Engine\IO\ActionHints;
use InvalidArgumentException;

/** A dismissible announcement; current theme bindings own its optional artwork. */
final readonly class NoticeContent
{
  /** @var list<array{action:string,label:string}> */
  public array $hints;

  /** @param list<array{action:string,label:string}> $hints Semantic controls resolved when presented. */
  public function __construct(public string $title, public string $message,
    public ?string $actorId = null, public ?string $iconRole = null, public ?SystemSound $sound = null,
    array $hints = [])
  {
    if (!array_is_list($hints)) { throw new InvalidArgumentException('Notice action hints must be a list.'); }
    $copy = [];
    foreach ($hints as $hint) {
      if (!is_array($hint) || array_diff(array_keys($hint), ['action', 'label']) !== []
        || !is_string($hint['action'] ?? null) || !is_string($hint['label'] ?? null)) {
        throw new InvalidArgumentException('A notice hint declares a semantic action and label.');
      }
      new ActionHint($hint['action'], $hint['label'], null);
      $copy[] = ['action' => $hint['action'], 'label' => $hint['label']];
    }
    $this->hints = $copy;
    if (trim($title . $message) === '') { throw new InvalidArgumentException('A notice requires visible text.'); }
    foreach ([$actorId, $iconRole] as $reference) {
      if ($reference !== null && (trim($reference) === '' || $reference !== trim($reference))) {
        throw new InvalidArgumentException('Notice artwork references must be nonempty stable identities.');
      }
    }
    if ($actorId !== null && $iconRole !== null) {
      throw new InvalidArgumentException('A notice selects an actor portrait or an icon role.');
    }
  }

  /** @return list<ActionHint> One current-control snapshot for measurement and painting. */
  public function getActionHints(): array
  {
    return array_map(static fn(array $hint): ActionHint => ActionHints::resolve($hint['action'], $hint['label']), $this->hints);
  }
}
