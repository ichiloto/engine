<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\Rendering\Sprites\SpriteValidation;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * How a game makes every cue mark readable on any ground: event cues, quest
 * waymarks and their edge markers. Authored as `cueStyle` in
 * `Data/Presentation/field.php`.
 *
 * Graphics: an optional plate image drawn behind the mark, an optional
 * gentle bob (held still under reduced motion), and how far an edge
 * marker's pointer sits from its mark. The mark's own outline belongs to its
 * artwork. Terminal: a contrasting background cell and bold, so a glyph
 * reads on coloured terrain.
 */
final readonly class FieldCueStyle
{
  public const array FIELDS = ['plate', 'bob', 'pointerDistance', 'terminal'];
  public const array BOB_FIELDS = ['height', 'seconds'];
  public const array TERMINAL_FIELDS = ['background', 'bold'];
  public const int MAX_BOB_HEIGHT = 12;
  public const float MIN_BOB_SECONDS = 0.4;
  public const float MAX_BOB_SECONDS = 4.0;
  public const int MAX_POINTER_DISTANCE = 96;

  /**
   * @param string|null $plate Asset-relative PNG drawn behind each graphical mark, or none.
   * @param int $bobHeight Logical pixels a graphical mark rises at the top of its bob; 0 holds it still.
   * @param float $bobSeconds One full bob.
   * @param int $pointerDistance Logical pixels from an edge marker's centre to its pointer's centre.
   * @param string|null $terminalBackground The background colour behind a cue glyph, or none.
   * @param bool $terminalBold Whether a cue glyph is bold.
   */
  public function __construct(
    public ?string $plate = null,
    public int $bobHeight = 0,
    public float $bobSeconds = 1.2,
    public int $pointerDistance = 30,
    public ?string $terminalBackground = 'black',
    public bool $terminalBold = true,
  )
  {
    if ($this->plate !== null) { SpriteValidation::validateAssetPath($this->plate); }
    if ($this->bobHeight < 0 || $this->bobHeight > self::MAX_BOB_HEIGHT) {
      throw new InvalidArgumentException('A cue bob height must be 0..12 logical pixels.');
    }
    if (!is_finite($this->bobSeconds) || $this->bobSeconds < self::MIN_BOB_SECONDS || $this->bobSeconds > self::MAX_BOB_SECONDS) {
      throw new InvalidArgumentException('A cue bob must last 0.4..4 seconds.');
    }
    if ($this->pointerDistance < 0 || $this->pointerDistance > self::MAX_POINTER_DISTANCE) {
      throw new InvalidArgumentException('A cue pointer distance must be 0..96 logical pixels.');
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
      throw new InvalidArgumentException('cueStyle accepts plate, bob, pointerDistance and terminal.');
    }
    $plate = $data['plate'] ?? null;
    if ($plate !== null && !is_string($plate)) { throw new InvalidArgumentException('A cue plate is an asset path.'); }
    $bob = $data['bob'] ?? null;
    if ($bob !== null && (!is_array($bob) || array_diff(array_keys($bob), self::BOB_FIELDS) !== []
      || !is_int($bob['height'] ?? 0) || !(is_int($bob['seconds'] ?? 1) || is_float($bob['seconds'] ?? 1.0)))) {
      throw new InvalidArgumentException('A cue bob needs an integer height and a number of seconds.');
    }
    $distance = $data['pointerDistance'] ?? 30;
    if (!is_int($distance)) { throw new InvalidArgumentException('A cue pointer distance is whole logical pixels.'); }
    $terminal = $data['terminal'] ?? [];
    if (!is_array($terminal) || array_diff(array_keys($terminal), self::TERMINAL_FIELDS) !== []) {
      throw new InvalidArgumentException('Cue terminal styling accepts background and bold.');
    }
    $background = array_key_exists('background', $terminal) ? $terminal['background'] : 'black';
    if ($background !== null && !is_string($background)) { throw new InvalidArgumentException('A cue background is a colour name or null.'); }
    $bold = $terminal['bold'] ?? true;
    if (!is_bool($bold)) { throw new InvalidArgumentException('Cue bold is true or false.'); }

    return new self($plate === null ? null : trim($plate), (int)($bob['height'] ?? 0), (float)($bob['seconds'] ?? 1.2),
      $distance, $background === null ? null : trim($background), $bold);
  }

  /** Formatter markup for a cue glyph in its colour, on the style's background. */
  public function styleGlyph(string $symbol, string $color): string
  {
    $options = array_filter(['fg=' . $color, $this->terminalBackground === null ? null : 'bg=' . $this->terminalBackground,
      $this->terminalBold ? 'options=bold' : null]);

    return sprintf('<%s>%s</>', implode(';', $options), $symbol);
  }

  /**
   * How many logical pixels a mark rises now: a smooth rise and fall, still
   * under reduced motion or when the style does not bob.
   */
  public function getBobLift(float $seconds, bool $reducedMotion): int
  {
    if ($reducedMotion || $this->bobHeight === 0) { return 0; }
    $phase = fmod(max(0.0, $seconds), $this->bobSeconds) / $this->bobSeconds;

    return (int)round($this->bobHeight * (1 - cos(2 * M_PI * $phase)) / 2);
  }
}
