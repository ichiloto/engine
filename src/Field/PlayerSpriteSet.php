<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;

/**
 * Represents the directional sprite set used by the field player.
 *
 * The engine keeps movement logic keyed by headings, while example games are
 * free to author the actual sprite glyphs however they like. This class is the
 * bridge between those two concerns.
 *
 * @package Ichiloto\Engine\Field
 */
class PlayerSpriteSet
{
  /**
   * Creates a new player sprite set.
   *
   * @param string[] $north The north-facing sprite.
   * @param string[] $east The east-facing sprite.
   * @param string[] $south The south-facing sprite.
   * @param string[] $west The west-facing sprite.
   */
  public function __construct(
    protected(set) array $north = ['^'],
    protected(set) array $east = ['>'],
    protected(set) array $south = ['v'],
    protected(set) array $west = ['<'],
  )
  {
  }

  /**
   * Builds a player sprite set from configuration data.
   *
   * @param array<string, mixed> $data The sprite configuration data.
   * @return self The normalized sprite set.
   */
  public static function fromArray(array $data): self
  {
    $sprites = is_array($data['sprites'] ?? null) ? $data['sprites'] : $data;

    return new self(
      north: self::normalizeSprite($sprites['north'] ?? ['^']),
      east: self::normalizeSprite($sprites['east'] ?? ['>']),
      south: self::normalizeSprite($sprites['south'] ?? ['v']),
      west: self::normalizeSprite($sprites['west'] ?? ['<']),
    );
  }

  /**
   * Returns the sprite for the requested heading.
   *
   * @param MovementHeading $heading The heading to resolve.
   * @return string[] The sprite rows for that heading.
   */
  public function getSpriteForHeading(MovementHeading $heading): array
  {
    return match ($heading) {
      MovementHeading::NORTH => $this->north,
      MovementHeading::EAST => $this->east,
      MovementHeading::SOUTH => $this->south,
      MovementHeading::WEST => $this->west,
      default => $this->south,
    };
  }

  /**
   * Resolves a heading from a concrete sprite.
   *
   * @param string[]|string $sprite The sprite rows to inspect.
   * @return MovementHeading The heading that owns the sprite, if any.
   */
  public function resolveHeading(array|string $sprite): MovementHeading
  {
    $sprite = self::normalizeSprite($sprite);

    return match (true) {
      $sprite === $this->north => MovementHeading::NORTH,
      $sprite === $this->east => MovementHeading::EAST,
      $sprite === $this->south => MovementHeading::SOUTH,
      $sprite === $this->west => MovementHeading::WEST,
      default => MovementHeading::NONE,
    };
  }

  /**
   * Returns the sprite set as plain array data.
   *
   * @return array{north: string[], east: string[], south: string[], west: string[]}
   */
  public function toArray(): array
  {
    return [
      'north' => $this->north,
      'east' => $this->east,
      'south' => $this->south,
      'west' => $this->west,
    ];
  }

  /**
   * Normalizes a configured sprite into a row array.
   *
   * @param string[]|string $sprite The configured sprite.
   * @return string[] The normalized sprite rows.
   */
  public static function normalizeSprite(array|string $sprite): array
  {
    if (is_array($sprite)) {
      return array_values(array_map(
        static fn(mixed $row): string => self::sanitizeSpriteRow((string)$row),
        $sprite
      ));
    }

    return [self::sanitizeSpriteRow((string)$sprite)];
  }

  /**
   * Reduces composite emoji to their terminal-safe base glyph.
   *
   * Skin-tone modifiers and ZWJ sequences (e.g. "🏃🏽‍➡️") advance the terminal
   * cursor by a different number of cells than the engine's grapheme-based
   * width accounting, leaving unerased glyph fragments on the map. Only the
   * base code point of such sequences renders one predictable double-width
   * cell pair across terminals.
   *
   * @param string $row The sprite row to sanitize.
   * @return string The sanitized sprite row.
   */
  public static function sanitizeSpriteRow(string $row): string
  {
    if (preg_match_all('/\X/u', $row, $matches) === false) {
      return $row;
    }

    $sanitized = '';

    foreach ($matches[0] as $grapheme) {
      $codepoints = preg_split('//u', $grapheme, -1, PREG_SPLIT_NO_EMPTY) ?: [];

      if (count($codepoints) < 2 || ! self::containsUnsafeEmojiComponent($codepoints)) {
        $sanitized .= $grapheme;
        continue;
      }

      $sanitized .= $codepoints[0];

      // Keep an immediately following variation selector so emoji that need
      // VS16 for emoji presentation retain it.
      if (isset($codepoints[1]) && $codepoints[1] === "\u{FE0F}") {
        $sanitized .= $codepoints[1];
      }
    }

    return $sanitized;
  }

  /**
   * Determines whether the code points contain width-unstable emoji components.
   *
   * @param string[] $codepoints The grapheme's code points.
   * @return bool True when a ZWJ or skin-tone modifier is present.
   */
  protected static function containsUnsafeEmojiComponent(array $codepoints): bool
  {
    foreach ($codepoints as $codepoint) {
      if ($codepoint === "\u{200D}") {
        return true;
      }

      $ordinal = mb_ord($codepoint, 'UTF-8');

      if ($ordinal >= 0x1F3FB && $ordinal <= 0x1F3FF) {
        return true;
      }
    }

    return false;
  }
}
