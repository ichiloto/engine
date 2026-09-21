<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use InvalidArgumentException;

/** Display identity supplied by an input context, not an input event or a detected device. */
final readonly class ControlHint
{
  public function __construct(public string $family, public string $control, public string $label)
  {
    foreach ([$family, $control] as $id) {
      if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,47}$/D', $id) !== 1) {
        throw new InvalidArgumentException('Hint family and control require stable nonempty identifiers.');
      }
    }
    if (trim($label) === '' || preg_match('//u', $label) !== 1 || preg_match('/\p{Cc}/u', $label) === 1) {
      throw new InvalidArgumentException('A control hint requires a readable UTF-8 fallback label without controls.');
    }
  }

  public function iconRole(): string
  {
    return 'input.' . $this->family . '.' . $this->control;
  }

  public static function keyboard(KeyCode $key): self
  {
    $label = $key === KeyCode::SHIFT_TAB ? 'Shift+Tab'
      : (strlen($key->name) === 1 ? $key->name : str_replace('_', ' ', ucwords(strtolower($key->name), '_')));
    return new self('keyboard', $key->name, $label);
  }
}
