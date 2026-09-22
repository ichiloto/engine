<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Presentation;

use Ichiloto\Engine\Core\Menu\MainMenu\ConfigMenu;
use Ichiloto\Engine\Rendering\Presentation\Canvas\PresentationCanvas;

final class ConfigMenuPresentation
{
  public static function compose(ConfigMenu $menu, MenuPresentationCatalog $theme, float $time = 0,
    int $width = 1350, int $height = 720): PresentationCanvas
  {
    $settings = $menu->selection->getSettings();
    return SettingsMenuPresentation::compose(new SettingsMenuContent('Config', $settings,
      array_map($menu->getChoiceIndex(...), $settings), $menu->selection->getActiveIndex(),
      $menu->menuInfoText, $menu->getStatusMessage(), $menu->hasStatusError()), $theme, $time, $width, $height);
  }
}
