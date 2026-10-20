<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/**
 * How a renderer presents detail-sheet fields. Canvas reads it from the project's menu theme (`details`);
 * Terminal uses the Engine's Terminal style. Owners never format labels, punctuation or alignment.
 */
final readonly class MenuDetailStyle
{
  public const array KEYS = ['labelSuffix', 'gapCells', 'minValueCells', 'stackedIndentCells', 'valueAlignment',
    'stackWrappedValues', 'stackedValueAlignment', 'labelColor', 'valueColor', 'separators', 'recordMinHeight'];

  /**
   * @param string $labelSuffix Text attached to each field label, such as Terminal's colon.
   * @param int $minValueCells A narrower value column places the label on its own line above the value.
   * @param string $labelColor Theme colour role for field labels.
   * @param string $valueColor Theme colour role for field values the owner does not colour itself.
   * @param bool $stackWrappedValues A value needing more than one line sits under its label instead of beside it.
   * @param bool $separators Whether each field closes with the theme's data-record separator.
   * @param int $recordMinHeight Minimum field record height in the renderer's line unit (Canvas pixels); text centres in it.
   */
  public function __construct(
    public string $labelSuffix = '',
    public int $gapCells = 1,
    public int $minValueCells = 12,
    public int $stackedIndentCells = 2,
    public HorizontalAlignment $valueAlignment = HorizontalAlignment::LEFT,
    public bool $stackWrappedValues = false,
    public HorizontalAlignment $stackedValueAlignment = HorizontalAlignment::LEFT,
    public string $labelColor = 'text',
    public string $valueColor = 'text',
    public bool $separators = true,
    public int $recordMinHeight = 0,
  ) {
    if (str_contains($labelSuffix, "\n") || $gapCells < 0 || $gapCells > 8 || $minValueCells < 1 || $minValueCells > 64
      || $stackedIndentCells < 0 || $stackedIndentCells > 16 || $valueAlignment === HorizontalAlignment::CENTER
      || $stackedValueAlignment === HorizontalAlignment::CENTER || $labelColor === '' || $valueColor === ''
      || $recordMinHeight < 0 || $recordMinHeight > 256) {
      throw new InvalidArgumentException('Detail style requires a one-line suffix, bounded cells and heights, left or right values and colour roles.');
    }
  }

  /** Terminal has no record separators, so it names each field with an attached suffix. */
  public static function createTerminal(): self
  {
    return new self(TerminalMenuRow::LABEL_SUFFIX, separators: false);
  }

  /** @param array<string, mixed> $data A theme's `details` block; omitted keys keep the Engine default. */
  public static function createFromArray(array $data): self
  {
    if (array_diff(array_keys($data), self::KEYS) !== []) { throw new InvalidArgumentException('Unsupported menu details field.'); }
    $types = ['labelSuffix' => 'string', 'labelColor' => 'string', 'valueColor' => 'string', 'gapCells' => 'integer',
      'minValueCells' => 'integer', 'stackedIndentCells' => 'integer', 'recordMinHeight' => 'integer',
      'stackWrappedValues' => 'boolean', 'separators' => 'boolean', 'valueAlignment' => 'string', 'stackedValueAlignment' => 'string'];
    foreach ($data as $key => $value) {
      if (gettype($value) !== $types[$key]) { throw new InvalidArgumentException("Menu details {$key} must be a {$types[$key]}."); }
    }
    foreach (['valueAlignment', 'stackedValueAlignment'] as $key) {
      if (isset($data[$key])) { $data[$key] = self::createAlignment($key, $data[$key]); }
    }
    return new self(...$data);
  }

  private static function createAlignment(string $key, string $value): HorizontalAlignment
  {
    return match ($value) {
      'left' => HorizontalAlignment::LEFT,
      'right' => HorizontalAlignment::RIGHT,
      default => throw new InvalidArgumentException("Menu details {$key} must be left or right."),
    };
  }
}
