<?php

namespace Ichiloto\Engine\Events\Interpreter;

use Assegai\Util\Path;
use Ichiloto\Engine\Battle\BattleResult;
use Ichiloto\Engine\Core\Time;
use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\Core\WorldConditionEvaluator;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CameraOperation;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicSubjectResolver;
use Ichiloto\Engine\Cutscenes\Cinematics\FieldAnimationOperation;
use Ichiloto\Engine\Cutscenes\Cinematics\TimedPresentationOperation;
use Ichiloto\Engine\Cutscenes\Cinematics\TransitionOperation;
use Ichiloto\Engine\Animations\AnimationLibrary;
use Ichiloto\Engine\Audio\CinematicMusicOperation;
use Ichiloto\Engine\Audio\CinematicMusicRequest;
use Ichiloto\Engine\Field\Location;
use Ichiloto\Engine\Quests\QuestManager;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Rendering\ScreenTransition;
use Ichiloto\Engine\Rendering\Enumerations\TransitionStyle;
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
  public const array COMMAND_TYPES = CinematicCommandSchema::COMMAND_TYPES;

  protected ?EventExecutionSession $activeSession = null;
  protected(set) ?EventExecutionSession $lastSession = null;

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

  /** Starts a first-class cinematic on this same interpreter. */
  public function runCinematic(
    CinematicDefinition $cinematic,
    ?EventSessionCompletionTargetInterface $completionTarget = null,
  ): ?EventExecutionSession
  {
    $session = $this->start(
      $cinematic->commands,
      $cinematic->id,
      $completionTarget,
      ['map' => $this->gameScene->currentMapId, 'source' => 'cinematic:' . $cinematic->id],
    );

    if ($session !== null) {
      $session->configureCinematic($cinematic);
      $this->update(0.0);
    }

    return $session;
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

    try {
      $this->tickLane($session, $session->rootLane(), max(0.0, $deltaSeconds ?? Time::getDeltaTime()));
      $session->refreshStatus();

      if ($session->rootLane()->status === EventExecutionStatus::COMPLETED) {
        if ($session->startFinalizerIfNeeded()) {
          $this->tickLane($session, $session->rootLane(), 0.0);
        }

        if ($session->rootLane()->status === EventExecutionStatus::COMPLETED) {
          $this->finish();
        }
      }
    } catch (Throwable $throwable) {
      $this->fail($throwable->getMessage(), $throwable);
    }
  }

  /** Advances one lane, including any nested parallel children. */
  protected function tickLane(
    EventExecutionSession $session,
    EventExecutionLane $lane,
    float $deltaSeconds,
  ): void
  {
    if (in_array($lane->status, [EventExecutionStatus::COMPLETED, EventExecutionStatus::FAILED, EventExecutionStatus::SUSPENDED], true)) {
      return;
    }

    if ($lane->status === EventExecutionStatus::YIELDED
      && ! $this->updatePendingCommand($session, $lane, $deltaSeconds)
    ) {
      return;
    }

    for ($commandsThisTick = 0; $commandsThisTick < 1000; $commandsThisTick++) {
      if ($lane->hasFinishedFrames()) {
        $lane->complete();
        return;
      }

      $command = $lane->currentCommand();

      if ($command === null) {
        $lane->advance();
        continue;
      }

      try {
        $result = $this->execute($session, $lane, $command);
      } catch (Throwable $throwable) {
        throw new RuntimeException($this->commandFailureDiagnostic($session, $lane, $command, $throwable), previous: $throwable);
      }

      if ($result === EventCommandResult::COMPLETED) {
        $lane->advance();
        $lane->pushQueuedFrame();
        continue;
      }

      return;
    }

    throw new RuntimeException(sprintf(
      'Event script "%s" lane "%s" exceeded 1000 immediate commands in one tick.',
      $session->scriptId ?? 'inline script',
      $lane->path,
    ));
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

  /** Requests authored safe skipping of the active cinematic. */
  public function skipActiveCinematic(): bool
  {
    $session = $this->activeSession;

    if ($session?->cinematic === null) {
      Debug::warn('Cinematic skip was refused because no cinematic is active.');
      return false;
    }

    if ($session->cinematic->skipPolicy !== 'authored' || $session->cinematic->finalizer === []) {
      Debug::warn(sprintf('Cinematic "%s" does not declare an authored safe finalizer.', $session->cinematic->id));
      return false;
    }

    if ($session->status === EventExecutionStatus::SUSPENDED
      && strval($session->pendingCommand['type'] ?? '') === 'start_battle'
    ) {
      Debug::warn(sprintf('Cinematic "%s" cannot skip across an active battle boundary.', $session->cinematic->id));
      return false;
    }

    $this->presentation->reset();
    $session->cancelLanes();
    $session->startFinalizerIfNeeded();

    try {
      $this->tickLane($session, $session->rootLane(), 0.0);

      if ($session->rootLane()->status === EventExecutionStatus::COMPLETED) {
        $this->finish();
      }
    } catch (Throwable $throwable) {
      $this->fail($throwable->getMessage(), $throwable);
      return false;
    }

    return true;
  }

  public function hasActiveSession(): bool
  {
    return $this->activeSession !== null;
  }

  public function activeSession(): ?EventExecutionSession
  {
    return $this->activeSession;
  }

  /** Re-renders an active modal after a cinematic field composition redraw. */
  public function renderPresentation(): void
  {
    if ($this->activeSession !== null) {
      $this->presentation->render();
    }
  }

  /**
   * Executes one command until it either completes or records pending state.
   *
   * @param array<string, mixed> $command The command entry.
   */
  protected function execute(
    EventExecutionSession $session,
    EventExecutionLane $lane,
    array $command,
  ): EventCommandResult
  {
    $gameState = $this->gameScene->gameState;
    $type = strval($command['type'] ?? '');

    switch ($type) {
      case 'text':
        $session->claimPresentation($lane);
        $this->presentation->beginText(
          strval($command['text'] ?? ''),
          strval($command['name'] ?? ''),
        );
        $lane->yieldFor($command, ['kind' => 'dialogue']);
        return EventCommandResult::YIELDED;

      case 'choice':
        $options = array_values(array_filter((array) ($command['options'] ?? []), is_array(...)));

        if ($options === []) {
          return EventCommandResult::COMPLETED;
        }

        $session->claimPresentation($lane);
        $labels = array_map(static fn(array $option): string => strval($option['text'] ?? '…'), $options);
        $this->presentation->beginChoice(
          strval($command['prompt'] ?? 'Choose:'),
          $labels,
          strval($command['title'] ?? ''),
        );
        $lane->yieldFor($command, ['kind' => 'choice']);
        return EventCommandResult::YIELDED;

      case 'wait':
        $seconds = max(0.0, floatval($command['seconds'] ?? 0.5));

        if ($seconds <= 0.0) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor($command, ['kind' => 'wait', 'remainingSeconds' => $seconds]);
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
          $this->gameScene->party->addItems(...$itemStore->instantiate(
            strval($command['item'] ?? ''),
            $quantity,
            'granting an item from a story event command',
          ));
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
        play_music(strval($command['music'] ?? ''), boolval($command['loop'] ?? true));
        return EventCommandResult::COMPLETED;

      case 'accept_quest':
        QuestManager::current()?->acceptQuest(
          strval($command['id'] ?? ''),
          ($command['confirm'] ?? true) !== false,
        );
        return EventCommandResult::COMPLETED;

      case 'knowledge':
        $this->gameScene->knowledge->apply(strval($command['operation'] ?? ''), $command);
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
        $route = new MovementRouteRunner($this->gameScene, $command);
        $lane->yieldFor($command, ['kind' => 'movement_route'], $route);
        return EventCommandResult::YIELDED;

      case 'transfer':
        $destinationMap = strval($command['map'] ?? '');
        $destinationX = intval($command['x'] ?? 0);
        $destinationY = intval($command['y'] ?? 0);
        $player = $this->gameScene->player;

        // An always-run finalizer may describe the same destination reached by
        // normal playback. Treat that already-satisfied final state as a
        // no-op so map-entry hooks, deferred autosaves, and other transfer
        // side effects are not repeated merely because cleanup is idempotent.
        if ($session->isFinalizing
          && $this->gameScene->currentMapId === $destinationMap
          && intval($player?->position->x ?? PHP_INT_MIN) === $destinationX
          && intval($player?->position->y ?? PHP_INT_MIN) === $destinationY
        ) {
          if ($player !== null && array_key_exists('sprite', $command)) {
            $player->setFacingSprite((array) $command['sprite']);
          }

          return EventCommandResult::COMPLETED;
        }

        $session->suspendLane($lane, $command, ['kind' => 'transfer']);
        $spawn = new Vector2($destinationX, $destinationY);
        $sprite = (array) ($command['sprite'] ?? ($this->gameScene->player?->sprite ?? ['@']));
        $this->gameScene->transferPlayer(new Location($destinationMap, $spawn, $sprite));
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

        $session->suspendLane($lane, $command, [
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
        $lane->queueFrame($commands, sprintf('branch:%s', $holds ? 'then' : 'else'));
        return EventCommandResult::COMPLETED;

      case 'sequence':
        $commands = array_values(array_filter((array) ($command['commands'] ?? []), is_array(...)));
        $lane->queueFrame($commands, 'sequence');
        return EventCommandResult::COMPLETED;

      case 'parallel':
        $entries = $command['lanes'] ?? [];

        if (! is_array($entries)) {
          throw new RuntimeException('Parallel command lanes must be an array.');
        }

        $group = new EventParallelGroup($entries, $lane->commandPath());

        if ($group->lanes() === []) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldForParallel($command, $group);
        return EventCommandResult::YIELDED;

      case 'common_event':
        $eventId = trim(strval($command['id'] ?? ''));

        if ($eventId === '' || preg_match('/^[a-zA-Z0-9._-]+$/', $eventId) !== 1) {
          throw new RuntimeException('common_event requires a safe stable id.');
        }

        $filename = Path::join(Path::getCurrentWorkingDirectory(), 'assets', 'Events', "$eventId.php");

        if (! is_file($filename)) {
          throw new RuntimeException(sprintf('Common Event "%s" was not found at "%s".', $eventId, $filename));
        }

        $commands = require $filename;

        if (! is_array($commands)) {
          throw new RuntimeException(sprintf('Common Event "%s" must return a command array.', $eventId));
        }

        $lane->queueFrame(array_values(array_filter($commands, is_array(...))), "common_event:$eventId");
        return EventCommandResult::COMPLETED;

      case 'checkpoint':
        $name = trim(strval($command['name'] ?? ''));

        if ($name === '') {
          throw new RuntimeException('checkpoint requires a name.');
        }

        $session->recordCheckpoint($name);
        return EventCommandResult::COMPLETED;

      case 'camera':
        $operation = new CameraOperation(
          $this->gameScene->camera,
          new CinematicSubjectResolver($this->gameScene),
          $command,
        );

        if ($operation->isComplete) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor($command, ['kind' => 'camera'], $operation);
        return EventCommandResult::YIELDED;

      case 'stage_actor':
        $this->gameScene->cinematicStage?->add(
          is_array($command['actor'] ?? null) ? $command['actor'] : $command,
        );
        return EventCommandResult::COMPLETED;

      case 'show_actor':
        $this->gameScene->cinematicStage?->show(strval($command['actorId'] ?? $command['id'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'hide_actor':
        $this->gameScene->cinematicStage?->hide(strval($command['actorId'] ?? $command['id'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'remove_actor':
        $this->gameScene->cinematicStage?->remove(strval($command['actorId'] ?? $command['id'] ?? ''));
        return EventCommandResult::COMPLETED;

      case 'title_card':
      case 'narration':
        $presentation = $this->gameScene->cinematicPresentation
          ?? throw new RuntimeException('Cinematic presentation host is not configured.');
        $presentation->showOverlay(
          $type,
          strval($command['text'] ?? ''),
          strval($command['title'] ?? ''),
        );
        $duration = max(0.0, floatval($command['seconds'] ?? 2.5));

        if ($duration <= 0.0) {
          $presentation->clear();
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor(
          $command,
          ['kind' => 'presentation', 'remainingSeconds' => $duration],
          new TimedPresentationOperation($presentation, $duration),
        );
        return EventCommandResult::YIELDED;

      case 'field_animation':
        $reference = $command['animation'] ?? $command['id'] ?? null;
        $library = new AnimationLibrary();
        $animation = is_numeric($reference)
          ? $library->findById(intval($reference))
          : $library->findByName(strval($reference));

        if ($animation === null) {
          throw new RuntimeException(sprintf('Field animation "%s" was not found.', strval($reference)));
        }

        $target = is_array($command['target'] ?? null) ? $command['target'] : [];
        $screenSpace = strtolower(strval($target['kind'] ?? '')) === 'screen_position';
        $position = $screenSpace
          ? new Vector2(intval($target['x'] ?? 0), intval($target['y'] ?? 0))
          : (new CinematicSubjectResolver($this->gameScene))->position($target);
        $operation = new FieldAnimationOperation(
          $animation,
          $this->gameScene->cinematicPresentation
            ?? throw new RuntimeException('Cinematic presentation host is not configured.'),
          $position,
          $screenSpace,
          max(0.01, floatval($command['secondsPerFrame'] ?? 0.12)),
        );

        if ($operation->isComplete) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor($command, ['kind' => 'field_animation'], $operation);
        return EventCommandResult::YIELDED;

      case 'transition':
        $style = TransitionStyle::tryFrom(strtolower(strval($command['style'] ?? 'fade')))
          ?? throw new RuntimeException(sprintf('Unsupported transition style "%s".', strval($command['style'] ?? '')));
        $transition = new ScreenTransition(
          $style,
          max(0, intval(round(floatval($command['seconds'] ?? 0.24) * 1000))),
        );
        $direction = strval($command['direction'] ?? 'out');
        $transitionSession = $transition->session($direction);
        $operation = new TransitionOperation(
          $transitionSession,
          $this->gameScene->cinematicPresentation
            ?? throw new RuntimeException('Cinematic presentation host is not configured.'),
          $direction,
        );

        if ($transitionSession->isComplete) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor($command, ['kind' => 'transition'], $operation);
        return EventCommandResult::YIELDED;

      case 'clear_presentation':
        $this->gameScene->cinematicPresentation?->clear();
        return EventCommandResult::COMPLETED;

      case 'cinematic_music':
        $musicSession = $this->gameScene->getGame()->audioManager->beginCinematicMusic(
          CinematicMusicRequest::fromArray($command),
        );

        if ($musicSession->isReady) {
          return EventCommandResult::COMPLETED;
        }

        $lane->yieldFor(
          $command,
          ['kind' => 'cinematic_music'],
          new CinematicMusicOperation($musicSession),
        );
        return EventCommandResult::YIELDED;

      default:
        throw new RuntimeException($this->unknownCommandDiagnostic($session, $lane, $type));
    }
  }

  /**
   * Builds a fail-closed diagnostic with the deepest command-frame context.
   */
  protected function unknownCommandDiagnostic(
    EventExecutionSession $session,
    EventExecutionLane $lane,
    string $type,
  ): string
  {
    $frame = $lane->currentFrame();
    $details = [
      sprintf('script "%s"', $session->scriptId ?? 'inline script'),
      sprintf('lane "%s"', $lane->path),
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

  /** Builds a controlled diagnostic for any command execution failure. */
  protected function commandFailureDiagnostic(
    EventExecutionSession $session,
    EventExecutionLane $lane,
    array $command,
    Throwable $throwable,
  ): string
  {
    $reference = '';

    foreach (['subject', 'npcId', 'actorId', 'animation', 'music', 'sound', 'map', 'troop', 'id'] as $key) {
      $value = $command[$key] ?? null;

      if (is_scalar($value) && trim(strval($value)) !== '') {
        $reference = sprintf(', %s "%s"', $key, strval($value));
        break;
      }
    }

    if ($reference === '' && is_array($command['target'] ?? null)) {
      $target = $command['target'];
      $kind = trim(strval($target['kind'] ?? 'subject'));
      $identity = trim(strval($target['id'] ?? $target['npcId'] ?? $target['actorId'] ?? ''));
      $reference = $identity !== ''
        ? sprintf(', target %s "%s"', $kind, $identity)
        : sprintf(', target %s', $kind !== '' ? $kind : 'subject');
    }

    return sprintf(
      '%s "%s" failed in lane "%s" at command path "%s" (type "%s"%s): %s',
      $session->cinematic !== null ? 'Cinematic' : 'Event script',
      $session->scriptId ?? 'inline script',
      $lane->path,
      $lane->commandPath(),
      strval($command['type'] ?? '(empty)'),
      $reference,
      $throwable->getMessage(),
    );
  }

  /**
   * Updates a command that yielded on a prior tick.
   *
   * @return bool True when the command completed and immediate execution may continue.
   */
  protected function updatePendingCommand(
    EventExecutionSession $session,
    EventExecutionLane $lane,
    float $deltaSeconds,
  ): bool
  {
    $type = strval($lane->pendingCommand['type'] ?? '');

    if ($type === 'parallel') {
      $group = $lane->parallelGroup;

      if (! $group instanceof EventParallelGroup) {
        throw new RuntimeException('Parallel continuation is missing its child lanes.');
      }

      foreach ($group->lanes() as $childLane) {
        $this->tickLane($session, $childLane, $deltaSeconds);

        if ($childLane->status === EventExecutionStatus::FAILED) {
          throw new RuntimeException($childLane->failureMessage ?? 'A parallel lane failed.');
        }

        if ($session->status === EventExecutionStatus::SUSPENDED) {
          return false;
        }
      }

      if (! $group->isComplete()) {
        return false;
      }

      $lane->completePendingCommand();
      return true;
    }

    if ($type === 'wait') {
      $remaining = max(0.0, floatval($lane->pendingState['remainingSeconds'] ?? 0.0) - $deltaSeconds);

      if ($remaining > 0.0) {
        $lane->updatePendingState(['kind' => 'wait', 'remainingSeconds' => $remaining]);
        return false;
      }

      $lane->completePendingCommand();
      return true;
    }

    if ($type === 'text') {
      $this->presentation->update();
      $this->presentation->render();

      if (! $this->presentation->isComplete()) {
        return false;
      }

      $this->presentation->reset();
      $session->releasePresentation($lane);
      $lane->completePendingCommand();
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
      $session->releasePresentation($lane);
      $options = array_values(array_filter((array) ($lane->pendingCommand['options'] ?? []), is_array(...)));

      // Preserve the old SelectModal contract when no cancel arm is authored,
      // while allowing story-critical choices to acknowledge cancellation and
      // restore a clear retry path without treating it as a selection.
      if ($chosen === -1) {
        $commands = array_values(array_filter((array) ($lane->pendingCommand['cancel'] ?? []), is_array(...)));
        $lane->completePendingCommand();

        if ($commands !== []) {
          $lane->pushFrame($commands, 'choice:cancel');
        }

        return true;
      }

      if (! is_int($chosen) || ! isset($options[$chosen])) {
        throw new RuntimeException('Event choice was cancelled without selecting an option.');
      }

      $commands = array_values(array_filter((array) ($options[$chosen]['then'] ?? []), is_array(...)));
      $lane->completePendingCommand();
      $lane->pushFrame($commands, sprintf('choice:%d', $chosen));
      return true;
    }

    if ($lane->pendingOperation instanceof EventPendingOperationInterface) {
      if (! $lane->pendingOperation->update($deltaSeconds)) {
        return false;
      }

      $lane->completePendingCommand();
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

    if (! $session->claimCompletion()) {
      $this->fail('Event session attempted duplicate completion.');
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
    $this->gameScene->cinematicPresentation?->clear();
    $session->cancelLanes();
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
