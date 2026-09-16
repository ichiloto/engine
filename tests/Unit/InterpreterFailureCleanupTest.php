<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\GameState;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicDefinition;
use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Events\Interpreter\EventExecutionLane;
use Ichiloto\Engine\Events\Interpreter\EventExecutionSession;
use Ichiloto\Engine\Events\Interpreter\EventExecutionStatus;
use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\Events\Interpreter\EventParallelGroup;
use Ichiloto\Engine\Events\Interpreter\EventPendingOperationInterface;
use Ichiloto\Engine\Events\Interpreter\EventPresentationInterface;
use Ichiloto\Engine\Events\Interpreter\EventSessionCompletionTargetInterface;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

final class FailureCleanupScene extends GameScene
{
  public array $finished = [];
  public bool $throwOnFinish = false;
  public function __construct()
  {
    $this->currentMapId = 'cleanup-test';
    $this->gameState = new GameState();
    $this->cinematicPresentation = new CinematicPresentationManager($this);
  }
  public function onEventSessionStarted(EventExecutionSession $session): void {}
  public function onEventSessionFinished(EventExecutionSession $session, bool $completed): void
  {
    $this->finished[] = [$session, $completed];
    if ($this->throwOnFinish) { throw new RuntimeException('scene finish failed'); }
  }
  public function install(EventInterpreter $interpreter): void { $this->eventInterpreter = $interpreter; }
}

final class FailureCleanupPresentation implements EventPresentationInterface
{
  public int $resets = 0;
  public bool $throwOnReset = false;
  public function beginText(string $text, string $name = ''): void {}
  public function beginChoice(string $prompt, array $options, string $title = ''): void {}
  public function update(): void {}
  public function render(): void {}
  public function isComplete(): bool { return false; }
  public function choiceResult(): ?int { return null; }
  public function reset(): void
  {
    $this->resets++;
    if ($this->throwOnReset) { throw new RuntimeException('presentation reset failed'); }
  }
}

final class FailureCleanupTarget implements EventSessionCompletionTargetInterface
{
  public int $failed = 0;
  public int $completed = 0;
  public ?Closure $onFailure = null;
  public function onEventSessionCompleted(GameScene $gameScene, EventExecutionSession $session): void { $this->completed++; }
  public function onEventSessionFailed(GameScene $gameScene, EventExecutionSession $session): void
  {
    $this->failed++;
    ($this->onFailure)?->__invoke($gameScene, $session);
  }
}

final class FailureCleanupOperation implements EventPendingOperationInterface
{
  public int $cancelled = 0;
  public function __construct(private ?Closure $onCancel = null) {}
  public function update(float $deltaSeconds): bool { return false; }
  public function cancel(): void
  {
    $this->cancelled++;
    ($this->onCancel)?->__invoke();
  }
}

beforeEach(function () {
  $this->debugBefore = new ReflectionClass(Debug::class)->getStaticProperties();
  $this->logs = sys_get_temp_dir() . '/ichiloto-failure-cleanup-' . bin2hex(random_bytes(4));
  mkdir($this->logs);
  Debug::configure(['log_directory' => $this->logs]);
  $this->scene = new FailureCleanupScene();
  $this->presentation = new FailureCleanupPresentation();
  $this->target = new FailureCleanupTarget();
  $this->interpreter = new EventInterpreter($this->scene, $this->presentation);
  $this->scene->install($this->interpreter);
  $this->session = $this->interpreter->start([['type' => 'wait', 'seconds' => 10]], 'cleanup', $this->target);
});

afterEach(function () {
  foreach ($this->debugBefore as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
  foreach (glob($this->logs . '/*') ?: [] as $path) { unlink($path); }
  rmdir($this->logs);
});

it('cancels operations and notifies failure even when presentation reset throws', function () {
  $operation = new FailureCleanupOperation();
  $this->session->rootLane()->yieldFor(['type' => 'wait'], [], $operation);
  $this->presentation->throwOnReset = true;
  $this->scene->cinematicPresentation->hideField();
  $this->target->onFailure = function (GameScene $scene, EventExecutionSession $session): void {
    expect($scene->eventInterpreter->activeSession())->toBe($session)
      ->and($session->status)->toBe(EventExecutionStatus::FAILED);
  };
  $this->interpreter->failActiveSession('original route failure');
  expect($operation->cancelled)->toBe(1)->and($this->target->failed)->toBe(1)
    ->and($this->target->completed)->toBe(0)->and($this->scene->finished)->toBe([[$this->session, false]])
    ->and($this->interpreter->activeSession())->toBeNull()->and($this->interpreter->lastSession)->toBe($this->session)
    ->and($this->session->failureMessage)->toBe('original route failure')
    ->and($this->scene->cinematicPresentation->hasTransitionCover())->toBeFalse();
  $this->interpreter->failActiveSession('not another failure');
  expect($operation->cancelled)->toBe(1)->and($this->target->failed)->toBe(1)
    ->and($this->presentation->resets)->toBe(1)
    ->and(file_get_contents($this->logs . '/error.log'))->toContain('original route failure', 'presentation reset failed');
});

it('cancels every nested sibling despite multiple throwing operations and retains primary failure', function () {
  $group = new EventParallelGroup([
    ['id' => 'first', 'commands' => [['type' => 'wait']]],
    ['id' => 'second', 'commands' => [['type' => 'wait']]],
    ['id' => 'third', 'commands' => [['type' => 'wait']]],
  ], 'root');
  $operations = [];
  foreach ($group->lanes() as $index => $lane) {
    $operation = new FailureCleanupOperation(static function () use ($index): void {
      if ($index < 2) { throw new RuntimeException('cancel failure ' . $index); }
    });
    $operations[] = $operation;
    $lane->yieldFor(['type' => 'wait'], [], $operation);
  }
  $this->session->rootLane()->yieldForParallel(['type' => 'parallel'], $group);
  $this->interpreter->failActiveSession('original command failure');
  foreach ($operations as $operation) { expect($operation->cancelled)->toBe(1); }
  foreach ($group->lanes() as $lane) {
    expect($lane->pendingOperation)->toBeNull()->and($lane->frames())->toBe([])
      ->and($lane->status)->toBe(EventExecutionStatus::COMPLETED);
  }
  expect($this->session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->session->failureMessage)->toBe('original command failure')
    ->and($this->target->failed)->toBe(1)->and($this->interpreter->activeSession())->toBeNull()
    ->and(file_get_contents($this->logs . '/error.log'))->toContain('cancel failure 0', 'cancel failure 1', 'original command failure');
  $group->cancel();
  foreach ($operations as $operation) { expect($operation->cancelled)->toBe(1); }
});

it('detaches all pending lane ownership before invoking cancellation', function () {
  $lane = new EventExecutionLane([['type' => 'wait']], 'detached', 'test');
  $operation = new FailureCleanupOperation(static function () use ($lane): void {
    expect($lane->pendingOperation)->toBeNull()->and($lane->pendingCommand)->toBeNull()
      ->and($lane->pendingState)->toBe([])->and($lane->frameToPush)->toBeNull();
    $lane->cancel();
    throw new RuntimeException('operation cancel failed');
  });
  $lane->queueFrame([['type' => 'wait']], 'next');
  $lane->yieldFor(['type' => 'wait'], ['timer' => 1], $operation);
  expect(fn() => $lane->fail('original lane failure'))->toThrow(RuntimeException::class, 'operation cancel failed');
  expect($lane->status)->toBe(EventExecutionStatus::FAILED)->and($lane->failureMessage)->toBe('original lane failure');
  $lane->fail('secondary lane failure');
  expect($operation->cancelled)->toBe(1)->and($lane->failureMessage)->toBe('original lane failure');
});

it('blocks reentrant failure and new sessions during the terminal failure callback', function () {
  $operation = new FailureCleanupOperation(function (): void {
    $this->interpreter->failActiveSession('reentrant cancellation failure');
  });
  $this->session->rootLane()->yieldFor(['type' => 'wait'], [], $operation);
  $this->target->onFailure = function (): void {
    $this->interpreter->failActiveSession('reentrant callback failure');
    expect($this->interpreter->start([['type' => 'wait']], 'early retry'))->toBeNull();
  };
  $this->interpreter->failActiveSession('primary failure');
  expect($this->target->failed)->toBe(1)->and($operation->cancelled)->toBe(1)
    ->and($this->presentation->resets)->toBe(1)->and($this->session->failureMessage)->toBe('primary failure')
    ->and($this->scene->finished)->toBe([[$this->session, false]]);
});

it('clears active ownership even when both terminal notifications throw', function () {
  $this->target->onFailure = static function (): void { throw new RuntimeException('target failure cleanup failed'); };
  $this->scene->throwOnFinish = true;
  $this->interpreter->failActiveSession('primary command failure');
  expect($this->interpreter->activeSession())->toBeNull()->and($this->interpreter->lastSession)->toBe($this->session)
    ->and($this->session->failureMessage)->toBe('primary command failure')
    ->and($this->target->failed)->toBe(1)->and($this->scene->finished)->toHaveCount(1)
    ->and(file_get_contents($this->logs . '/error.log'))->toContain('target failure cleanup failed', 'scene finish failed');
});

it('fails skip entry without outcome writes when reset or cancellation throws', function (bool $resetThrows) {
  $this->session->configureCinematic(CinematicDefinition::fromArrays([
    'id' => 'cleanup', 'name' => 'Cleanup', 'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'set_switch', 'name' => 'skip-completed', 'value' => true]],
  ], [['type' => 'wait', 'seconds' => 10]]));
  $operation = new FailureCleanupOperation(static function () use ($resetThrows): void {
    if (!$resetThrows) { throw new RuntimeException('skip cancel failed'); }
  });
  $this->session->rootLane()->yieldFor(['type' => 'wait'], [], $operation);
  $subject = new stdClass();
  $this->session->claimMovementSubject($subject);
  $this->session->claimPresentation($this->session->rootLane());
  $this->scene->cinematicPresentation->hideField();
  $this->presentation->throwOnReset = $resetThrows;

  expect($this->interpreter->skipActiveCinematic())->toBeFalse()
    ->and($this->session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->session->failureMessage)->toContain($resetThrows ? 'presentation reset failed' : 'skip cancel failed')
    ->and($this->session->isFinalizing)->toBeFalse()
    ->and($this->session->frames())->toBe([])
    ->and($this->interpreter->activeSession())->toBeNull()
    ->and($this->scene->gameState->getSwitch('skip-completed'))->toBeFalse()
    ->and($this->scene->cinematicPresentation->hasTransitionCover())->toBeFalse()
    ->and($this->scene->finished)->toBe([[$this->session, false]])
    ->and($this->target->completed)->toBe(0)->and($this->target->failed)->toBe(1)
    ->and($operation->cancelled)->toBe(1);
  expect(fn() => $this->session->claimMovementSubject($subject))->not->toThrow(Throwable::class);
  expect(fn() => $this->session->claimPresentation(new EventExecutionLane([], 'new-owner')))
    ->not->toThrow(Throwable::class);
  $this->interpreter->update(10);
  expect($this->interpreter->skipActiveCinematic())->toBeFalse()
    ->and($this->target->failed)->toBe(1)->and($this->target->completed)->toBe(0);
})->with(['reset' => [true], 'cancel' => [false]]);

it('refuses reentrant skip while the failed session still owns its callback identity', function () {
  $this->session->configureCinematic(CinematicDefinition::fromArrays([
    'id' => 'cleanup', 'name' => 'Cleanup', 'skip' => ['policy' => 'authored'],
    'finalizer' => [['type' => 'set_switch', 'name' => 'skip-completed', 'value' => true]],
  ], [['type' => 'wait', 'seconds' => 10]]));
  $this->target->onFailure = function (): void {
    expect($this->interpreter->activeSession())->toBe($this->session)
      ->and($this->interpreter->skipActiveCinematic())->toBeFalse();
  };
  $this->interpreter->failActiveSession('primary failure');
  expect($this->session->status)->toBe(EventExecutionStatus::FAILED)
    ->and($this->session->failureMessage)->toBe('primary failure')
    ->and($this->session->isFinalizing)->toBeFalse()
    ->and($this->scene->gameState->getSwitch('skip-completed'))->toBeFalse()
    ->and($this->target->completed)->toBe(0)->and($this->target->failed)->toBe(1)
    ->and($this->scene->finished)->toBe([[$this->session, false]])
    ->and($this->interpreter->activeSession())->toBeNull();
});

it('falls back to the process logger only after cleanup if the project log fails', function () {
  file_put_contents($this->logs . '/not-a-directory', 'blocked');
  Debug::configure(['log_directory' => $this->logs . '/not-a-directory']);
  $previousLog = ini_get('error_log');
  ini_set('error_log', $this->logs . '/fallback.log');
  set_error_handler(static function (int $severity, string $message): never { throw new ErrorException($message, 0, $severity); });
  try {
    $this->presentation->throwOnReset = true;
    $this->interpreter->failActiveSession('primary before logger');
  } finally {
    restore_error_handler();
    ini_set('error_log', $previousLog);
  }
  expect($this->interpreter->activeSession())->toBeNull()->and($this->target->failed)->toBe(1)
    ->and($this->session->failureMessage)->toBe('primary before logger')
    ->and(file_get_contents($this->logs . '/fallback.log'))->toContain('primary before logger', 'presentation reset failed', 'Project logger failed');
});
