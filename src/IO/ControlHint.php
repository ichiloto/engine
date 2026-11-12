<?php

declare(strict_types=1);

namespace Ichiloto\Engine\IO;

use Ichiloto\Engine\IO\Enumerations\KeyCode;
use InvalidArgumentException;

/**
 * Display identity supplied by an input context, not an input event or a detected device. The family
 * selects the theme's control set (`controls.<family>`), and the control its glyph within that set.
 */
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

  /**
   * The keyboard control for a key, named as its keycap reads. This is the one mapping from keys to
   * their displayed names: arrows show their symbol, and every hint, keycap and Controls list uses it.
   */
  public static function keyboard(KeyCode $key): self
  {
    $label = match ($key) {
      KeyCode::SHIFT_TAB => 'Shift+Tab',
      KeyCode::ESCAPE => 'Esc',
      KeyCode::PAGE_UP => 'PgUp',
      KeyCode::PAGE_DOWN => 'PgDn',
      KeyCode::UP => '↑',
      KeyCode::DOWN => '↓',
      KeyCode::LEFT => '←',
      KeyCode::RIGHT => '→',
      default => strlen($key->name) === 1 ? $key->name : str_replace('_', ' ', ucwords(strtolower($key->name), '_')),
    };
    return new self('keyboard', $key->name, $label);
  }
}
