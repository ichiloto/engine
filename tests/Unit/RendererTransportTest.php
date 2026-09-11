<?php

use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProcessExitedException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererProtocolException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererStartupException;
use Ichiloto\Engine\Rendering\Transport\Exceptions\RendererTransportException;
use Ichiloto\Engine\Rendering\Transport\Internal\RendererWriteBuffer;
use Ichiloto\Engine\Rendering\Transport\ProcessRendererTransport;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererTransportState;

/** Test-owned handles are explicitly cleaned even if an assertion fails. */
final class RendererTransportTestHandles
{
  public static array $transports = [];
}

afterEach(function () {
  foreach (RendererTransportTestHandles::$transports as $transport) {
    try { $transport->shutdown(); } catch (Throwable) { }
  }
  RendererTransportTestHandles::$transports = [];
});

function makeS2Transport(string $scenario = 'normal', array $overrides = [], ?string $capture = null): ProcessRendererTransport
{
  $command = [PHP_BINARY, __DIR__ . '/../Fixtures/Renderer/renderer-stub.php', $scenario];
  if ($capture !== null) { $command[] = $capture; }
  $transport = new ProcessRendererTransport(new RendererProcessConfig(...array_replace([
    'command' => $command, 'startupTimeout' => 1.0, 'shutdownTimeout' => 0.5, 'terminationTimeout' => 0.1,
  ], $overrides)));
  RendererTransportTestHandles::$transports[] = $transport;
  return $transport;
}

function s2Session(): RendererSessionConfig
{
  return new RendererSessionConfig('Transport test', sys_get_temp_dir());
}

function s2ApplicationMessage(string $token = 'test', string $data = ''): RendererMessage
{
  // Opaque test payloads exercise byte transport, not graphics/frame generation.
  return new RendererMessage(RendererMessageType::FRAME, ['token' => $token, 'data' => $data]);
}

function s2Await(ProcessRendererTransport $transport, callable $done): array
{
  $events = [];
  $deadline = hrtime(true) + 2_000_000_000;
  do {
    array_push($events, ...$transport->pollEvents(0.01));
    if ($done($events)) { return $events; }
  } while (hrtime(true) < $deadline);
  throw new RuntimeException('Test event deadline exceeded.');
}

it('starts a direct child, validates hello, and releases it on idempotent shutdown', function () {
  $capture = tempnam(sys_get_temp_dir(), 'renderer argv space-');
  try {
    $transport = makeS2Transport(capture: $capture);
    expect($transport->isRunning())->toBeFalse();
    $transport->start(s2Session());
    expect($transport->isRunning())->toBeTrue()->and($transport->getState())->toBe(RendererTransportState::RUNNING);
    expect($transport->pollEvents()[0]->type)->toBe(RendererEventType::READY);
    expect($transport->shutdown())->toBe(0)->and($transport->shutdown())->toBe(0)
      ->and($transport->isRunning())->toBeFalse()->and($transport->getState())->toBe(RendererTransportState::STOPPED);
    $lines = explode("\n", trim(file_get_contents($capture)));
    expect($lines)->toHaveCount(2);
    expect(json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR))->toBe([
      'protocol' => 1, 'type' => 'hello', 'title' => 'Transport test',
      'assetRoot' => realpath(sys_get_temp_dir()),
      'grid' => ['columns' => 80, 'rows' => 24, 'cellWidth' => 16, 'cellHeight' => 24],
    ])->and(json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR))->toBe(['protocol' => 1, 'type' => 'shutdown']);
    expect($transport->getDiagnostics())->toContain('graceful shutdown');
    $transport->start(s2Session());
    expect($transport->isRunning())->toBeTrue();
  } finally { unlink($capture); }
});

it('rejects send before start, double start, and manual lifecycle messages', function () {
  $transport = makeS2Transport();
  expect(fn() => $transport->send(s2ApplicationMessage()))->toThrow(RendererTransportException::class);
  $transport->start(s2Session());
  expect(fn() => $transport->start(s2Session()))->toThrow(RendererTransportException::class);
  foreach ([RendererMessageType::HELLO, RendererMessageType::SHUTDOWN] as $type) {
    expect(fn() => $transport->send(new RendererMessage($type)))->toThrow(RendererTransportException::class);
  }
});

it('preserves ready and key delivered in one startup read', function () {
  $transport = makeS2Transport('ready_key');
  $transport->start(s2Session());
  $events = s2Await($transport, fn($events) => count($events) === 2);
  expect(array_column($events, 'type'))->toBe([RendererEventType::READY, RendererEventType::KEY])
    ->and($events[1]->key)->toBe('W');
});

it('surfaces typed events in order and ignores blank lines without action policy', function () {
  $transport = makeS2Transport('all_events');
  $transport->start(s2Session());
  $transport->send(s2ApplicationMessage());
  $events = s2Await($transport, fn($events) => count($events) === 4);
  expect(array_column($events, 'type'))->toBe([RendererEventType::READY, RendererEventType::KEY,
    RendererEventType::ERROR, RendererEventType::CLOSE_REQUESTED]);
  expect($events[1]->key)->toBe('up')->and($events[2]->message)->toBe('recoverable')
    ->and($transport->isRunning())->toBeTrue();
});

it('rejects startup errors, timeouts, and early exit with diagnostics and released resources', function ($scenario, $diagnostic) {
  $transport = makeS2Transport($scenario, ['startupTimeout' => 0.15]);
  $start = hrtime(true);
  try {
    $transport->start(s2Session());
    test()->fail('Expected startup failure');
  } catch (RendererStartupException $error) {
    expect($error->diagnostics)->toContain($diagnostic)->and($error->getMessage())->toContain($diagnostic);
  }
  expect((hrtime(true) - $start) / 1e9)->toBeLessThan(1.5)
    ->and($transport->isRunning())->toBeFalse()->and($transport->getState())->toBe(RendererTransportState::FAILED);
  $transport->shutdown(); $transport->shutdown();
})->with([
  ['startup_error', 'startup diagnostic'], ['startup_timeout', 'unavailable surface'], ['early_exit', 'early startup failure'],
]);

it('reports an unavailable executable as a startup failure', function () {
  $transport = makeS2Transport(overrides: ['command' => ['/nonexistent/ichiloto-renderer']]);
  expect(fn() => $transport->start(s2Session()))->toThrow(RendererStartupException::class);
  expect($transport->isRunning())->toBeFalse();
});

it('buffers chunked handshake and key lines until complete', function () {
  $transport = makeS2Transport('chunked', ['ioBudgetBytes' => 11]);
  $transport->start(s2Session());
  expect($transport->pollEvents()[0]->type)->toBe(RendererEventType::READY);
  $transport->send(s2ApplicationMessage());
  $events = s2Await($transport, fn($events) => count($events) === 1);
  expect($events[0]->key)->toBe('W');
});

it('rejects protocol violations after startup and permits repeated cleanup', function ($scenario) {
  $transport = makeS2Transport($scenario, ['maxLineBytes' => 1024]);
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  expect(fn() => s2Await($transport, fn() => false))->toThrow(RendererProtocolException::class);
  expect($transport->isRunning())->toBeFalse();
  $transport->shutdown(); $transport->shutdown();
})->with(['malformed', 'nul_line', 'unsupported', 'unknown', 'missing_key', 'duplicate_ready', 'oversized']);

it('rejects a truncated EOF line and collects the final stderr tail before throwing', function () {
  $transport = makeS2Transport('incomplete', ['diagnosticBufferBytes' => 128, 'ioBudgetBytes' => 1024]);
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  try {
    s2Await($transport, fn() => false);
    test()->fail('Expected incomplete line violation');
  } catch (RendererProtocolException $error) {
    expect($error->getMessage())->toContain('incomplete NDJSON')->and($error->diagnostics)->toEndWith('FINAL-TAIL');
  }
});

it('surfaces the final complete event before a nonzero exit exception with final diagnostics', function () {
  $transport = makeS2Transport('nonzero', ['diagnosticBufferBytes' => 128, 'ioBudgetBytes' => 1024]);
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  $events = s2Await($transport, fn($events) => count($events) === 1);
  expect($events[0]->key)->toBe('last-key');
  try {
    s2Await($transport, fn() => false);
    test()->fail('Expected exit failure');
  } catch (RendererProcessExitedException $error) {
    expect($error->exitCode)->toBe(7)->and($error->diagnostics)->toEndWith('FINAL-TAIL');
  }
});

it('detects an unexpected clean child exit', function () {
  $transport = makeS2Transport('exit');
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  expect(fn() => s2Await($transport, fn() => false))->toThrow(RendererProcessExitedException::class);
  expect($transport->getExitCode())->toBe(0)->and($transport->isRunning())->toBeFalse();
});

it('preserves close_requested at process exit without deciding that PHP should quit', function () {
  $transport = makeS2Transport('close_exit');
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  $events = s2Await($transport, fn($events) => $transport->getState() === RendererTransportState::STOPPED);
  expect(array_column($events, 'type'))->toBe([RendererEventType::CLOSE_REQUESTED])
    ->and($transport->shutdown())->toBe(0)->and($transport->pollEvents())->toBe([]);
});

it('drains heavy stderr fairly into a bounded diagnostic tail', function () {
  $transport = makeS2Transport('stderr_flood', ['diagnosticBufferBytes' => 64]);
  $transport->start(s2Session()); $transport->pollEvents();
  expect(strlen($transport->getDiagnostics()))->toBe(64)->and($transport->getDiagnostics())->toEndWith('STARTUP-TAIL');
  $transport->send(s2ApplicationMessage());
  $events = s2Await($transport, fn($events) => count($events) === 1);
  expect($events[0]->key)->toBe('after-stderr')->and(strlen($transport->getDiagnostics()))->toBe(64)
    ->and($transport->getDiagnostics())->toEndWith('RUNTIME-TAIL');
});

it('writes large queued messages through real pipe backpressure without losing bytes or order', function () {
  $transport = makeS2Transport('slow_reader');
  $transport->start(s2Session()); $transport->pollEvents();
  $data = str_repeat("alpha\nbeta", 150000);
  $transport->send(s2ApplicationMessage('first', $data));
  $transport->send(s2ApplicationMessage('second', $data));
  $transport->pollEvents();
  expect($transport->getPendingWriteBytes())->toBeGreaterThan(0);
  $events = s2Await($transport, fn($events) => count($events) === 2);
  expect(array_column($events, 'key'))->toBe(['first:' . hash('sha256', $data), 'second:' . hash('sha256', $data)])
    ->and($transport->getPendingWriteBytes())->toBe(0);
});

it('reports a full outbound queue without changing already accepted bytes', function () {
  $transport = makeS2Transport(overrides: ['maxLineBytes' => 1024, 'maxOutboundBytes' => 1024]);
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage('first', str_repeat('x', 700)));
  $pending = $transport->getPendingWriteBytes();
  expect(fn() => $transport->send(s2ApplicationMessage('second', str_repeat('y', 700))))->toThrow(RendererTransportException::class);
  expect($transport->getPendingWriteBytes())->toBe($pending);
  $events = s2Await($transport, fn($events) => count($events) === 1);
  expect($events[0]->key)->toStartWith('first:');
});

it('rejects unencodable and overlarge outbound messages before queuing', function () {
  $transport = makeS2Transport(overrides: ['maxLineBytes' => 1024]);
  $transport->start(s2Session()); $transport->pollEvents();
  expect(fn() => $transport->send(new RendererMessage(RendererMessageType::FRAME, ['invalid' => INF])))
    ->toThrow(RendererProtocolException::class);
  expect(fn() => $transport->send(s2ApplicationMessage(data: str_repeat('x', 2000))))->toThrow(RendererTransportException::class);
  expect($transport->getPendingWriteBytes())->toBe(0)->and($transport->isRunning())->toBeTrue();
});

it('bounds pending events and reports overflow instead of silently dropping', function () {
  $transport = makeS2Transport('event_flood', ['maxPendingEvents' => 2]);
  $transport->start(s2Session()); $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  expect(fn() => s2Await($transport, fn() => false))->toThrow(RendererProtocolException::class);
  expect($transport->isRunning())->toBeFalse();
});

it('forces bounded cleanup when shutdown is ignored or stdin is not consumed', function ($scenario) {
  $transport = makeS2Transport($scenario, ['shutdownTimeout' => 0.12, 'terminationTimeout' => 0.06]);
  $transport->start(s2Session()); $transport->pollEvents();
  if ($scenario === 'never_read') { $transport->send(s2ApplicationMessage(data: str_repeat('x', 1000000))); }
  $started = hrtime(true);
  expect(fn() => $transport->shutdown())->toThrow(RendererTransportException::class, 'shutdown timed out');
  expect((hrtime(true) - $started) / 1e9)->toBeLessThan(1.5)->and($transport->isRunning())->toBeFalse();
  $transport->shutdown(); $transport->shutdown();
})->with(['ignore_shutdown', 'never_read']);

it('reports nonzero shutdown while keeping further shutdown calls idempotent', function () {
  $transport = makeS2Transport('shutdown_nonzero');
  $transport->start(s2Session()); $transport->pollEvents();
  expect(fn() => $transport->shutdown())->toThrow(RendererProcessExitedException::class);
  expect($transport->getExitCode())->toBe(17)->and($transport->shutdown())->toBe(17);
});

it('rejects negative or nonfinite waits and configuration deadlines', function ($value) {
  expect(fn() => new RendererProcessConfig([PHP_BINARY], startupTimeout: $value))->toThrow(InvalidArgumentException::class);
  expect(fn() => makeS2Transport()->pollEvents($value))->toThrow(InvalidArgumentException::class);
})->with([-1.0, INF, NAN]);

it('validates process argv and session geometry at the boundary', function () {
  foreach ([[], [''], [PHP_BINARY, "bad\0arg"]] as $command) {
    expect(fn() => new RendererProcessConfig($command))->toThrow(InvalidArgumentException::class);
  }
  foreach ([0, -1, 513] as $columns) {
    expect(fn() => new RendererGridConfig(columns: $columns))->toThrow(InvalidArgumentException::class);
  }
  expect(fn() => new RendererSessionConfig("bad\ntitle", sys_get_temp_dir()))->toThrow(InvalidArgumentException::class);
  expect(fn() => new RendererSessionConfig('test', 'relative/path'))->toThrow(InvalidArgumentException::class);
  expect(fn() => new RendererSessionConfig('test', __FILE__))->toThrow(InvalidArgumentException::class);
  expect(fn() => new RendererMessage(RendererMessageType::FRAME, ['protocol' => 2]))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid event envelopes and required field types', function ($line) {
  expect(fn() => RendererEvent::fromJson($line))->toThrow(RendererProtocolException::class);
})->with([
  'garbage', '[]', '{"type":"ready"}', '{"protocol":"1","type":"ready"}',
  '{"protocol":1,"type":"unknown"}', '{"protocol":1,"type":"key","key":3}',
  '{"protocol":1,"type":"error"}', '{"protocol":1,"type":"error","message":false}',
]);

it('preserves the exact remainder across zero and short writes', function () {
  $buffer = new RendererWriteBuffer(100);
  $buffer->append("one\n"); $buffer->append("two\n");
  $buffer->flush(fn() => 0, 100);
  expect($buffer->pendingBytes())->toBe(8);
  $received = '';
  $buffer->flush(function ($chunk) use (&$received) { $received .= substr($chunk, 0, 2); return min(2, strlen($chunk)); }, 3);
  expect($received)->toBe('one')->and($buffer->pendingBytes())->toBe(5);
  $buffer->flush(function ($chunk) use (&$received) { $received .= $chunk; return strlen($chunk); }, 100);
  expect($received)->toBe("one\ntwo\n")->and($buffer->pendingBytes())->toBe(0);
});

it('reports failed writes without consuming their pending bytes', function () {
  $buffer = new RendererWriteBuffer(10); $buffer->append("test\n");
  expect(fn() => $buffer->flush(fn() => false, 10))->toThrow(RendererTransportException::class);
  expect($buffer->pendingBytes())->toBe(5);
});

it('fails startup when ready and error arrive in the same handshake batch', function () {
  $transport = makeS2Transport('ready_error');
  expect(fn() => $transport->start(s2Session()))->toThrow(RendererStartupException::class, 'startup frame rejected');
  expect($transport->isRunning())->toBeFalse();
});

it('bounds event memory as well as event count', function () {
  $transport = makeS2Transport('event_flood', ['maxPendingEventBytes' => 128]);
  $transport->start(s2Session());
  $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  expect(fn() => s2Await($transport, fn() => false))->toThrow(RendererProtocolException::class, 'event limit');
});

it('does not block a runtime poll on TERM grace when a malformed peer resists cleanup', function () {
  $transport = makeS2Transport('malformed_stubborn', ['terminationTimeout' => 0.3]);
  $transport->start(s2Session());
  $transport->pollEvents();
  $transport->send(s2ApplicationMessage());
  $deadline = hrtime(true) + 2_000_000_000;
  $failed = false;
  do {
    $start = hrtime(true);
    try {
      $transport->pollEvents(0.01);
    } catch (RendererProtocolException) {
      $failed = true;
    }
    expect((hrtime(true) - $start) / 1e9)->toBeLessThan(0.2);
  } while (! $failed && hrtime(true) < $deadline);
  expect($failed)->toBeTrue()->and($transport->isRunning())->toBeFalse();
});

it('drains a native-close style exit without sending shutdown to a closing peer', function () {
  $capture = tempnam(sys_get_temp_dir(), 'renderer-close-');
  try {
    $transport = makeS2Transport('close_wait', capture: $capture);
    $transport->start(s2Session());
    $transport->pollEvents();
    $transport->send(s2ApplicationMessage());
    $events = s2Await($transport, fn($events) => count($events) === 1);
    expect($events[0]->type)->toBe(RendererEventType::CLOSE_REQUESTED);
    expect(fn() => $transport->send(s2ApplicationMessage()))->toThrow(RendererTransportException::class);
    expect($transport->shutdown())->toBe(0);
    expect(file_get_contents($capture))->not->toContain('"type":"shutdown"');
  } finally {
    unlink($capture);
  }
});

it('performs best-effort destructor cleanup without leaving the child running', function () {
  $transport = makeS2Transport();
  $transport->start(s2Session());
  $process = new ReflectionProperty($transport, 'process')->getValue($transport);
  RendererTransportTestHandles::$transports = [];
  unset($transport);
  expect(is_resource($process))->toBeFalse();
});

it('flushes a full application queue before shutdown and retains the final reply', function () {
  $transport = makeS2Transport(overrides: ['maxLineBytes' => 1024, 'maxOutboundBytes' => 1024]);
  $transport->start(s2Session());
  $transport->pollEvents();
  $data = str_repeat('x', 1024 - strlen(s2ApplicationMessage('queued')->encode()));
  $transport->send(s2ApplicationMessage('queued', $data));
  expect($transport->getPendingWriteBytes())->toBe(1024);
  expect($transport->shutdown())->toBe(0);
  expect($transport->pollEvents()[0]->key)->toBe('queued:' . hash('sha256', $data));
});

it('reports unsent bytes and exit diagnostics when the child exits under backpressure', function () {
  $transport = makeS2Transport('exit_pending');
  $transport->start(s2Session());
  $transport->pollEvents();
  $transport->send(s2ApplicationMessage(data: str_repeat('x', 1000000)));
  try {
    s2Await($transport, fn() => false);
    test()->fail('Expected failed pending output');
  } catch (RendererTransportException $error) {
    expect($error->getMessage())->toContain('pending')->and($error->exitCode)->toBe(7)
      ->and($error->diagnostics)->toContain('without reading');
  }
  expect($transport->getPendingWriteBytes())->toBeGreaterThan(0)->and($transport->isRunning())->toBeFalse();
});
