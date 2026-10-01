<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

use Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition;

/** Read-only projection of the existing owner's page, typing and playback state. */
final readonly class DialogueSnapshot
{
    public function __construct(public string $speaker, public string $page, public string $visibleText,
        public bool $isPrinting, public int $pageIndex, public int $pageCount, public bool $auto,
        public WindowPosition $position, public DialogueContext $context = new DialogueContext(),
        public string $help = '') {}
}
