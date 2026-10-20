<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\UI\Text\TerminalMenuRow;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;
use InvalidArgumentException;

/**
 * How a renderer presents detail sheets. Canvas reads it from the project's menu theme (`details`);
 * Terminal uses the Engine's Terminal style. Owners never format labels, punctuation or alignment.
 */
final readonly class MenuDetailStyle
{
  public const array KEYS = ['labelSuffix', 'gapCells', 'minValueCells', 'stackedIndentCells', 'valueAlignment',
    'valuePlacement', 'stackWrappedValues', 'stackedValueAlignment', 'labelColor', 'valueColor', 'separators',
    'recordMinHeight', 'emptyValueText', 'headingColor', 'headingBackground', 'headingMinHeight'];
  public const array PLACEMENTS = ['beside', 'below'];

  /** @var array<string, HorizontalAlignment> By field kind value: text, number, icons. */
  public array $valueAlignment;
  /** @var array<string, string> By field kind value: 'beside' its label, or 'below' it. */
  public array $valuePlacement;

  /**
   * @param string $labelSuffix Text attached to each field label, such as Terminal's colon.
   * @param int $minValueCells A narrower value column places the label on its own line above the value.
   * @param array<string, HorizontalAlignment> $valueAlignment Per field kind; omitted kinds: numbers right, others left.
   * @param array<string, string> $valuePlacement Per field kind; omitted kinds sit beside their label.
   * @param bool $stackWrappedValues A value needing more than one line sits under its label instead of beside it.
   * @param string $labelColor Theme colour role for field labels.
   * @param string $valueColor Theme colour role for field values the owner does not colour itself.
   * @param bool $separators Whether each field closes with the theme's data-record separator.
   * @param int $recordMinHeight Minimum field record height in the renderer's line unit (Canvas pixels); text centres in it.
   * @param string $emptyValueText Shown for an empty icon list, which keeps its row.
   * @param ?string $headingBackground Theme colour role behind headings, unless the theme binds details.header artwork.
   * @param int $iconCells Cells one drawn icon occupies; the renderer derives it from its icon box.
   * @param list<string> $iconRoles Icon roles this renderer draws; other icons show their names.
   */
  public function __construct(
    public string $labelSuffix = '',
    public int $gapCells = 1,
    public int $minValueCells = 12,
    public int $stackedIndentCells = 2,
    array $valueAlignment = [],
    array $valuePlacement = [],
    public bool $stackWrappedValues = false,
    public HorizontalAlignment $stackedValueAlignment = HorizontalAlignment::LEFT,
    public string $labelColor = 'text',
    public string $valueColor = 'text',
    public bool $separators = true,
    public int $recordMinHeight = 0,
    public string $emptyValueText = '',
    public string $headingColor = 'accent',
    public ?string $headingBackground = null,
    public int $headingMinHeight = 0,
    public int $iconCells = 1,
    public array $iconRoles = [],
  ) {
    $kinds = [MenuDetailKind::TEXT->value, MenuDetailKind::NUMBER->value, MenuDetailKind::ICONS->value];
    if (array_diff(array_keys($valueAlignment), $kinds) !== [] || array_diff(array_keys($valuePlacement), $kinds) !== []
      || !array_all($valueAlignment, static fn($alignment) => $alignment instanceof HorizontalAlignment && $alignment !== HorizontalAlignment::CENTER)
      || !array_all($valuePlacement, static fn($placement) => in_array($placement, self::PLACEMENTS, true))) {
      throw new InvalidArgumentException('Detail value alignment and placement are keyed by text, number or icons.');
    }
    if (str_contains($labelSuffix, "\n") || str_contains($emptyValueText, "\n") || $gapCells < 0 || $gapCells > 8
      || $minValueCells < 1 || $minValueCells > 64 || $stackedIndentCells < 0 || $stackedIndentCells > 16
      || $stackedValueAlignment === HorizontalAlignment::CENTER || $labelColor === '' || $valueColor === ''
      || $headingColor === '' || $headingBackground === '' || $recordMinHeight < 0 || $recordMinHeight > 256
      || $headingMinHeight < 0 || $headingMinHeight > 256 || $iconCells < 1 || $iconCells > 16
      || !array_is_list($iconRoles) || !array_all($iconRoles, static fn($role): bool => is_string($role))) {
      throw new InvalidArgumentException('Detail style requires one-line texts, bounded cells and heights, left or right values and colour roles.');
    }
    $this->valueAlignment = [...[MenuDetailKind::TEXT->value => HorizontalAlignment::LEFT,
      MenuDetailKind::NUMBER->value => HorizontalAlignment::RIGHT, MenuDetailKind::ICONS->value => HorizontalAlignment::LEFT], ...$valueAlignment];
    $this->valuePlacement = [...array_fill_keys($kinds, 'beside'), ...$valuePlacement];
  }

  /** Terminal has no record separators or icons: labels carry an attached suffix and icons show their names. */
  public static function createTerminal(): self
  {
    return new self(TerminalMenuRow::LABEL_SUFFIX, separators: false, emptyValueText: '-');
  }

  /**
   * @param array<string, mixed> $data A theme's `details` block; omitted keys keep the Engine default.
   * @param list<string> $iconRoles Icon roles the theme can draw.
   */
  public static function createFromArray(array $data, int $iconCells = 1, array $iconRoles = []): self
  {
    if (array_diff(array_keys($data), self::KEYS) !== []) { throw new InvalidArgumentException('Unsupported menu details field.'); }
    $types = ['labelSuffix' => 'string', 'labelColor' => 'string', 'valueColor' => 'string', 'gapCells' => 'integer',
      'minValueCells' => 'integer', 'stackedIndentCells' => 'integer', 'recordMinHeight' => 'integer',
      'stackWrappedValues' => 'boolean', 'separators' => 'boolean', 'valueAlignment' => 'array', 'valuePlacement' => 'array',
      'stackedValueAlignment' => 'string', 'emptyValueText' => 'string', 'headingColor' => 'string',
      'headingBackground' => 'string', 'headingMinHeight' => 'integer'];
    foreach ($data as $key => $value) {
      if (gettype($value) !== $types[$key]) { throw new InvalidArgumentException("Menu details {$key} must be a {$types[$key]}."); }
    }
    if (isset($data['stackedValueAlignment'])) { $data['stackedValueAlignment'] = self::createAlignment($data['stackedValueAlignment']); }
    if (isset($data['valueAlignment'])) { $data['valueAlignment'] = array_map(self::createAlignment(...), $data['valueAlignment']); }
    return new self(...$data, iconCells: $iconCells, iconRoles: $iconRoles);
  }

  public function getAlignment(MenuDetailKind $kind): HorizontalAlignment
  {
    return $this->valueAlignment[$kind->value] ?? HorizontalAlignment::LEFT;
  }

  public function isPlacedBelow(MenuDetailKind $kind): bool
  {
    return ($this->valuePlacement[$kind->value] ?? 'beside') === 'below';
  }

  private static function createAlignment(mixed $value): HorizontalAlignment
  {
    return match ($value) {
      'left' => HorizontalAlignment::LEFT,
      'right' => HorizontalAlignment::RIGHT,
      default => throw new InvalidArgumentException('Menu details alignments must be left or right.'),
    };
  }
}
