<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Battle\Actions\SkillBattleAction;
use Ichiloto\Engine\Battle\BattleAction;
use Ichiloto\Engine\Battle\BattleCommandCatalog;
use Ichiloto\Engine\Localization\MessageCatalog;

/** Project-authored callouts shared by command playback and editor previews. */
final class BattleAnnouncement
{
  private function __construct() {}

  public static function formatForAction(?BattleAction $action, string $actorName, string $actionName): string
  {
    $template = $action instanceof SkillBattleAction ? $action->skill->invocation->message
      : MessageCatalog::resolve('battle.announcements.' . BattleCommandCatalog::getActionType($action)->value,
        '%2', allowEmpty: true);
    return self::format($template, $actorName, $actionName);
  }

  /** Blank text silences a command; $1/%1 and $2/%2 substitute literal names once. */
  public static function format(string $template, string $actorName, string $actionName): string
  {
    if (trim($template) === '') { return ''; }
    return preg_replace_callback('/[$%]([12])(?![0-9])/',
      static fn(array $match): string => $match[1] === '1' ? $actorName : $actionName, $template);
  }
}
