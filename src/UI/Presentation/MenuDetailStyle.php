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
    'labelColor', 'valueColor', 'separators'];

  /**
   * @param string $labelSuffix Text attached to each field label, such as Terminal's colon.
   * @param int $minValueCells A narrower value column places the label on its own line above the value.
   * @param string $labelColor Theme colour role for field labels.
   * @param string $valueColor Theme colour role for field values the owner does not colour itself.
   * @param bool $separators Whether each field closes with the theme's data-record separator.
   */
  public function __construct(
    public string $labelSuffix = '',
    public int $gapCells = 1,
    public int $minValueCells = 12,
    public int $stackedIndentCells = 2,
    public HorizontalAlignment $valueAlignment = HorizontalAlignment::LEFT,
    public string $labelColor = 'text',
    public string $valueColor = 'text',
    public bool $separators = true,
  ) {
    if (str_contains($labelSuffix, "\n") || $gapCells < 0 || $gapCells > 8 || $minValueCells < 1 || $minValueCells > 64
      || $stackedIndentCells < 0 || $stackedIndentCells > 16 || $valueAlignment === HorizontalAlignment::CENTER
      || $labelColor === '' || $valueColor === '') {
      throw new InvalidArgumentException('Detail style requires a one-line suffix, bounded cells, left or right values and colour roles.');
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
    $alignment = $data['valueAlignment'] ?? 'left';
    if (!in_array($alignment, ['left', 'right'], true)) { throw new InvalidArgumentException('Menu details valueAlignment must be left or right.'); }
    foreach (['labelSuffix', 'labelColor', 'valueColor'] as $key) {
      if (isset($data[$key]) && !is_string($data[$key])) { throw new InvalidArgumentException("Menu details {$key} must be a string."); }
    }
    foreach (['gapCells', 'minValueCells', 'stackedIndentCells'] as $key) {
      if (isset($data[$key]) && !is_int($data[$key])) { throw new InvalidArgumentException("Menu details {$key} must be an integer."); }
    }
    if (isset($data['separators']) && !is_bool($data['separators'])) { throw new InvalidArgumentException('Menu details separators must be a boolean.'); }
    $default = new self();
    return new self($data['labelSuffix'] ?? $default->labelSuffix, $data['gapCells'] ?? $default->gapCells,
      $data['minValueCells'] ?? $default->minValueCells, $data['stackedIndentCells'] ?? $default->stackedIndentCells,
      $alignment === 'right' ? HorizontalAlignment::RIGHT : HorizontalAlignment::LEFT,
      $data['labelColor'] ?? $default->labelColor, $data['valueColor'] ?? $default->valueColor,
      $data['separators'] ?? $default->separators);
  }
}
