<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Presentation\PresentationSpriteBob;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * How a game makes every cue mark readable on any ground: event cues, quest
 * waymarks and their edge markers. Authored as `cueStyle` in
 * `Data/Presentation/field.php`.
 *
 * Graphics: an optional gentle bob (held still under reduced motion), and how far an edge
 * marker's pointer sits from its mark across and up or down. The mark's own outline belongs to its
 * artwork. Terminal: a contrasting background cell and bold, so a glyph
 * reads on coloured terrain.
 */
final readonly class FieldCueStyle
{
  public const array FIELDS = ['bob', 'pointerSpacing', 'terminal'];
  public const array SPACING_FIELDS = ['x', 'y'];
  public const array BOB_FIELDS = ['height', 'seconds'];
  public const array TERMINAL_FIELDS = ['background', 'bold'];
  public const int MAX_BOB_HEIGHT = 12;
  public const float MIN_BOB_SECONDS = 0.4;
  public const float MAX_BOB_SECONDS = 4.0;
  public const int MAX_POINTER_SPACING = 96;

  /**
   * @param int $bobHeight Logical pixels a graphical mark rises at the top of its bob; 0 holds it still.
   * @param float $bobSeconds One full bob.
   * @param int $pointerSpacingX Logical pixels across from an edge mark's centre to its pointer's centre.
   * @param int $pointerSpacingY Logical pixels up or down from an edge mark's centre to its pointer's centre; a tall
   *   mark needs more than a wide one. A diagonal pointer takes both, scaled by its direction.
   * @param string|null $terminalBackground The background colour behind a cue glyph, or none.
   * @param bool $terminalBold Whether a cue glyph is bold.
   */
  public function __construct(
    public int $bobHeight = 0,
    public float $bobSeconds = 1.2,
    public int $pointerSpacingX = 24,
    public int $pointerSpacingY = 30,
    public ?string $terminalBackground = 'black',
    public bool $terminalBold = true,
  )
  {
    if ($this->bobHeight < 0 || $this->bobHeight > self::MAX_BOB_HEIGHT) {
      throw new InvalidArgumentException('A cue bob height must be 0..12 logical pixels.');
    }
    if (!is_finite($this->bobSeconds) || $this->bobSeconds < self::MIN_BOB_SECONDS || $this->bobSeconds > self::MAX_BOB_SECONDS) {
      throw new InvalidArgumentException('A cue bob must last 0.4..4 seconds.');
    }
    foreach ([$this->pointerSpacingX, $this->pointerSpacingY] as $spacing) {
      if ($spacing < 0 || $spacing > self::MAX_POINTER_SPACING) {
        throw new InvalidArgumentException('Cue pointer spacing must be 0..96 logical pixels.');
      }
    }
    if ($this->terminalBackground !== null) {
      try {
        new OutputFormatterStyle(null, $this->terminalBackground);
      } catch (InvalidArgumentException $exception) {
        throw new InvalidArgumentException(sprintf('Invalid cue background colour "%s".', $this->terminalBackground), previous: $exception);
      }
    }
  }

  /** @param mixed $data The authored `cueStyle`, or null for the Engine default. */
  public static function fromArray(mixed $data): self
  {
    if ($data === null) { return new self(); }
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException('cueStyle accepts bob, pointerSpacing and terminal; a plate belongs to its cue colour.');
    }
    $bob = $data['bob'] ?? null;
    if ($bob !== null && (!is_array($bob) || array_diff(array_keys($bob), self::BOB_FIELDS) !== []
      || !is_int($bob['height'] ?? 0) || !(is_int($bob['seconds'] ?? 1) || is_float($bob['seconds'] ?? 1.0)))) {
      throw new InvalidArgumentException('A cue bob needs an integer height and a number of seconds.');
    }
    $spacing = $data['pointerSpacing'] ?? [];
    if (!is_array($spacing) || array_diff(array_keys($spacing), self::SPACING_FIELDS) !== []
      || !is_int($spacing['x'] ?? 24) || !is_int($spacing['y'] ?? 30)) {
      throw new InvalidArgumentException('Cue pointer spacing is whole logical pixels x and y.');
    }
    $terminal = $data['terminal'] ?? [];
    if (!is_array($terminal) || array_diff(array_keys($terminal), self::TERMINAL_FIELDS) !== []) {
      throw new InvalidArgumentException('Cue terminal styling accepts background and bold.');
    }
    $background = array_key_exists('background', $terminal) ? $terminal['background'] : 'black';
    if ($background !== null && !is_string($background)) { throw new InvalidArgumentException('A cue background is a colour name or null.'); }
    $bold = $terminal['bold'] ?? true;
    if (!is_bool($bold)) { throw new InvalidArgumentException('Cue bold is true or false.'); }

    return new self((int)($bob['height'] ?? 0), (float)($bob['seconds'] ?? 1.2),
      $spacing['x'] ?? 24, $spacing['y'] ?? 30, $background === null ? null : trim($background), $bold);
  }

  /** Formatter markup for a cue glyph in its colour, on the style's background. */
  public function styleGlyph(string $symbol, string $color): string
  {
    $options = array_filter(['fg=' . $color, $this->terminalBackground === null ? null : 'bg=' . $this->terminalBackground,
      $this->terminalBold ? 'options=bold' : null]);

    return sprintf('<%s>%s</>', implode(';', $options), $symbol);
  }

  /**
   * The bob a mark declares to a renderer that draws it at display rate, at
   * the given scale (an edge marker is drawn at the field's zoom); none
   * under reduced motion or when the style does not bob.
   */
  public function getBob(bool $reducedMotion, float $scale = 1.0): ?PresentationSpriteBob
  {
    if ($reducedMotion || $this->bobHeight === 0) { return null; }

    return new PresentationSpriteBob(max(1, (int)round($this->bobHeight * $scale)), $this->bobSeconds);
  }

  /**
   * How many logical pixels a mark rises now, in whole pixels, for a
   * renderer that cannot draw a declared bob: the same rise and fall,
   * still under reduced motion or when the style does not bob.
   */
  public function getBobLift(float $seconds, bool $reducedMotion): int
  {
    return (int)round($this->getBob($reducedMotion)?->getOffset($seconds) ?? 0);
  }
}
