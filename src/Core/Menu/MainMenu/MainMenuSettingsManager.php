<?php

namespace Ichiloto\Engine\Core\Menu\MainMenu;

use Ichiloto\Engine\Settings\SettingsManager;

/**
 * The settings listed by the in-game config menu.
 *
 * @package Ichiloto\Engine\Core\Menu\MainMenu
 */
class MainMenuSettingsManager extends SettingsManager
{
    /**
     * @inheritDoc
     */
    protected function settingKeys(): array
    {
        return [
            'volume',
            'music',
            'sfx',
            'dialogue_speed',
            'notification_duration',
            'cursor_memory',
            'battle_message_pace',
            'battle_animation_pace',
            'selection_color',
            'transitions',
            'location_hud',
        ];
    }
}
