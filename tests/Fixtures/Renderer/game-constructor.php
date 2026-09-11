<?php

namespace Ichiloto\Engine\IO\Console {
  // Isolate only the physical terminal probe; Game construction stays real.
  function shell_exec(string $command): ?string
  {
    return str_starts_with($command, 'stty size') ? "31 117\n" : \shell_exec($command);
  }
}

namespace {
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\IO\Console\Console;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$arguments = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
$game = new Game('Constructor geometry', ...$arguments);
file_put_contents('dimensions.json', json_encode([
  'width' => Console::getWidth(), 'height' => Console::getHeight(),
], JSON_THROW_ON_ERROR));
$game->quit();
}
