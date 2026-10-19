<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Scenes\Battle\BattleConfig;

/** Arena rows only: no scene, Console, input, audio, or gameplay execution. */
final class TerminalBattlePresentation
{
  private readonly TerminalBattleEffects $effects;
  private readonly Vector2 $origin;
  private readonly BattleConditionEffects $conditionEffects;

  public function __construct(private readonly BattleConfig $battle, ?PartyBattlerPositions $positions = null,
    ?Vector2 $origin = null, public readonly int $width = 135, public readonly int $height = 30,
    ?BattleConditionEffects $conditionEffects = null)
  {
    $this->origin = clone ($origin ?? new Vector2());
    $this->effects = new TerminalBattleEffects($positions, $this->origin, $width, $height);
    $this->conditionEffects = $conditionEffects ?? BattleConditionEffects::createFromConfig(
      new \Ichiloto\Engine\Animations\Timelines\EffectTimelineLibrary(getcwd() . '/assets'));
  }

  /** @return array{lines: list<string>, draws: array, flashes: array} */
  public function getFrame(?BattleCommandPlayback $playback = null, bool $reducedMotion = false, float $poseSeconds = 0): array
  {
    $party = $this->battle->partyRoster->battlers;
    $troop = $this->battle->troop->members->toArray();
    $draws = [];
    foreach ($party as $index => $battler) {
      if ($battler->isKnockedOut) { continue; }
      $position = $this->effects->getPartyPresentedPosition($index, $battler, $playback, $reducedMotion);
      array_push($draws, ...self::getSpriteDraws($battler->images->battle, $this->origin->x + $position->x, $this->origin->y + $position->y));
    }
    foreach ($troop as $battler) {
      $defeat = $playback?->getEnemyDefeatTreatment($battler, $reducedMotion);
      if ($battler->isKnockedOut && !($defeat['visible'] ?? false)) { continue; }
      $position = $this->effects->getTroopPresentedPosition($battler, $playback, $reducedMotion);
      array_push($draws, ...self::getSpriteDraws(EnemyDefeatStyle::applyTerminalTreatment($battler->image, $defeat), $this->origin->x + $position->x,
        $this->origin->y + $position->y, $this->effects->getTroopAvailableWidth($position)));
    }
    $composed = $playback === null ? ['draws' => [], 'flashes' => []] : $this->effects->compose($playback,
      fn($battler, $position) => $this->effects->getAnimationOrigin($battler, $position, $party, $troop, $playback, $reducedMotion), $reducedMotion);
    array_push($draws, ...$composed['draws']);
    foreach ([...$party, ...$troop] as $battler) {
      $condition = $this->conditionEffects->createPlayback($battler, $poseSeconds);
      if ($condition === null) { continue; }
      $ambient = $this->effects->compose($condition,
        fn($subject, $position) => $this->effects->getAnimationOrigin($subject, $position, $party, $troop, $playback, $reducedMotion), $reducedMotion);
      array_push($draws, ...$ambient['draws']);
    }
    $lines = array_fill(0, $this->height, str_repeat(' ', $this->width));
    foreach ($draws as $draw) {
      $position = self::getDrawPosition($draw['x'], $draw['y']);
      $this->overlayLine($lines, $draw['text'], $position['x'] - intval($this->origin->x), $position['y'] - intval($this->origin->y));
    }
    $regions = $this->effects->getFlashRegions($composed['flashes'],
      fn($battler) => $this->effects->getBattlerAnchor($battler, $party, $troop, $playback, $reducedMotion),
      fn($battler) => $battler instanceof Character ? TerminalBattleEffects::getSpriteWidth($battler->images->battle)
        : $this->effects->getTroopVisibleSpriteWidth($battler, $this->effects->getTroopPresentedPosition($battler, $playback, $reducedMotion)));
    foreach ($regions as &$region) {
      $region['left'] -= intval($this->origin->x);
      $region['right'] -= intval($this->origin->x);
      $region['top'] -= intval($this->origin->y);
      $region['bottom'] -= intval($this->origin->y);
    }
    unset($region);
    $overlay = TerminalBattleEffects::composeFlashOverlay($lines, $regions, $this->width, $this->height);
    foreach ($overlay['lines'] ?? [] as $row => $line) { $this->overlayLine($lines, $line, $overlay['x'], $overlay['y'] + $row); }
    return ['lines' => $lines, 'draws' => $draws, 'flashes' => $composed['flashes']];
  }

  /** @return list<array{text: string, x: float|int, y: float|int}> */
  public static function getSpriteDraws(array $sprite, float|int $x, float|int $y, ?int $maxWidth = null): array
  {
    $draws = [];
    foreach ($sprite as $row => $text) {
      $draws[] = ['text' => TerminalText::stabilize($maxWidth === null ? $text : TerminalText::truncateToWidth($text, max(0, $maxWidth))),
        'x' => $x, 'y' => $y + $row];
    }
    return $draws;
  }

  /** Converts the field's logical coordinates to the Console's zero-based cells. */
  public static function getDrawPosition(float|int $x, float|int $y): array
  {
    return ['x' => max(0, (int)floor($x) - 1), 'y' => max(0, (int)floor($y) - 1)];
  }

  private function overlayLine(array &$lines, string $text, int $x, int $y): void
  {
    if ($y < 0 || $y >= $this->height) { return; }
    $cells = NormalizedRow::fromText($lines[$y], $this->width)->cells;
    $draw = NormalizedRow::fromText(TerminalText::stabilize($text))->cells;
    foreach ($draw as $column => $cell) {
      if ($cell === NormalizedRow::CONTINUATION) { continue; }
      $start = $x + $column;
      $span = NormalizedRow::symbolWidth($cell);
      if ($start < 0 || $start + $span > $this->width) { continue; }
      // An overlay touching half a wide glyph must erase its whole previous footprint.
      for ($at = $start; $at < $start + $span; $at++) {
        $anchor = $at;
        while ($anchor > 0 && $cells[$anchor] === NormalizedRow::CONTINUATION) { $anchor--; }
        $oldSpan = NormalizedRow::symbolWidth($cells[$anchor]);
        for ($old = $anchor; $old < min($this->width, $anchor + $oldSpan); $old++) { $cells[$old] = ' '; }
      }
      $cells[$start] = $cell;
      for ($at = $start + 1; $at < $start + $span; $at++) { $cells[$at] = NormalizedRow::CONTINUATION; }
    }
    $lines[$y] = implode('', array_filter($cells, static fn(string $cell): bool => $cell !== NormalizedRow::CONTINUATION));
  }
}
