<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

/** Orient a directional stroke toward its recipient, independently of combat resolution. */
final class BattleEffectDirection
{
  /** @param array<string, mixed> $command @return array<string, mixed> */
  public static function orientCommand(array $command, float $casterX, float $recipientX): array
  {
    $facing = $command['payload']['facing'] ?? null;
    if ($facing === null || $casterX === $recipientX) { return $command; }
    $east = $recipientX > $casterX;
    if ($east === ($facing === 'east')) { return $command; }

    $command['payload']['flipX'] = !($command['payload']['flipX'] ?? false);
    if (isset($command['position']['x'])) { $command['position']['x'] *= -1; }
    if (isset($command['content'])) {
      $lines = explode("\n", $command['content']);
      $command['content'] = implode("\n", array_map(static fn(string $line): string =>
        strtr(implode('', array_reverse(mb_str_split($line))),
          ['/' => '\\', '\\' => '/', '(' => ')', ')' => '(', '[' => ']', ']' => '[', '<' => '>', '>' => '<']), $lines));
    }
    return $command;
  }
}
