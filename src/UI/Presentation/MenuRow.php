<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Enumerations\WeaponType;
use Ichiloto\Engine\Entities\Inventory\EquipmentSlotType;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasValidation;
use Ichiloto\Engine\Rendering\Presentation\PresentationTextRun;
use InvalidArgumentException;

/** An owner-supplied display record, not inventory, input state or a gameplay model. */
final readonly class MenuRow
{
  /** @var list<MenuRowValue> */
  public array $values;

  /** @param list<MenuRowValue> $values */
  public function __construct(
    public string $id,
    public string $label,
    array $values = [],
    public MenuRowKind $kind = MenuRowKind::RECORD,
    public WeaponType|EquipmentSlotType|string|null $icon = null,
    public bool $selected = false,
    public bool $focused = false,
    public bool $disabled = false,
    public bool $showCursor = true,
  ) {
    CanvasValidation::id($id);
    new PresentationTextRun(0, 0, $label);
    if (!array_is_list($values)) { throw new InvalidArgumentException('Menu values must be a list.'); }
    $copy = [];
    foreach ($values as $value) {
      if (!$value instanceof MenuRowValue) { throw new InvalidArgumentException('Menu values must be typed.'); }
      $copy[] = $value;
    }
    if ($kind->isAction() && $values !== []) {
      throw new InvalidArgumentException('Commands have one centered label, not data columns.');
    }
    if (trim($label) === '' && !array_any($copy, static fn(MenuRowValue $value) => trim($value->text) !== '')) {
      throw new InvalidArgumentException('Blank space is not a menu record. Supply only real rows.');
    }
    if (is_string($icon)) { CanvasValidation::id($icon); }
    $this->values = $copy;
  }
}
