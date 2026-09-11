<?php

namespace Ichiloto\Engine\Rendering\Transport;

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProcessExitedException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererStartupException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Internal\RendererWriteBuffer;
use InvalidArgumentException;
use JsonException;
use Throwable;

/** Owns one direct child and its protocol pipes. Never owns engine/game policy. */
final class ProcessRendererTransport implements RendererTransportInterface
{
  private const float STATUS_INTERVAL = 0.02;
  private const int TERMINATE_SIGNAL = 15;
  private const int KILL_SIGNAL = 9;

  /** @var resource|null */
  private $process = null;
  /** @var array<int, resource> */
  private array $pipes = [];
  private RendererTransportState $state = RendererTransportState::NEW;
  private RendererWriteBuffer $outbound;
  private string $stdout = '';
  private string $diagnostics = '';
  /** @var list<RendererEvent> */
  private array $events = [];
  private ?RendererTransportException $failure = null;
  private ?int $exitCode = null;
  private bool $closeRequested = false;
  private int $eventBytes = 0;
  private int $terminationSignal = 0;
  private float $terminationDeadline = 0.0;

  public function __construct(private readonly RendererProcessConfig $config)
  {
    $this->outbound = new RendererWriteBuffer($config->maxOutboundBytes);
  }

  public function start(RendererSessionConfig $session): void
  {
    if ($this->process !== null || ! in_array($this->state, [RendererTransportState::NEW, RendererTransportState::STOPPED], true)) {
      throw new RendererTransportException('Renderer transport must be shut down before starting another session.');
    }
    $this->state = RendererTransportState::STARTING;
    $this->stdout = $this->diagnostics = '';
    $this->events = [];
    $this->eventBytes = 0;
    $this->failure = null;
    $this->exitCode = null;
    $this->closeRequested = false;
    $this->terminationSignal = 0;
    $this->outbound = new RendererWriteBuffer($this->config->maxOutboundBytes);
    $deadline = self::now() + $this->config->startupTimeout;

    try {
      if (PHP_OS_FAMILY === 'Windows') {
        throw new RendererStartupException('Nonblocking proc_open pipe transport currently requires a POSIX host.');
      }
      $pipes = [];
      $process = self::performIo(function () use (&$pipes) {
        return proc_open($this->config->command, [
          0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, null, null, ['bypass_shell' => true]);
      }, $launchError);
      if (! is_resource($process)) {
        throw new RendererStartupException('Could not launch renderer executable: ' . $launchError);
      }
      $this->process = $process;
      $this->pipes = $pipes;
      foreach ($this->pipes as $pipe) {
        if (! stream_set_blocking($pipe, false)) {
          throw new RendererStartupException('Cannot configure nonblocking renderer pipes.');
        }
      }
      // proc_open uses file-descriptor pipes, not buffered stdio FILE streams.
      // Their writes already go directly to the nonblocking descriptor.
      $this->queue($session->hello());
      while (self::now() < $deadline) {
        $this->serviceIo(min(self::STATUS_INTERVAL, max(0.0, $deadline - self::now())));
        foreach ($this->events as $event) {
          if ($event->type === RendererEventType::ERROR) {
            throw new RendererStartupException('Renderer rejected startup: ' . substr($event->message ?? '', 0, 1024));
          }
        }
        if ($this->hasReady() && $this->isRunning()) {
          $this->state = RendererTransportState::RUNNING;
          return; // ready and all other complete events remain available to pollEvents().
        }
      }
      throw new RendererStartupException('Timed out waiting for renderer ready.');
    } catch (Throwable $error) {
      $this->fail(new RendererStartupException($error->getMessage(), previous: $error));
      throw $this->failure;
    }
  }

  public function isRunning(): bool
  {
    if ($this->process === null) {
      return false;
    }
    $status = proc_get_status($this->process);
    if (! $status['running']) {
      if ($status['exitcode'] >= 0) {
        $this->exitCode = $status['exitcode'];
      } elseif ($status['signaled']) {
        $this->exitCode = 128 + $status['termsig'];
      }
    }
    return $status['running'];
  }

  public function send(RendererMessage $message): void
  {
    if ($this->state !== RendererTransportState::RUNNING || $this->closeRequested) {
      throw new RendererTransportException('Application messages require a running renderer session.', $this->diagnostics, $this->exitCode);
    }
    if (in_array($message->type, [RendererMessageType::HELLO, RendererMessageType::SHUTDOWN], true)) {
      throw new RendererTransportException('hello and shutdown are owned by the transport lifecycle.');
    }
    if (! $this->isRunning()) {
      $this->advance(0.0);
      throw $this->failure ?? new RendererProcessExitedException('Renderer exited before send.', $this->diagnostics, $this->exitCode);
    }
    $this->queue($message);
  }

  public function pollEvents(float $waitSeconds = 0.0): array
  {
    if (! is_finite($waitSeconds) || $waitSeconds < 0.0 || $waitSeconds > 1.0) {
      throw new InvalidArgumentException('Poll wait must be finite and between zero and one second.');
    }
    $this->advance($this->events === [] ? $waitSeconds : 0.0);
    if ($this->events !== []) {
      $events = $this->events;
      $this->events = [];
      $this->eventBytes = 0;
      return $events;
    }
    if ($this->failure !== null && $this->terminationSignal === 0) {
      throw $this->failureWithDiagnostics();
    }
    return [];
  }

  public function shutdown(): ?int
  {
    if ($this->failure !== null && $this->process !== null) {
      $this->forceCleanup();
      throw $this->failureWithDiagnostics();
    }
    if ($this->process === null) {
      $this->state = RendererTransportState::STOPPED;
      return $this->exitCode;
    }
    // Preserve unexpected-exit classification when shutdown first observes a dead child.
    if ($this->isRunning()) {
      $this->state = RendererTransportState::STOPPING;
    }
    $deadline = self::now() + $this->config->shutdownTimeout;
    $shutdown = (new RendererMessage(RendererMessageType::SHUTDOWN))->encode();
    $queued = false;
    try {
      while ($this->process !== null && self::now() < $deadline) {
        if (! $queued && ! $this->closeRequested && $this->isRunning()
          && $this->outbound->pendingBytes() + strlen($shutdown) <= $this->config->maxOutboundBytes) {
          $this->outbound->append($shutdown);
          $queued = true;
        }
        $this->serviceIo(min(self::STATUS_INTERVAL, max(0.0, $deadline - self::now())));
        if (($queued || $this->closeRequested) && $this->outbound->pendingBytes() === 0) {
          $this->closePipe(0);
        }
      }
      if ($this->process !== null) {
        throw new RendererTransportException('Renderer shutdown timed out with ' . $this->outbound->pendingBytes() . ' outbound bytes pending.');
      }
      return $this->exitCode;
    } catch (RendererTransportException $error) {
      $this->fail($error);
      throw $this->failure;
    }
  }

  public function getState(): RendererTransportState
  {
    return $this->state;
  }

  public function getExitCode(): ?int
  {
    return $this->exitCode;
  }

  public function getDiagnostics(): string
  {
    return $this->diagnostics;
  }

  public function getPendingWriteBytes(): int
  {
    return $this->outbound->pendingBytes();
  }

  public function __destruct()
  {
    try {
      $this->forceCleanup();
    } catch (Throwable) {
      // Destruction must never throw; explicit shutdown is the normal API.
    }
  }

  private function queue(RendererMessage $message): void
  {
    try {
      $line = $message->encode();
    } catch (JsonException $error) {
      throw new RendererProtocolException('Cannot encode renderer message: ' . $error->getMessage(), $this->diagnostics, previous: $error);
    }
    if (strlen($line) > $this->config->maxLineBytes) {
      throw new RendererTransportException('Outbound renderer line exceeds the configured byte limit; message was not queued.');
    }
    $this->outbound->append($line);
  }

  private function advance(float $wait): void
  {
    if ($this->process === null) {
      return;
    }
    if ($this->failure !== null) {
      $this->cleanupStep($wait);
      return;
    }
    try {
      $this->serviceIo($wait);
    } catch (RendererTransportException $error) {
      $this->beginFailure($error);
      $this->cleanupStep(0.0);
    }
  }

  /** One fair, bounded pass: each readable pipe and stdin have their own byte budget. */
  private function serviceIo(float $wait): void
  {
    $read = array_values(array_intersect_key($this->pipes, [1 => true, 2 => true]));
    $write = isset($this->pipes[0]) && ! $this->closeRequested && $this->outbound->pendingBytes() > 0 && $this->isRunning()
      ? [$this->pipes[0]] : [];
    $except = [];
    if ($read !== [] || $write !== []) {
      $seconds = (int) $wait;
      $selected = @stream_select($read, $write, $except, $seconds, (int) (($wait - $seconds) * 1_000_000));
      if ($selected === false && $this->isRunning()) {
        throw new RendererTransportException('Could not select renderer pipes.');
      }
    } elseif ($wait > 0.0) {
      // A child can close both output pipes before exiting; select has no fds then.
      time_nanosleep(0, (int) (min($wait, self::STATUS_INTERVAL) * 1_000_000_000));
    }
    // Read stderr first so a simultaneous stdout error includes current diagnostics.
    foreach ([2, 1] as $index) {
      if (isset($this->pipes[$index]) && in_array($this->pipes[$index], $read, true)) {
        $this->readPipe($index);
      }
    }
    if ($write !== [] && isset($this->pipes[0]) && ! $this->closeRequested) {
      $pipe = $this->pipes[0];
      $this->outbound->flush(static fn(string $bytes): int|false => self::performIo(
        static fn() => fwrite($pipe, $bytes)), $this->config->ioBudgetBytes);
    }
    $this->collectExit();
  }

  private function readPipe(int $index, bool $discardStdout = false): void
  {
    $budget = $this->config->ioBudgetBytes;
    while (isset($this->pipes[$index]) && $budget > 0) {
      $bytes = @fread($this->pipes[$index], min(8192, $budget));
      if ($bytes === false) {
        throw new RendererTransportException('Cannot read renderer ' . ($index === 1 ? 'stdout' : 'stderr') . '.');
      }
      if ($bytes === '') {
        if (feof($this->pipes[$index])) {
          $this->closePipe($index);
          if ($index === 1 && ! $discardStdout && $this->stdout !== '') {
            throw new RendererProtocolException('Renderer stdout ended with an incomplete NDJSON line.');
          }
        }
        break;
      }
      $budget -= strlen($bytes);
      if ($index === 2) {
        $this->diagnostics = substr($this->diagnostics . $bytes, -$this->config->diagnosticBufferBytes);
      } elseif (! $discardStdout) {
        $this->stdout .= $bytes;
        $this->parseLines();
      }
    }
  }

  private function parseLines(): void
  {
    while (($newline = strpos($this->stdout, "\n")) !== false) {
      if ($newline + 1 > $this->config->maxLineBytes) {
        throw new RendererProtocolException('Renderer stdout line exceeds the configured byte limit.');
      }
      $line = substr($this->stdout, 0, $newline);
      $this->stdout = substr($this->stdout, $newline + 1);
      if (trim($line, " \t\r") === '') {
        continue;
      }
      $event = RendererEvent::fromJson($line);
      if ($event->type === RendererEventType::READY
        && ($this->state !== RendererTransportState::STARTING || $this->hasReady())) {
        throw new RendererProtocolException('Renderer emitted an unexpected second ready event.');
      }
      if ($this->state === RendererTransportState::STARTING && ! $this->hasReady()
        && ! in_array($event->type, [RendererEventType::READY, RendererEventType::ERROR], true)) {
        throw new RendererProtocolException('Renderer emitted an application event before ready.');
      }
      if (count($this->events) >= $this->config->maxPendingEvents
        || $this->eventBytes + strlen($line) > $this->config->maxPendingEventBytes) {
        throw new RendererProtocolException('Renderer pending event limit exceeded.');
      }
      $this->events[] = $event;
      $this->eventBytes += strlen($line);
      if ($event->type === RendererEventType::CLOSE_REQUESTED) {
        $this->closeRequested = true;
      }
    }
    if (strlen($this->stdout) >= $this->config->maxLineBytes) {
      throw new RendererProtocolException('Renderer stdout line exceeds the configured byte limit.');
    }
  }

  private function hasReady(): bool
  {
    foreach ($this->events as $event) {
      if ($event->type === RendererEventType::READY) {
        return true;
      }
    }
    return false;
  }

  private function collectExit(): void
  {
    if ($this->isRunning() || isset($this->pipes[1]) || isset($this->pipes[2])) {
      return; // A budget-limited read is not EOF. Keep draining subsequent pumps.
    }
    $pending = $this->outbound->pendingBytes();
    $expected = $this->state === RendererTransportState::STOPPING || $this->closeRequested;
    $this->releaseProcess();
    if ($this->state === RendererTransportState::STARTING || ! $expected || $this->exitCode !== 0 || $pending > 0) {
      throw new RendererProcessExitedException('Renderer process exited' . ($pending > 0 ? " with {$pending} outbound bytes pending." : '.'));
    }
    $this->state = RendererTransportState::STOPPED;
  }

  private function fail(RendererTransportException $error): void
  {
    $this->beginFailure($error);
    $cleanupFailure = $this->forceCleanup();
    $class = $error::class;
    $this->failure = new $class($error->reason . ($cleanupFailure !== null ? '; ' . $cleanupFailure : ''),
      $this->diagnostics, $this->exitCode, $error);
  }

  private function beginFailure(RendererTransportException $error): void
  {
    $this->state = RendererTransportState::FAILED;
    $this->failure = $error;
    $this->closePipe(0);
    if ($this->process !== null && $this->terminationSignal === 0) {
      $this->signalTermination(self::TERMINATE_SIGNAL);
    }
  }

  private function failureWithDiagnostics(): RendererTransportException
  {
    $error = $this->failure;
    $class = $error::class;
    $reason = $error->reason;
    if ($this->process !== null && $this->terminationSignal === 0) {
      $reason .= '; Operating system did not reap the renderer after TERM/KILL deadlines';
    }
    return new $class($reason, $this->diagnostics, $this->exitCode, $error);
  }

  private function signalTermination(int $signal): void
  {
    $this->terminationSignal = $signal;
    $this->terminationDeadline = self::now() + $this->config->terminationTimeout;
    if ($this->isRunning()) {
      @proc_terminate($this->process, $signal);
    }
  }

  /** Fatal runtime cleanup advances with polling, never waits for a whole termination deadline. */
  private function cleanupStep(float $wait): void
  {
    $read = array_values(array_intersect_key($this->pipes, [1 => true, 2 => true]));
    $write = $except = [];
    $micros = (int) (min($wait, self::STATUS_INTERVAL, max(0.0, $this->terminationDeadline - self::now())) * 1_000_000);
    if ($read !== []) {
      @stream_select($read, $write, $except, 0, $micros);
    } elseif ($micros > 0) {
      time_nanosleep(0, $micros * 1000);
    }
    foreach ([2, 1] as $index) {
      if (isset($this->pipes[$index])) {
        try {
          $this->readPipe($index, true);
        } catch (RendererTransportException) {
          $this->closePipe($index);
        }
      }
    }
    if (! $this->isRunning() && ! isset($this->pipes[1]) && ! isset($this->pipes[2])) {
      $this->releaseProcess();
      $this->terminationSignal = 0;
    } elseif (self::now() >= $this->terminationDeadline) {
      if ($this->terminationSignal === self::TERMINATE_SIGNAL) {
        $this->signalTermination(self::KILL_SIGNAL);
      } else {
        foreach (array_keys($this->pipes) as $index) {
          $this->closePipe($index);
        }
        if (! $this->isRunning()) {
          $this->releaseProcess();
        }
        $this->terminationSignal = 0;
      }
    }
  }

  /** No proc_close on a live child: that API is an unbounded wait. */
  private function forceCleanup(): ?string
  {
    if ($this->process === null) {
      return null;
    }
    $this->closePipe(0);
    if ($this->terminationSignal === 0) {
      $this->signalTermination(self::TERMINATE_SIGNAL);
    }
    while ($this->terminationSignal !== 0) {
      $this->cleanupStep(self::STATUS_INTERVAL);
    }
    return $this->process !== null ? 'Operating system did not reap the renderer after TERM/KILL deadlines' : null;
  }

  private function closePipe(int $index): void
  {
    if (isset($this->pipes[$index])) {
      fclose($this->pipes[$index]);
      unset($this->pipes[$index]);
    }
  }

  private function releaseProcess(): void
  {
    foreach (array_keys($this->pipes) as $index) {
      $this->closePipe($index);
    }
    if ($this->process !== null) {
      $status = proc_close($this->process);
      if ($this->exitCode === null && $status >= 0) {
        $this->exitCode = $status;
      }
      $this->process = null;
    }
  }

  private static function now(): float
  {
    return hrtime(true) / 1_000_000_000;
  }

  /** Return codes are converted to transport exceptions, not leaked to host error handlers. */
  private static function performIo(callable $operation, ?string &$diagnostic = null): mixed
  {
    $diagnostic = '';
    set_error_handler(static function (int $severity, string $message) use (&$diagnostic): bool {
      $diagnostic = substr($message, 0, 1024);
      return true;
    });
    try {
      return $operation();
    } finally {
      restore_error_handler();
    }
  }
}
