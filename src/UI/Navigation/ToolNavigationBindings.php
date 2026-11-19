<?php

declare(strict_types=1);

namespace Ichiloto\Engine\UI\Navigation;

use Ichiloto\Engine\IO\{ActionHintProvider, ControlHint, InputBindings};
use Ichiloto\Engine\IO\Enumerations\KeyCode;
use Ichiloto\Engine\IO\InputSources\TerminalInputSource;
use InvalidArgumentException;

/** One keyboard profile for tools; it never rewrites project/player bindings or dispatches input. */
final readonly class ToolNavigationBindings implements ActionHintProvider
{
  /** @var array<string, list<KeyCode>> */
  private array $bindings;

  /** Explicit empty overrides stay unbound. Other device presentations may supply their own hint provider.
   * @param array<string, list<KeyCode>> $overrides
   */
  public function __construct(array $overrides = [], private ActionHintProvider $otherActions = new InputBindings())
  {
    $bindings = [ToolNavigationAction::NEXT_PANE->value => [KeyCode::TAB],
      ToolNavigationAction::PREVIOUS_PANE->value => [KeyCode::SHIFT_TAB],
      ToolNavigationAction::CANCEL->value => [KeyCode::ESCAPE],
      ToolNavigationAction::QUIT->value => [KeyCode::CTRL_Q]];
    foreach ($overrides as $id => $keys) {
      if (!is_string($id) || ToolNavigationAction::tryFrom($id) === null || !is_array($keys)
        || !array_is_list($keys) || !array_all($keys, static fn($key): bool => $key instanceof KeyCode)) {
        throw new InvalidArgumentException('Tool bindings name a shared action and a list of canonical keys.');
      }
      $bindings[$id] = $keys;
    }
    $claimed = [];
    foreach ($bindings as $id => $keys) {
      foreach ($keys as $key) {
        if (isset($claimed[$key->value]) && $claimed[$key->value] !== $id) {
          throw new InvalidArgumentException('Tool controls belong to one action: ' . $key->value);
        }
        $claimed[$key->value] = $id;
      }
    }
    $this->bindings = $bindings;
  }

  public function findAction(?KeyCode $key): ?ToolNavigationAction
  {
    if ($key === null) { return null; }
    foreach ($this->bindings as $id => $keys) {
      if (in_array($key, $keys, true)) { return ToolNavigationAction::from($id); }
    }
    return null;
  }

  /** Editor adapters pass one decoded terminal token, preserving their modal and text-capture routing. */
  public function decodeAction(string $token): ?ToolNavigationAction
  {
    return $this->findAction(TerminalInputSource::decodeKey($token));
  }

  /** @return list<KeyCode> */
  public function getKeys(ToolNavigationAction $action): array { return $this->bindings[$action->value]; }

  public function controlForAction(string $action): ?ControlHint
  {
    $tool = ToolNavigationAction::tryFrom($action);
    if ($tool === null) { return $this->otherActions->controlForAction($action); }
    $key = $this->bindings[$tool->value][0] ?? null;
    return $key === null ? null : ControlHint::keyboard($key);
  }
}
