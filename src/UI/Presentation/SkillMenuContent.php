<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\UI\Text\MenuInfoText;

/** Fresh read-only display projection of an existing Abilities/Magic owner, never saved state. */
final readonly class SkillMenuContent
{
  /** @param list<string> $tabs
   * @param array<string, string> $summary
   * @param array<string, string> $fields
   * @param list<MenuRow> $rows
   */
  public function __construct(
    public Character $character,
    public string $title,
    public array $tabs,
    public int $tabIndex,
    public array $summary,
    public string $detailTitle,
    public array $fields,
    public string $detailText,
    public array $rows,
    public string $listTitle,
    public int $index,
    public string $emptyText,
    public string $description,
    public ?string $status,
    public string $confirmLabel,
    public bool $targeting = false,
    public ?MenuInfoText $infoModel = null,
  ) {}

  /** The selected entry's heading and fields, then its requirement or guidance text, for renderers that read it as one sheet.
   * @param array<string, string> $additionalFields Renderer-specific fields that follow the shared ones.
   */
  public function getDetailSheet(array $additionalFields = []): MenuDetailSheet
  {
    $fields = [];
    foreach ([...$this->fields, ...$additionalFields] as $label => $value) {
      if (trim($value) !== '') { $fields[] = MenuDetailEntry::createField((string)$label, $value); }
    }
    return new MenuDetailSheet([[...($this->detailTitle === '' ? [] : [MenuDetailEntry::createProse($this->detailTitle)]), ...$fields],
      trim($this->detailText) === '' ? [] : [MenuDetailEntry::createProse($this->detailText)]]);
  }
}
