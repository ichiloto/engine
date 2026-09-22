<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

/** Semantic journal content, supplied after the owner has applied visibility rules. */
final readonly class JournalSection
{
  /** @param list<array{text: string, icon?: string, color?: string}> $entries */
  public function __construct(public string $title, public array $entries, public ?string $icon = null) {}
}
