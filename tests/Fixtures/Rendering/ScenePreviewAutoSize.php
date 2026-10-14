<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO\Console {
    // Only the physical terminal probe is synthetic; Game sizing stays real.
    function shell_exec(string $command): ?string
    {
        return str_starts_with($command, 'stty size') ? "4 12\n" : \shell_exec($command);
    }
}

namespace {
    $factory = require __DIR__ . '/ScenePreview.php';
    return static function (...$arguments) use ($factory): array {
        $fixture = $factory(...$arguments);
        $fixture['game']->configure(['width' => null, 'height' => null]);
        return $fixture;
    };
}
