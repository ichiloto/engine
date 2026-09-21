<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Messaging\Dialogue\Presentation;

final readonly class DialoguePresentationCatalog
{
    public const string FILE = 'Data/Presentation/dialogue.php';
    public function __construct(public array $actors = [])
    {
    }
}
