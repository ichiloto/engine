<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Battle\Presentation;

use Ichiloto\Engine\Localization\Vocabulary;

enum PauseAction: string
{
  case RESUME = 'Resume';
  case CONFIG = 'Config';
  case TITLE = 'To Title';
  case EXIT = 'Exit';

  public function getLabel(): string
  {
    return match ($this) {
      self::RESUME => Vocabulary::getTerm('game.resume', $this->value),
      self::CONFIG => Vocabulary::getTerm('game.options', Vocabulary::getTerm('command.options', $this->value)),
      self::TITLE => Vocabulary::getTerm('game.to_title', $this->value),
      self::EXIT => Vocabulary::getTerm('game.shutdown', $this->value),
    };
  }
}
