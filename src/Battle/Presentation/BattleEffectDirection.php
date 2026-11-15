<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\Timelines\{EffectFacingTarget, EffectHorizontalFacing};
use Ichiloto\Engine\Core\Vector2;
use InvalidArgumentException;

/** Orient a directional effect toward what it faces, independently of combat resolution. */
final class BattleEffectDirection
{
  /**
   * The fixed Terminal lane convention and legacy source-art direction.
   * Graphical hosts resolve actual opponents from formation ground positions.
   */
  public static function getOpponentsSign(bool $partySide): int
  {
    return self::getAuthoredFacing($partySide)->getSign();
  }

  /** Existing source-art convention; it is independent of where the formation stands. */
  public static function getAuthoredFacing(bool $partySide): EffectHorizontalFacing
  {
    return $partySide ? EffectHorizontalFacing::WEST : EffectHorizontalFacing::EAST;
  }

  /** Face the selected ground extent, independent of selection order and artwork bounds.
   * Empty/self-only targets and equal columns keep the caller's current orientation.
   * @param array<float|int> $recipientXs
   */
  public static function getFacingToward(float $originX, array $recipientXs,
    EffectHorizontalFacing $retained): EffectHorizontalFacing
  {
    if (!is_finite($originX) || array_any($recipientXs, static fn($x): bool =>
      (!is_int($x) && !is_float($x)) || !is_finite((float)$x))) {
      throw new InvalidArgumentException('Battle facing requires finite ground positions.');
    }
    if ($recipientXs === []) { return $retained; }
    $left = min($recipientXs); $right = max($recipientXs);
    $centre = $left / 2 + $right / 2;
    return $centre === $originX ? $retained
      : ($centre < $originX ? EffectHorizontalFacing::WEST : EffectHorizontalFacing::EAST);
  }

  /** @param array<int, Vector2> $grounds @param array<int, bool> $partySides */
  public static function getOpponentsFacing(int $subject, array $grounds, array $partySides): ?EffectHorizontalFacing
  {
    if (!isset($grounds[$subject], $partySides[$subject])) { return null; }
    return self::getOpponentsFacingAt($grounds[$subject]->x, $partySides[$subject], $grounds, $partySides);
  }

  /** A target group has a real ground origin; it never borrows its first member's orientation.
   * @param array<int, Vector2> $grounds @param array<int, bool> $partySides
   */
  public static function getOpponentsFacingAt(float $originX, bool $side, array $grounds,
    array $partySides): EffectHorizontalFacing
  {
    $opponents = [];
    foreach ($grounds as $id => $point) {
      if (isset($partySides[$id]) && $partySides[$id] !== $side) { $opponents[] = $point->x; }
    }
    return self::getFacingToward($originX, $opponents, self::getAuthoredFacing($side));
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
    return self::orientForFacing($command, $casterX, $recipientX,
      $subjectPartySide === null ? null : self::getAuthoredFacing($subjectPartySide));
  }

  /** Graphical hosts provide the actual formation direction; Terminal retains its own lane convention.
   * @param array<string, mixed> $command @return array<string, mixed>
   */
  public static function orientForFacing(array $command, ?float $casterX, ?float $recipientX,
    ?EffectHorizontalFacing $opponentsFacing): array
  {
    if (($command['payload']['toward'] ?? null) === EffectFacingTarget::OPPONENTS->value) {
      return $opponentsFacing === null ? $command
        : self::orientCommand($command, 0.0, (float)$opponentsFacing->getSign());
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
