<?php

namespace Ichiloto\Engine\Field;

use Ichiloto\Engine\Core\Enumerations\MovementHeading;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

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

    // A project may author alternates for terminals that cannot compose ZWJ
    // emoji, so composing terminals keep the richer directional art while
    // everything else still gets four distinct sprites.
    if (! self::allowsCompositeEmoji()) {
      // An authored alternate always wins; otherwise derive one so composite
      // sprites never collapse into each other on this terminal.
      $authoredFallbacks = is_array($data['fallbackSprites'] ?? null)
        ? array_filter($data['fallbackSprites'], static fn(mixed $sprite): bool => $sprite !== null && $sprite !== '')
        : [];

      foreach (['north', 'east', 'south', 'west'] as $direction) {
        if (isset($authoredFallbacks[$direction])) {
          $sprites[$direction] = $authoredFallbacks[$direction];
          continue;
        }

        $authored = $sprites[$direction] ?? null;

        if ($authored !== null && self::isComposite($authored)) {
          $sprites[$direction] = self::deriveDirectionalFallback((array) $authored, $direction);
        }
      }
    }

    $set = new self(
      north: self::normalizeSprite($sprites['north'] ?? ['^']),
      east: self::normalizeSprite($sprites['east'] ?? ['>']),
      south: self::normalizeSprite($sprites['south'] ?? ['v']),
      west: self::normalizeSprite($sprites['west'] ?? ['<']),
    );

    $set->warnAboutCollapsedDirections();

    return $set;
  }

  /**
   * Warns when two directions ended up sharing a sprite.
   *
   * Composite (ZWJ) emoji are reduced to their base glyph unless the project
   * opts in, which can silently make two directions identical — the
   * east-facing runner and the plain runner both become "🏃". The player then
   * sees one glyph for two directions and heading resolution becomes
   * ambiguous, so say so rather than failing quietly.
   *
   * @return bool True when a collapse was detected.
   */
  public function warnAboutCollapsedDirections(): bool
  {
    $bySprite = [];

    foreach (['north' => $this->north, 'east' => $this->east, 'south' => $this->south, 'west' => $this->west] as $direction => $sprite) {
      $bySprite[implode("\n", $sprite)][] = $direction;
    }

    $collapsed = array_filter($bySprite, static fn(array $directions): bool => count($directions) > 1);

    foreach ($collapsed as $sprite => $directions) {
      Debug::warn(sprintf(
        'Player sprites for %s render identically ("%s"). Composite emoji need the %s project setting; without it they reduce to their base glyph.',
        implode(' and ', $directions),
        $sprite,
        self::CONFIG_ALLOW_COMPOSITE_EMOJI
      ));
    }

    return ! empty($collapsed);
  }

  /**
   * Determines whether a sprite contains a composite (ZWJ) sequence.
   *
   * @param array|string $sprite The authored sprite.
   * @return bool True when the sprite needs composition support.
   */
  public static function isComposite(array|string $sprite): bool
  {
    foreach ((array) $sprite as $row) {
      if (preg_match('/\x{200D}/u', (string) $row) === 1) {
        return true;
      }
    }

    return false;
  }

  /**
   * Derives a terminal-safe sprite for a direction.
   *
   * When the terminal cannot compose ZWJ sequences, a directional emoji such
   * as "🏃‍➡️" would either smear the row or reduce to the same glyph as
   * another direction. Instead the base emoji keeps its two columns and an
   * ASCII marker states the direction, so all four stay distinguishable and
   * the width stays predictable.
   *
   * @param string[] $sprite The authored sprite rows.
   * @param string $direction The direction key (north/east/south/west).
   * @return string[] The derived sprite rows.
   */
  public static function deriveDirectionalFallback(array $sprite, string $direction): array
  {
    $marker = match ($direction) {
      'north' => '^',
      'east' => '>',
      'south' => 'v',
      'west' => '<',
      default => '',
    };

    if ($marker === '') {
      return $sprite;
    }

    $rows = [];

    foreach ($sprite as $index => $row) {
      // Only the first row carries the marker; taller sprites keep their
      // remaining rows untouched.
      $rows[$index] = $index === 0 ? self::baseGlyph((string) $row) . $marker : (string) $row;
    }

    return $rows === [] ? $sprite : $rows;
  }

  /**
   * Reduces a grapheme to its base code point.
   *
   * @param string $sprite The sprite row.
   * @return string The base glyph.
   */
  protected static function baseGlyph(string $sprite): string
  {
    $codepoints = preg_split('//u', $sprite, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    return $codepoints[0] ?? $sprite;
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
   * Resolves configured spawn data into concrete sprite rows.
   *
   * Spawn data may name a heading ("South") instead of spelling out the art,
   * which is what keeps map files free of glyphs: change the project's sprite
   * set and every spawn point follows, with nothing to migrate.
   *
   * @param string[]|string $sprite The configured sprite rows, or a heading name.
   * @return string[] The sprite rows to display.
   */
  public function resolveSprite(array|string $sprite): array
  {
    $heading = self::headingFromName($sprite);

    return $heading !== null
      ? $this->getSpriteForHeading($heading)
      : self::normalizeSprite($sprite);
  }

  /**
   * Reads a heading out of spawn data that names one.
   *
   * Names are matched case insensitively, so 'South', 'south', and
   * MovementHeading::SOUTH->value all mean the same thing. Anything else,
   * including every sprite glyph, resolves to null.
   *
   * @param string[]|string $sprite The configured sprite rows, or a heading name.
   * @return MovementHeading|null The named heading, or null when the value is art.
   */
  public static function headingFromName(array|string $sprite): ?MovementHeading
  {
    $rows = array_values(array_filter(
      is_array($sprite) ? $sprite : [$sprite],
      static fn(mixed $row): bool => is_string($row) && trim($row) !== ''
    ));

    if (count($rows) !== 1) {
      return null;
    }

    $heading = MovementHeading::tryFrom(ucfirst(strtolower(trim($rows[0]))));

    return $heading === MovementHeading::NONE ? null : $heading;
  }

  /**
   * Resolves a heading from a concrete sprite.
   *
   * @param string[]|string $sprite The sprite rows to inspect.
   * @return MovementHeading The heading that owns the sprite, if any.
   */
  public function resolveHeading(array|string $sprite): MovementHeading
  {
    if (($named = self::headingFromName($sprite)) !== null) {
      return $named;
    }

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
   * The project config path of the composite emoji opt-in.
   */
  public const string CONFIG_ALLOW_COMPOSITE_EMOJI = 'graphics.sprites.allow_composite_emoji';

  /**
   * Normalizes a configured sprite into a row array.
   *
   * Composite emoji (skin tones, ZWJ sequences such as the right-facing
   * runner) are reduced to their base glyph unless the project opts in via
   * `graphics.sprites.allow_composite_emoji` — see sanitizeSpriteRow() for
   * why the safe default reduces them.
   *
   * @param string[]|string $sprite The configured sprite.
   * @return string[] The normalized sprite rows.
   */
  public static function normalizeSprite(array|string $sprite): array
  {
    $normalizeRow = self::allowsCompositeEmoji()
      ? static fn(mixed $row): string => (string)$row
      : static fn(mixed $row): string => self::sanitizeSpriteRow((string)$row);

    if (is_array($sprite)) {
      return array_values(array_map($normalizeRow, $sprite));
    }

    return [$normalizeRow($sprite)];
  }

  /**
   * Whether the project allows composite emoji sprites.
   *
   * Directional emoji variants (e.g. "🏃‍➡️", the runner facing right) only
   * exist as ZWJ sequences, so games that want direction-consistent art must
   * opt in. The opt-in requires a terminal that composes ZWJ sequences into a
   * single double-width glyph (Windows Terminal, iTerm2, kitty, and other
   * modern emulators do); on terminals that render the components separately,
   * composite sprites leave glyph fragments on the map.
   *
   * @return bool True when composite emoji sprites are allowed.
   */
  protected static function allowsCompositeEmoji(): bool
  {
    return \Ichiloto\Engine\IO\Console\TerminalCapabilities::supportsCompositeEmoji();
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
