<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Animations\Field;

use Ichiloto\Engine\IO\Console\TerminalText;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatterStyle;

/**
 * A game's objective waymark style, authored as `waymarks` in
 * `Data/Presentation/field.php`.
 *
 * The colour is a terminal colour and also selects the graphical art: the
 * cue binding of the same colour supplies the marker effect and its eight
 * directional edge images. The inset keeps edge arrows that many cells in
 * from the view edge.
 */
final readonly class FieldWaymarkStyle
{
  public const array FIELDS = ['color', 'symbol', 'inset'];
  public const int MAX_INSET = 8;
  /** Terminal edge arrows, keyed like the graphical edge images. */
  public const array ARROWS = [
    'east' => '→', 'southeast' => '↘', 'south' => '↓', 'southwest' => '↙',
    'west' => '←', 'northwest' => '↖', 'north' => '↑', 'northeast' => '↗',
  ];

  public function __construct(
    public string $color = 'bright-yellow',
    public string $symbol = '!',
    public int $inset = 0,
  )
  {
    if (TerminalText::symbolCount($this->symbol) !== 1 || TerminalText::displayWidth($this->symbol) !== 1) {
      throw new InvalidArgumentException('The waymark symbol must occupy exactly one terminal cell.');
    }
    try {
      new OutputFormatterStyle($this->color);
    } catch (InvalidArgumentException $exception) {
      throw new InvalidArgumentException(sprintf('Invalid waymark color "%s".', $this->color), previous: $exception);
    }
    if ($this->inset < 0 || $this->inset > self::MAX_INSET) {
      throw new InvalidArgumentException('The waymark inset must be 0..8 cells.');
    }
  }

  /** @param mixed $data The authored `waymarks` entry, or null for the Engine default. */
  public static function fromArray(mixed $data): self
  {
    if ($data === null) { return new self(); }
    if (!is_array($data) || array_diff(array_keys($data), self::FIELDS) !== []) {
      throw new InvalidArgumentException('Field waymarks accept color, symbol and inset.');
    }
    $color = $data['color'] ?? 'bright-yellow';
    $symbol = $data['symbol'] ?? '!';
    $inset = $data['inset'] ?? 0;
    if (!is_string($color) || !is_string($symbol) || !is_int($inset)) {
      throw new InvalidArgumentException('Field waymarks need a string color and symbol and an integer inset.');
    }
    return new self(trim($color), trim($symbol), $inset);
  }

  /** Formatter markup for the on-screen marker, with the game's cue treatment. */
  public function styleSymbol(FieldCueStyle $cueStyle = new FieldCueStyle()): string
  {
    return $cueStyle->styleGlyph($this->symbol, $this->color);
  }

  /** Formatter markup for an edge arrow pointing the given compass direction, with the game's cue treatment. */
  public function styleArrow(string $direction, FieldCueStyle $cueStyle = new FieldCueStyle()): string
  {
    $arrow = self::ARROWS[$direction] ?? throw new InvalidArgumentException("Unknown edge direction '{$direction}'.");
    return $cueStyle->styleGlyph($arrow, $this->color);
  }
}
