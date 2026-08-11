<?php

namespace Ichiloto\Engine\Events\Triggers;

use Ichiloto\Engine\Entities\Actions\RunScriptAction;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Events\Interfaces\EventTriggerContextInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;

/**
 * Fires a data-driven event script (the cutscene engine's map hook).
 *
 * ```php
 * 'E' => [
 *   'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
 *   'data' => [
 *     'mode' => 'auto',            // 'auto' runs on step-in; 'action' waits for the action key
 *     'reusable' => false,         // one-shot cutscenes persist like chests
 *     'script' => [                // inline commands, or 'scriptId' => 'intro' for assets/Events/intro.php
 *       ['type' => 'text', 'name' => 'Elder', 'text' => '...'],
 *     ],
 *   ],
 * ],
 * ```
 *
 * Combined with the trigger's `conditions`/`sets` (and the script's own
 * `branch`/`choice` commands), authored scenes appear, change, and retire
 * as the story moves.
 *
 * @package Ichiloto\Engine\Events\Triggers
 */
class ScriptEventTrigger extends EventTrigger implements EventSessionCompletionTargetInterface
{
  /**
   * @var array<int, array<string, mixed>> The script commands.
   */
  protected(set) array $script = [];
  /**
   * @var bool True when the script runs the moment the player steps in.
   */
  protected(set) bool $runsAutomatically = false;
  protected(set) ?string $scriptId = null;
  protected(set) ?string $scriptSource = null;
  protected(set) bool $sessionIsActive = false;

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this->isReusable = (bool) ($this->data->reusable ?? true);
    $this->runsAutomatically = strval($this->data->mode ?? 'action') === 'auto';
    $scriptId = trim(strval($this->data->scriptId ?? ''));
    $this->scriptId = $scriptId !== '' ? $scriptId : null;
    $this->script = $this->resolveScript();
  }

  /**
   * Starts this trigger's script if it is not already running or complete.
   */
  public function startSession(GameScene $gameScene): ?EventExecutionSession
  {
    if ($this->sessionIsActive || $this->isComplete) {
      return null;
    }

    $identity = $this->scriptId ?? (
      $this->mapId !== null && $this->marker !== null
        ? sprintf('%s:%s', $this->mapId, $this->marker)
        : null
    );
    // Mark before starting: a state-only script may finish synchronously
    // inside startEventScript(), and its completion callback must be allowed
    // to leave this false rather than being overwritten afterward.
    $this->sessionIsActive = true;
    $session = $gameScene->startEventScript($this->script, $identity, $this, [
      'map' => $this->mapId ?? $gameScene->currentMapId,
      'marker' => $this->marker,
      'trigger' => static::class,
      'source' => $this->scriptSource,
    ]);

    if ($session === null) {
      $this->sessionIsActive = false;
    }

    return $session;
  }

  /** @inheritDoc */
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->sessionIsActive = false;
    $this->complete();
  }

  /** @inheritDoc */
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->sessionIsActive = false;
  }

  /**
   * @inheritDoc
   */
  public function enter(EventTriggerContextInterface $context): void
  {
    parent::enter($context);

    if ($this->runsAutomatically) {
      new RunScriptAction($this)->execute(new \Ichiloto\Engine\Entities\Actions\FieldActionContext(
        $context->player,
        $context->scene,
        $context->player->position
      ));
      return;
    }

    $context->player->erase();
    $context->player->availableAction = new RunScriptAction($this);
    $context->player->render();
  }

  /**
   * @inheritDoc
   */
  public function exit(EventTriggerContextInterface $context): void
  {
    parent::exit($context);

    if (! $this->runsAutomatically) {
      $context->player->erase();
      $context->player->availableAction = null;
      $context->player->render();
    }
  }

  /**
   * Resolves the script from inline data or an `assets/Events/<id>.php` file.
   *
   * @return array<int, array<string, mixed>> The script commands.
   */
  protected function resolveScript(): array
  {
    $inline = json_decode(json_encode($this->data->script ?? []), true);

    if (is_array($inline) && ! empty($inline)) {
      return $inline;
    }

    $scriptId = trim(strval($this->data->scriptId ?? ''));

    if ($scriptId === '') {
      return [];
    }

    $filename = \Assegai\Util\Path::join(\Assegai\Util\Path::getCurrentWorkingDirectory(), 'assets', 'Events', "$scriptId.php");
    $this->scriptSource = $filename;

    if (! file_exists($filename)) {
      \Ichiloto\Engine\Util\Debug::warn("Event script not found: $filename");
      return [];
    }

    $commands = require $filename;

    return is_array($commands) ? $commands : [];
  }
}
