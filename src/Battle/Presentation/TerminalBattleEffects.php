<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Animations\AnimationTargetPosition;
use Ichiloto\Engine\Battle\PartyBattlerPositions;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\Enumerations\Color;
use InvalidArgumentException;
use RuntimeException;

/** Pure terminal geometry and effect layout shared by the live field and previews. */
final class TerminalBattleEffects
{
  private const int TROOP_STEP_X_OFFSET = 3;
  private const int TROOP_ZONE_LEFT = 2;
  private const int BATTLE_SIDE_GAP = 3;
  private const int LEGACY_SCREEN_INSET = 2;
  private readonly PartyBattlerPositions $positions;
  private readonly Vector2 $origin;

  public function __construct(?PartyBattlerPositions $positions = null, ?Vector2 $origin = null,
    private readonly int $width = 135, private readonly int $height = 30)
  {
    if ($width < 1 || $height < 1) { throw new InvalidArgumentException('Terminal battle dimensions must be positive.'); }
    $this->positions = $positions ?? new PartyBattlerPositions();
    $this->origin = clone ($origin ?? new Vector2());
  }

  public function getPartyIdlePosition(int $index): Vector2
  {
    return clone ($this->positions->idlePositions[$index] ?? throw new RuntimeException('Invalid party battler position.'));
  }

  public function getPartyActivePosition(int $index): Vector2
  {
    return clone ($this->positions->activePositions[$index] ?? throw new RuntimeException('Invalid party battler active position.'));
  }

  public static function isCommandAdvanced(CharacterInterface $battler, ?BattleCommandPlayback $playback,
    bool $reducedMotion): bool
  {
    return $playback?->actor === $battler && !$playback->isCompleted && !$playback->plan->resultsOnly
      && !in_array($playback->phase, ['return', 'finish'], true) && !$reducedMotion;
  }

  public function getPartyPresentedPosition(int $index, CharacterInterface $battler,
    ?BattleCommandPlayback $playback, bool $reducedMotion): Vector2
  {
    return self::isCommandAdvanced($battler, $playback, $reducedMotion)
      ? $this->getPartyActivePosition($index) : $this->getPartyIdlePosition($index);
  }

  public function getTroopIdlePosition(Enemy $battler): Vector2
  {
    // Reserve the enemy step as well as the party's active positions before fitting art.
    $maximumX = max(self::TROOP_ZONE_LEFT, $this->getPartyZoneLeft() - self::BATTLE_SIDE_GAP
      - self::TROOP_STEP_X_OFFSET - max(1, self::getSpriteWidth($battler->image)));
    return new Vector2(clamp(intval($battler->position->x), self::TROOP_ZONE_LEFT, $maximumX), $battler->position->y);
  }

  public function getTroopActivePosition(Enemy $battler): Vector2
  {
    $position = $this->getTroopIdlePosition($battler);
    return new Vector2($position->x + self::TROOP_STEP_X_OFFSET, $position->y);
  }

  public function getTroopPresentedPosition(Enemy $battler, ?BattleCommandPlayback $playback,
    bool $reducedMotion): Vector2
  {
    return self::isCommandAdvanced($battler, $playback, $reducedMotion)
      ? $this->getTroopActivePosition($battler) : $this->getTroopIdlePosition($battler);
  }

  public function getPartyZoneLeft(): int
  {
    return min(array_map(static fn(Vector2 $position): int => intval($position->x),
      [...$this->positions->idlePositions, ...$this->positions->activePositions]));
  }

  public function getTroopAvailableWidth(Vector2 $position): int
  {
    return max(0, $this->getPartyZoneLeft() - self::BATTLE_SIDE_GAP - intval($position->x));
  }

  public function getTroopVisibleSpriteWidth(Enemy $battler, Vector2 $position): int
  {
    return min(self::getSpriteWidth($battler->image), $this->getTroopAvailableWidth($position));
  }

  public static function getSpriteWidth(array $sprite): int
  {
    return max([0, ...array_map(TerminalText::displayWidth(...), $sprite)]);
  }

  /** @return array{x: int, y: int, partyIndex?: int, troopIndex?: int}|null */
  public function getBattlerAnchor(CharacterInterface $battler, array $party, array $troop,
    ?BattleCommandPlayback $playback = null, bool $reducedMotion = false): ?array
  {
    if ($battler instanceof Character) {
      $index = array_search($battler, $party, true);
      if (!is_int($index)) { return null; }
      $position = $this->getPartyPresentedPosition($index, $battler, $playback, $reducedMotion);
      $width = self::getSpriteWidth($battler->images->battle);
      $identity = ['partyIndex' => $index];
    } elseif ($battler instanceof Enemy) {
      $index = array_search($battler, $troop, true);
      if (!is_int($index)) { return null; }
      $position = $this->getTroopPresentedPosition($battler, $playback, $reducedMotion);
      $width = $this->getTroopVisibleSpriteWidth($battler, $position);
      $identity = ['troopIndex' => $index];
    } else { return null; }
    return ['x' => intval($this->origin->x + $position->x) + intdiv(max(1, $width), 2),
      'y' => intval($this->origin->y + $position->y) - 1] + $identity;
  }

  /** @return array{x: int, y: int}|null */
  public function getAnimationOrigin(CharacterInterface $battler, AnimationTargetPosition $position,
    array $party, array $troop, ?BattleCommandPlayback $playback = null, bool $reducedMotion = false): ?array
  {
    if ($position === AnimationTargetPosition::SCREEN) {
      return ['x' => intval($this->origin->x) + intdiv($this->width, 2),
        'y' => intval($this->origin->y) + intdiv($this->height, 2)];
    }
    $anchor = $this->getBattlerAnchor($battler, $party, $troop, $playback, $reducedMotion);
    if ($anchor === null) { return null; }
    $sprite = $battler instanceof Character ? $battler->images->battle : $battler->image;
    $height = max(1, count($sprite));
    return ['x' => $anchor['x'], 'y' => $anchor['y'] + 1 + match ($position) {
      AnimationTargetPosition::HEAD => 0,
      AnimationTargetPosition::FEET => $height - 1,
      default => intdiv($height, 2),
    }];
  }

  /** Logical field coordinates are one-based, matching the live field's indicators. */
  public function getIndicatorPosition(string $text, int $x, int $y): array
  {
    $minX = intval($this->origin->x) + 1;
    $minY = intval($this->origin->y) + 1;
    return ['x' => clamp($x, $minX, max($minX, intval($this->origin->x) + $this->width - TerminalText::displayWidth($text) - 1)),
      'y' => clamp($y, $minY, max($minY, intval($this->origin->y) + $this->height - 2))];
  }

  /**
   * @param callable(CharacterInterface, AnimationTargetPosition): ?array $resolveOrigin Read-only geometry owner.
   * @return array{draws: list<array{text: string, x: int, y: int}>, flashes: list<array{target: CharacterInterface, screen: bool, color: Color}>}
   */
  public function compose(BattleEffectPlayback $playback, callable $resolveOrigin, bool $reducedMotion = false): array
  {
    $draws = $flashes = [];
    foreach ($playback->getActiveSegments($reducedMotion, true) as $segment) {
      foreach ($segment['drawCommands'] as $command) {
        if (!($command['visible'] ?? true)) { continue; }
        $data = $command['payload'] ?? [];
        $anchor = $data['anchor'] ?? 'target';
        if ($segment['layer'] === 'flash' && !$reducedMotion) {
          // Full-screen flashes and camera movement are deliberately absent in Terminal.
          if (in_array($anchor, ['screen', 'legacy-screen'], true) || ($data['scope'] ?? '') === 'screen') { continue; }
          $color = self::resolveNamedColor($data['color'] ?? $command['color'] ?? 'white');
          if ($color === null) { continue; }
          foreach ($anchor === 'caster' ? [$playback->actor] : $playback->targets as $target) {
            $flashes[spl_object_id($target)] = ['target' => $target, 'screen' => false, 'color' => $color];
          }
        }
        if (!in_array($segment['layer'], ['glyph', 'text'], true)) { continue; }
        if ($anchor === 'legacy-screen') {
          array_push($draws, ...$this->getLegacyDraws($command));
          continue;
        }
        $position = AnimationTargetPosition::tryFrom($data['attachment'] ?? $data['legacyPosition'] ?? 'center')
          ?? AnimationTargetPosition::CENTER;
        $screen = $anchor === 'screen' || $position === AnimationTargetPosition::SCREEN;
        $subjects = $screen || $anchor === 'caster' ? [$playback->actor] : $playback->targets;
        $seen = [];
        foreach ($subjects as $subject) {
          if (isset($seen[spl_object_id($subject)])) { continue; }
          $seen[spl_object_id($subject)] = true;
          $origin = $resolveOrigin($subject, $screen ? AnimationTargetPosition::SCREEN : $position);
          if ($origin === null) { continue; }
          $recipient = $anchor === 'caster'
            ? array_find($playback->targets, static fn($target): bool => $target !== $playback->actor) : $subject;
          $casterOrigin = $resolveOrigin($playback->actor, AnimationTargetPosition::CENTER);
          $recipientOrigin = $recipient === null ? null
            : $resolveOrigin($recipient, AnimationTargetPosition::CENTER);
          $oriented = $screen || $casterOrigin === null || $recipientOrigin === null ? $command
            : BattleEffectDirection::orientCommand($command, $casterOrigin['x'], $recipientOrigin['x']);
          array_push($draws, ...self::getCommandDraws($oriented, $origin));
        }
      }
    }
    foreach ($draws as &$draw) { $draw = ['text' => $draw['text']] + $this->getIndicatorPosition($draw['text'], $draw['x'], $draw['y']); }
    unset($draw);
    return ['draws' => $draws, 'flashes' => array_values($flashes)];
  }

  public function getLegacyDraws(array $command, array $offset = ['x' => 0, 'y' => 0]): array
  {
    return self::getCommandDraws($command, ['x' => intval($this->origin->x) + self::LEGACY_SCREEN_INSET + $offset['x'],
      'y' => intval($this->origin->y) + self::LEGACY_SCREEN_INSET + $offset['y']], true);
  }

  private static function getCommandDraws(array $command, array $origin, bool $skipEmpty = false): array
  {
    if (!($command['visible'] ?? true)) { return []; }
    $draws = [];
    $position = $command['position'] ?? [];
    foreach (self::getCommandLines($command) as $row => $line) {
      if ($skipEmpty && $line === '') { continue; }
      $draws[] = ['text' => self::formatLine($line, self::resolveNamedColor($command['color'] ?? null)),
        'x' => $origin['x'] + intval($position['x'] ?? $position[0] ?? 0),
        'y' => $origin['y'] + intval($position['y'] ?? $position[1] ?? 0) + $row];
    }
    return $draws;
  }

  public static function getCommandLines(array $command): array
  {
    // Leading spaces belong to authored multi-line art, not padding to trim.
    $content = trim(strval($command['content'] ?? ''), "\r\n");
    if (trim($content) === '') {
      $asset = trim(strval($command['assetId'] ?? ''));
      $content = $asset !== '' ? '[' . strtoupper($asset) . ']' : '';
    }
    return preg_split('/\r?\n/', $content) ?: [];
  }

  public static function resolveNamedColor(?string $name): ?Color
  {
    return $name === null ? null : array_find(Color::cases(), static fn(Color $color): bool => strtolower($color->name) === strtolower(trim($name)));
  }

  public static function formatLine(string $line, ?Color $color): string
  {
    return $color === null ? $line : $color->value . $line . Color::RESET->value;
  }

  /**
   * @param callable(CharacterInterface): ?array $resolveAnchor
   * @param callable(CharacterInterface): int $resolveWidth Visible sprite width, after troop clipping.
   * @return list<array{left: int, top: int, right: int, bottom: int, color: Color}>
   */
  public function getFlashRegions(array $flashes, callable $resolveAnchor, callable $resolveWidth): array
  {
    $regions = [];
    foreach ($flashes as $flash) {
      $x = intval($this->origin->x) + 1;
      $y = intval($this->origin->y) + 1;
      $width = max(1, $this->width - 2);
      $height = max(1, $this->height - 2);
      if (!$flash['screen']) {
        $target = $flash['target'];
        $anchor = $resolveAnchor($target);
        if ($anchor === null) { continue; }
        $sprite = $target instanceof Character ? $target->images->battle : $target->image;
        $width = $resolveWidth($target);
        if ($target instanceof Enemy && $width < 1) { continue; }
        $width = max(1, $width);
        $height = max(1, count($sprite));
        $x = $anchor['x'] - intdiv($width, 2);
        $y = $anchor['y'] + 1;
      }
      $regions[] = ['left' => $x, 'top' => $y, 'right' => $x + $width, 'bottom' => $y + $height, 'color' => $flash['color']];
    }
    return $regions;
  }

  /** Builds the same foreground-only flash overlay used by the live Console layer. */
  public static function composeFlashOverlay(array $buffer, array $regions, int $width, int $height): ?array
  {
    $clipped = [];
    foreach ($regions as $region) {
      $region['left'] = max(0, $region['left']);
      $region['top'] = max(0, $region['top']);
      $region['right'] = min($width, $region['right']);
      $region['bottom'] = min($height, $region['bottom']);
      if ($region['left'] < $region['right'] && $region['top'] < $region['bottom']) { $clipped[] = $region; }
    }
    if ($clipped === []) { return null; }
    $left = min(array_column($clipped, 'left'));
    $right = max(array_column($clipped, 'right'));
    $top = min(array_column($clipped, 'top'));
    $bottom = max(array_column($clipped, 'bottom'));
    $rows = [];
    for ($row = $top; $row < $bottom; $row++) { $rows[$row] = NormalizedRow::fromText($buffer[$row] ?? '', $width)->cells; }
    do {
      $previousLeft = $left;
      $previousRight = $right;
      foreach ($rows as $cells) {
        while ($left > 0 && ($cells[$left] ?? '') === NormalizedRow::CONTINUATION) { $left--; }
        while ($right < $width && ($cells[$right] ?? '') === NormalizedRow::CONTINUATION) { $right++; }
      }
    } while ($left !== $previousLeft || $right !== $previousRight);
    $lines = [];
    for ($row = $top; $row < $bottom; $row++) {
      $line = '';
      for ($column = $left; $column < $right; $column++) {
        $cell = $rows[$row][$column] ?? ' ';
        if ($cell === NormalizedRow::CONTINUATION) { continue; }
        $span = NormalizedRow::symbolWidth($cell);
        $color = null;
        foreach ($clipped as $region) {
          if ($row >= $region['top'] && $row < $region['bottom'] && $column >= $region['left'] && $column + $span <= $region['right']) {
            $color = $region['color'];
          }
        }
        $line .= $color === null ? $cell : self::formatLine(TerminalText::stripAnsi($cell), $color);
      }
      $lines[] = $line;
    }
    return ['lines' => $lines, 'x' => $left, 'y' => $top];
  }
}
