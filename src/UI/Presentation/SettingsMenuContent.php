<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Settings\GameSetting;
use Ichiloto\Engine\UI\Text\MenuInfoText;
use InvalidArgumentException;

final readonly class SettingsMenuContent
{
  /** @param list<GameSetting> $settings @param list<int> $choiceIndices */
  public function __construct(public string $title, public array $settings, public array $choiceIndices,
    public int $activeIndex, public MenuInfoText $info, public ?string $status = null,
    public bool $statusError = false, public string $backLabel = 'Cancel', public bool $backFocused = false,
    public ?\Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle $bounds = null)
  {
    if (!array_is_list($settings) || !array_is_list($choiceIndices) || count($settings) !== count($choiceIndices)) {
      throw new InvalidArgumentException('Settings presentation requires matching ordered settings and choice indices.');
    }
    foreach ($settings as $index => $setting) {
      if (!$setting instanceof GameSetting || !is_int($choiceIndices[$index])
        || $choiceIndices[$index] < 0 || $choiceIndices[$index] >= count($setting->choices)) {
        throw new InvalidArgumentException('Settings presentation requires existing settings and valid choices.');
      }
    }
  }
}
