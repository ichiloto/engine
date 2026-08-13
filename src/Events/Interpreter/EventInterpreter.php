<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Debug;
use Ichiloto\Engine\Util\Stores\ItemStore;
use RuntimeException;
use Throwable;

/**
 * Runs data-driven story-event scripts through one resumable command stack.
 *
 * Immediate commands may share a frame. Dialogue, choices, waits, movement,
 * transfers, and battles retain explicit pending state and hand control back
 * to the game loop. Nested arms are frames, not recursive interpreter calls.
 */
class EventInterpreter
{
  /** The single runtime/editor command vocabulary. */
  public const array COMMAND_TYPES = [
    'text',
    'choice',
    'wait',
    'set_switch',
    'set_variable',
    'record_event',
    'give_item',
    'give_gold',
    'recover_party',
    'play_sound',
    'play_music',
    'accept_quest',
    'move_player',
    'move_route',
    'transfer',
    'start_battle',
    'branch',
  ];

  protected ?EventExecutionSession $activeSession = null;
  protected(set) ?EventExecutionSession $lastSession = null;
  protected ?MovementRouteRunner $pendingRoute = null;
  protected ?array $frameToPush = null;

  public function __construct(
    protected GameScene $gameScene,
    protected ?EventPresentationInterface $presentation = null,
  )
  {
    $this->presentation ??= new ModalEventPresentation($gameScene);
  }

  /**
   * Starts a script and immediately drains its synchronous prefix.
   *
   * This keeps simple state-only scripts source compatible with the old
   * `run()` entry point while yielded commands continue on later field ticks.
   *
   * @param array<int, array<string, mixed>> $commands The script commands.
   * @param string|null $scriptId Stable script identity when available.
   * @param array<string, scalar|null> $origin Plain authoring origin metadata.
   * @return EventExecutionSession|null The session, or null when another is active.
   */
  public function run(
    array $commands,
    ?string $scriptId = null,
    ?EventSessionCompletionTargetInterface $completionTarget = null,
    array $origin = [],
  ): ?EventExecutionSession
  {
    $session = $this->start($commands, $scriptId, $completionTarget, $origin);

    if ($session !== null) {
      $this->update(0.0);
    }

    return $session;
  }

  /**
   * Creates one execution session without running it.
   *
   * @param array<int, array<string, mixed>> $commands The script commands.
   */
  public function start(
    array $commands,
    ?string $scriptId = null,
    ?EventSessionCompletionTargetInterface $completionTarget = null,
    array $origin = [],
  ): ?EventExecutionSession
  {
    if ($this->activeSession !== null) {
      Debug::warn(sprintf(
        'Event script "%s" did not start because session %d is already active.',
        $scriptId ?? 'inline script',
        $this->activeSession->id,
      ));
      return null;
    }

    $commands = array_values(array_filter($commands, is_array(...)));
    $origin['map'] ??= $this->gameScene->currentMapId !== ''
      ? $this->gameScene->currentMapId
      : null;
    $this->activeSession = new EventExecutionSession($commands, $scriptId, $completionTarget, $origin);
    $this->gameScene->onEventSessionStarted($this->activeSession);

    return $this->activeSession;
  }

  /**
   * Advances the active session by one field tick.
   *
   * @param float|null $deltaSeconds Elapsed time; engine delta when omitted.
   */
  public function update(?float $deltaSeconds = null): void
  {
    $session = $this->activeSession;

    if ($session === null || in_array($session->status, [EventExecutionStatus::COMPLETED, EventExecutionStatus::FAILED], true)) {
      return;
    }

    if ($session->status === EventExecutionStatus::SUSPENDED) {
      return;
    }

    $deltaSeconds ??= Time::getDeltaTime();

    if ($session->status === EventExecutionStatus::YIELDED) {
      try {
        if (! $this->updatePendingCommand($session, max(0.0, $deltaSeconds))) {
          return;
        }
      } catch (Throwable $throwable) {
        $this->fail($throwable->getMessage(), $throwable);
        return;
      }
    }

    // A malformed but finite script should never monopolize a frame. This is
    // a diagnostic ceiling, not a sequencing mechanism.
    for ($commandsThisTick = 0; $commandsThisTick < 1000; $commandsThisTick++) {
      if ($session->hasFinishedFrames()) {
        $this->finish();
        return;
      }

      $command = $session->currentCommand();

      if ($command === null) {
        $session->advance();
        continue;
      }

      try {
        $result = $this->execute($session, $command);
      } catch (Throwable $throwable) {
        $this->fail(sprintf(
          'Event command %s failed: %s',
          strval($command['type'] ?? '?'),
          $throwable->getMessage(),
        ), $throwable);
        return;
      }

      if ($result === EventCommandResult::COMPLETED) {
        $session->advance();

        if ($this->frameToPush !== null) {
          $session->pushFrame($this->frameToPush['commands'], $this->frameToPush['label']);
          $this->frameToPush = null;
        }

        continue;
      }

      return;
    }

    $this->fail('Event script exceeded 1000 immediate commands in one tick.');
  }

  /**
   * Resumes a transfer command after the existing map-transfer path finishes.
   */
  public function resumeAfterTransfer(): void
  {
    $session = $this->activeSession;

    if (
      $session === null
      || $session->status !== EventExecutionStatus::SUSPENDED
      || strval($session->pendingCommand['type'] ?? '') !== 'transfer'
    ) {
      return;
    }

    $session->completePendingCommand();
  }

  /**
   * Resumes a scripted battle through the existing BattleResult object.
   */
  public function resumeAfterBattle(BattleResult $result): void
  {
    $session = $this->activeSession;

    if (
      $session === null
      || $session->status !== EventExecutionStatus::SUSPENDED
      || strval($session->pendingCommand['type'] ?? '') !== 'start_battle'
    ) {
      return;
    }

    $resultVariable = trim(strval($session->pendingState['resultVariable'] ?? ''));

    if ($resultVariable !== '') {
      $this->gameScene->gameState->setVariable($resultVariable, $result->outcome());
    }

    $session->completePendingCommand();
  }

  public function failActiveSession(string $message): void
  {
    if ($this->activeSession !== null) {
      $this->fail($message);
    }
  }

  public function hasActiveSession(): bool
  {
    return $this->activeSession !== null;
  }

  public function activeSession(): ?EventExecutionSession
  {
    return $this->activeSession;
  }

  /**
   * Executes one command until it either completes or records pending state.
   *
   * @param array<string, mixed> $command The command entry.
   */
  protected function execute(EventExecutionSession $session, array $command): EventCommandResult
  {
    $gameState = $this->gameScene->gameState;
    $type = strval($command['type'] ?? '');

    switch ($type) {
      case 'text':
        $this->presentation->beginText(
          strval($command['text'] ?? ''),
          strval($command['name'] ?? ''),
        );
        $session->yieldFor($command, ['kind' => 'dialogue']);
        return EventCommandResult::YIELDED;

      case 'choice':
        $options = array_values(array_filter((array) ($command['options'] ?? []), is_array(...)));

        if ($options === []) {
          return EventCommandResult::COMPLETED;
        }

        $labels = array_map(static fn(array $option): string => strval($option['text'] ?? '…'), $options);
        $this->presentation->beginChoice(
          strval($command['prompt'] ?? 'Choose:'),
          $labels,
          strval($command['title'] ?? ''),
        );
        $session->yieldFor($command, ['kind' => 'choice']);
        return EventCommandResult::YIELDED;

      case 'wait':
        $seconds = max(0.0, floatval($command['seconds'] ?? 0.5));

        if ($seconds <= 0.0) {
          return EventCommandResult::COMPLETED;
        }

        $session->yieldFor($command, ['kind' => 'wait', 'remainingSeconds' => $seconds]);
        return EventCommandResult::YIELDED;

      case 'set_switch':
        $gameState->setSwitch(strval($command['name'] ?? ''), (bool) ($command['value'] ?? true));
        return EventCommandResult::COMPLETED;

      case 'set_variable':
        $name = strval($command['name'] ?? '');
        strval($command['op'] ?? 'set') === 'add'
          ? $gameState->addToVariable($name, is_numeric($command['value'] ?? 1) ? $command['value'] + 0 : 1)
          : $gameState->setVariable($name, $command['value'] ?? 0);
        return EventCommandResult::COMPLETED;

      case 'record_event':
        $gameState->recordStoryEvent(strval($command['name'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'give_item':
        $itemStore = ConfigStore::get(ItemStore::class);
        $quantity = max(1, intval($command['quantity'] ?? 1));

        if ($itemStore instanceof ItemStore && $this->gameScene->party) {
          for ($count = 0; $count < $quantity; $count++) {
            $this->gameScene->party->addItems(...$itemStore->load([strval($command['item'] ?? '')]));
          }
        }
        return EventCommandResult::COMPLETED;

      case 'give_gold':
        $this->gameScene->party?->credit(intval($command['amount'] ?? 0));
        return EventCommandResult::COMPLETED;

      case 'recover_party':
        foreach ($this->gameScene->party?->members->toArray() ?? [] as $member) {
          $member->stats->currentHp = $member->stats->totalHp;
          $member->stats->currentMp = $member->stats->totalMp;
          $member->stats->currentAp = $member->stats->totalAp;
          $member->clearBattleStates();
        }
        return EventCommandResult::COMPLETED;

      case 'play_sound':
        play_sound(strval($command['sound'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'play_music':
        play_music(strval($command['music'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'accept_quest':
        QuestManager::current()?->acceptQuest(
          strval($command['id'] ?? ''),
          ($command['confirm'] ?? true) !== false,
        );
        return EventCommandResult::COMPLETED;

      case 'move_player':
        $player = $this->gameScene->player;

        if ($player !== null && isset($command['x'], $command['y'])) {
          $player->erase();
          $player->position->x = intval($command['x']);
          $player->position->y = intval($command['y']);
          $player->render();
        }
        return EventCommandResult::COMPLETED;

      case 'move_route':
        $this->pendingRoute = new MovementRouteRunner($this->gameScene, $command);
        $session->yieldFor($command, ['kind' => 'movement_route']);
        return EventCommandResult::YIELDED;

      case 'transfer':
        $session->suspendFor($command, ['kind' => 'transfer']);
        $spawn = new Vector2(intval($command['x'] ?? 0), intval($command['y'] ?? 0));
        $sprite = (array) ($command['sprite'] ?? ($this->gameScene->player?->sprite ?? ['@']));
        $this->gameScene->transferPlayer(new Location(strval($command['map'] ?? ''), $spawn, $sprite));
        return EventCommandResult::SUSPENDED;

      case 'start_battle':
        $troopName = trim(strval($command['troop'] ?? ''));

        if ($troopName === '') {
          throw new RuntimeException('start_battle requires a troop.');
        }

        if (! $this->gameScene->party) {
          throw new RuntimeException('start_battle requires a configured party.');
        }

        if ($this->gameScene->party->isDefeated()) {
          throw new RuntimeException('start_battle cannot launch with a defeated party.');
        }

        $defeatPolicy = strval($command['defeatPolicy'] ?? 'game_over');

        if (! in_array($defeatPolicy, ['game_over', 'continue'], true)) {
          throw new RuntimeException(sprintf('Unsupported battle defeat policy "%s".', $defeatPolicy));
        }

        $escapePolicy = null;

        if (array_key_exists('escapePolicy', $command)) {
          try {
            $escapePolicy = \Ichiloto\Engine\Battle\EscapePolicy::resolve($command['escapePolicy'])->value;
          } catch (\InvalidArgumentException $exception) {
            throw new RuntimeException($exception->getMessage(), previous: $exception);
          }
        }

        $session->suspendFor($command, [
          'kind' => 'battle',
          'resultVariable' => trim(strval($command['resultVariable'] ?? '')),
          'defeatPolicy' => $defeatPolicy,
        ]);
        $extraSettings = ['event_defeat_policy' => $defeatPolicy];

        if ($escapePolicy !== null) {
          $extraSettings['escapePolicy'] = $escapePolicy;
        }

        $this->gameScene->sceneManager->loadBattleScene(
          $this->gameScene->party,
          get_troop($troopName),
          extraSettings: $extraSettings,
        );
        return EventCommandResult::SUSPENDED;

      case 'branch':
        $holds = $this->conditionsHold((array) ($command['conditions'] ?? []));
        $commands = array_values(array_filter((array) ($command[$holds ? 'then' : 'else'] ?? []), is_array(...)));
        $this->frameToPush = [
          'commands' => $commands,
          'label' => sprintf('branch:%s', $holds ? 'then' : 'else'),
        ];
        return EventCommandResult::COMPLETED;

      default:
        throw new RuntimeException($this->unknownCommandDiagnostic($session, $type));
    }
  }

  /**
   * Builds a fail-closed diagnostic with the deepest command-frame context.
   */
  protected function unknownCommandDiagnostic(EventExecutionSession $session, string $type): string
  {
    $frame = $session->currentFrame();
    $details = [
      sprintf('script "%s"', $session->scriptId ?? 'inline script'),
      sprintf('command %d', ($frame?->commandIndex ?? 0) + 1),
      sprintf('frame "%s"', $frame?->label ?? 'unknown'),
    ];
    $originLabels = [
      'map' => 'map',
      'marker' => 'marker',
      'trigger' => 'trigger',
      'npc' => 'NPC',
      'source' => 'source',
    ];

    foreach ($originLabels as $key => $label) {
      $value = $session->origin[$key] ?? null;

      if (is_scalar($value) && trim(strval($value)) !== '') {
        $details[] = sprintf('%s "%s"', $label, strval($value));
      }
    }

    return sprintf(
      'Unknown event command type "%s" in %s.',
      $type !== '' ? $type : '(empty)',
      implode(', ', $details),
    );
  }

  /**
   * Updates a command that yielded on a prior tick.
   *
   * @return bool True when the command completed and immediate execution may continue.
   */
  protected function updatePendingCommand(EventExecutionSession $session, float $deltaSeconds): bool
  {
    $type = strval($session->pendingCommand['type'] ?? '');

    if ($type === 'wait') {
      $remaining = max(0.0, floatval($session->pendingState['remainingSeconds'] ?? 0.0) - $deltaSeconds);

      if ($remaining > 0.0) {
        $session->updatePendingState(['kind' => 'wait', 'remainingSeconds' => $remaining]);
        return false;
      }

      $session->completePendingCommand();
      return true;
    }

    if ($type === 'text') {
      $this->presentation->update();
      $this->presentation->render();

      if (! $this->presentation->isComplete()) {
        return false;
      }

      $this->presentation->reset();
      $session->completePendingCommand();
      return true;
    }

    if ($type === 'choice') {
      $this->presentation->update();
      $this->presentation->render();

      if (! $this->presentation->isComplete()) {
        return false;
      }

      $chosen = $this->presentation->choiceResult();
      $this->presentation->reset();
      $options = array_values(array_filter((array) ($session->pendingCommand['options'] ?? []), is_array(...)));

      // Preserve the old SelectModal contract when no cancel arm is authored,
      // while allowing story-critical choices to acknowledge cancellation and
      // restore a clear retry path without treating it as a selection.
      if ($chosen === -1) {
        $commands = array_values(array_filter((array) ($session->pendingCommand['cancel'] ?? []), is_array(...)));
        $session->completePendingCommand();

        if ($commands !== []) {
          $session->pushFrame($commands, 'choice:cancel');
        }

        return true;
      }

      if (! is_int($chosen) || ! isset($options[$chosen])) {
        throw new RuntimeException('Event choice was cancelled without selecting an option.');
      }

      $commands = array_values(array_filter((array) ($options[$chosen]['then'] ?? []), is_array(...)));
      $session->completePendingCommand();
      $session->pushFrame($commands, sprintf('choice:%d', $chosen));
      return true;
    }

    if ($type === 'move_route') {
      if (! $this->pendingRoute instanceof MovementRouteRunner) {
        throw new RuntimeException('Movement-route continuation is missing.');
      }

      if (! $this->pendingRoute->update($deltaSeconds)) {
        return false;
      }

      $this->pendingRoute = null;
      $session->completePendingCommand();
      return true;
    }

    throw new RuntimeException(sprintf('Unsupported pending event command "%s".', $type));
  }

  protected function finish(): void
  {
    $session = $this->activeSession;

    if ($session === null) {
      return;
    }

    try {
      $session->completionTarget?->onEventSessionCompleted($this->gameScene, $session);
    } catch (Throwable $throwable) {
      $this->fail(sprintf('Event completion failed: %s', $throwable->getMessage()), $throwable);
      return;
    }

    $session->complete();
    $this->lastSession = $session;
    $this->activeSession = null;
    $this->gameScene->onEventSessionFinished($session, true);
  }

  protected function fail(string $message, ?Throwable $throwable = null): void
  {
    $session = $this->activeSession;

    if ($session === null) {
      return;
    }

    $this->presentation->reset();
    $this->pendingRoute = null;
    $this->frameToPush = null;
    $session->fail($message);
    Debug::error($message);

    try {
      $session->completionTarget?->onEventSessionFailed($this->gameScene, $session);
    } catch (Throwable $completionFailure) {
      Debug::error(sprintf('Event failure cleanup failed: %s', $completionFailure->getMessage()));
    }

    $this->lastSession = $session;
    $this->activeSession = null;
    $this->gameScene->onEventSessionFinished($session, false);
  }

  /** @param array<int, array<string, mixed>> $conditions The condition entries. */
  protected function conditionsHold(array $conditions): bool
  {
    return WorldConditionEvaluator::allHold(
      $conditions,
      $this->gameScene->gameState,
      $this->gameScene->party,
    );
  }
}
