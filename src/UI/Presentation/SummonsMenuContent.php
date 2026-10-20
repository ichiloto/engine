<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\ActionHint;

/** Current contract-menu projection. Assignment and navigation remain with its state. */
final readonly class SummonsMenuContent
{
  /** @param list<string> $summary @param list<MenuRow> $rows @param list<ActionHint> $hints */
  public function __construct(public ?Character $character, public string $title, public array $summary,
    public array $rows, public int $index, public bool $detailsOpen, public string $description,
    public ?string $status, public array $hints, public ?MenuDetailSheet $details = null) {}
}
