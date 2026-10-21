<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\EffectFacingTarget;

/** Orient a directional effect toward what it faces, independently of combat resolution. */
final class BattleEffectDirection
{
  /**
   * The horizontal direction from a battler to its opponents: the party stands
   * east of the troop and faces west; the troop faces east. Command advance and
   * opponent-facing effects share this one owner.
   */
  public static function getOpponentsSign(bool $partySide): int
  {
    return $partySide ? -1 : 1;
  }

  /**
   * Orient a track by what it turns toward: its recipient (a stroke), or the
   * anchored battler's opponents (a ward raised in front of that battler,
   * wherever its caster stands). A track whose reference is unknown keeps its
   * authored facing.
   * @param array<string, mixed> $command @return array<string, mixed>
   */
  public static function orientForSubject(array $command, ?float $casterX, ?float $recipientX,
    ?bool $subjectPartySide): array
  {
    if (($command['payload']['toward'] ?? null) === EffectFacingTarget::OPPONENTS->value) {
      return $subjectPartySide === null ? $command
        : self::orientCommand($command, 0.0, (float)self::getOpponentsSign($subjectPartySide));
    }
    return $casterX === null || $recipientX === null ? $command : self::orientCommand($command, $casterX, $recipientX);
  }

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
