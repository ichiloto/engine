<?php

// Deterministic pipe peer, not a renderer. No engine, Rust, GPUI, or desktop dependency.
declare(strict_types=1);

$scenario = $argv[1] ?? 'normal';
$capture = $argv[2] ?? null;
if (function_exists('pcntl_async_signals')) {
  pcntl_async_signals(true);
  pcntl_signal(SIGALRM, static fn() => exit(98));
  pcntl_alarm(8); // Hard guard even if a transport regression stops pumping.
  if (in_array($scenario, ['ignore_shutdown', 'malformed_stubborn'], true)) {
    pcntl_signal(SIGTERM, SIG_IGN);
  }
}
stream_set_timeout(STDIN, 8);

function rendererStubEvent(string $type, array $payload = [], ?int $protocol = null): string
{
  return json_encode(['protocol' => $protocol ?? $GLOBALS['protocol'] ?? 1, 'type' => $type, ...$payload], JSON_THROW_ON_ERROR) . "\n";
}

function rendererStubWrite(string $bytes, $stream = STDOUT): void
{
  while ($bytes !== '') {
    $written = @fwrite($stream, $bytes);
    if ($written === false || $written === 0) {
      exit(97);
    }
    $bytes = substr($bytes, $written);
  }
  fflush($stream);
}

if ($scenario === 'early_exit') {
  rendererStubWrite('early startup failure', STDERR);
  exit(23);
}

$hello = fgets(STDIN);
if ($hello === false) { exit(90); }
if ($capture !== null) { file_put_contents($capture, $hello, FILE_APPEND); }
$message = json_decode($hello, true, 64, JSON_THROW_ON_ERROR);
if (!in_array($message['protocol'] ?? null, [1, 2], true) || ($message['type'] ?? null) !== 'hello'
  || ! is_dir($message['assetRoot'] ?? '') || ! is_string($message['title'] ?? null)
  || count(array_filter($message['grid'] ?? [], static fn($value) => is_int($value) && $value > 0)) !== 4) {
  rendererStubWrite(rendererStubEvent('error', ['message' => 'invalid hello']));
  exit(91);
}
$protocol = $message['protocol'];
if (in_array($scenario, ['startup_error', 'pre_session_error', 'pre_session_key'], true)) {
  rendererStubWrite('startup diagnostic', STDERR);
  rendererStubWrite($scenario === 'pre_session_key'
    ? rendererStubEvent('key', ['key' => 'c'], 1)
    : rendererStubEvent('error', ['message' => 'hello rejected'], $scenario === 'pre_session_error' ? 1 : $protocol));
  usleep(2_000_000);
  exit(92);
}
if ($scenario === 'startup_timeout') {
  rendererStubWrite('waiting for an unavailable surface', STDERR);
  usleep(2_000_000);
  exit(93);
}
if ($scenario === 'stderr_flood') {
  rendererStubWrite(str_repeat('diagnostic-', 100000) . 'STARTUP-TAIL', STDERR);
}
$ready = rendererStubEvent('ready', $scenario === 'capabilities' && isset($message['requiredCapabilities'])
  ? ['capabilities' => $message['requiredCapabilities']] : []);
if ($scenario === 'ready_key') {
  $ready .= rendererStubEvent('key', ['key' => 'W']);
}
if ($scenario === 'ready_error') {
  $ready .= rendererStubEvent('error', ['message' => 'startup frame rejected']);
}
if ($scenario === 'ready_mixed') {
  $ready .= rendererStubEvent('error', ['message' => 'wrong session'], $protocol === 1 ? 2 : 1);
}
if ($scenario === 'chunked') {
  foreach (str_split($ready, 7) as $chunk) {
    rendererStubWrite($chunk);
    usleep(5000);
  }
} else {
  rendererStubWrite($ready);
}
if ($scenario === 'never_read') {
  usleep(2_000_000);
  exit(94);
}
if ($scenario === 'exit_pending') {
  usleep(100000);
  rendererStubWrite('exiting without reading application messages', STDERR);
  exit(7);
}
if ($scenario === 'slow_reader') { usleep(120000); }

$deadline = hrtime(true) + 7_000_000_000;
while (hrtime(true) < $deadline && ($line = fgets(STDIN)) !== false) {
  if ($capture !== null) { file_put_contents($capture, $line, FILE_APPEND); }
  $message = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
  if ($message['protocol'] !== $protocol) { exit(89); }
  if ($message['type'] === 'shutdown') {
    if ($scenario === 'ignore_shutdown') {
      while (hrtime(true) < $deadline) { usleep(10000); }
      exit(95);
    }
    rendererStubWrite('graceful shutdown', STDERR);
    exit($scenario === 'shutdown_nonzero' ? 17 : 0);
  }
  switch ($scenario) {
    case 'v2_events':
      rendererStubWrite(rendererStubEvent('key', ['key' => 'up']) . rendererStubEvent('error', ['message' => 'recoverable']));
      break;
    case 'mixed': rendererStubWrite(rendererStubEvent('key', ['key' => 'c'], $protocol === 1 ? 2 : 1)); break;
    case 'malformed_stubborn':
      rendererStubWrite("not JSON\n");
      while (hrtime(true) < $deadline) { usleep(10000); }
      exit(95);
    case 'all_events':
      rendererStubWrite("\n \r\n" . rendererStubEvent('key', ['key' => 'up'])
        . rendererStubEvent('error', ['message' => 'recoverable']) . rendererStubEvent('close_requested'));
      break;
    case 'malformed': rendererStubWrite("not JSON\n"); break;
    case 'nul_line': rendererStubWrite("\0\n"); break;
    case 'unsupported': rendererStubWrite("{\"protocol\":2,\"type\":\"key\",\"key\":\"w\"}\n"); break;
    case 'unknown': rendererStubWrite("{\"protocol\":1,\"type\":\"mystery\"}\n"); break;
    case 'missing_key': rendererStubWrite(rendererStubEvent('key')); break;
    case 'duplicate_ready': rendererStubWrite(rendererStubEvent('ready')); break;
    case 'incomplete':
      rendererStubWrite('{"protocol":1,"type":"key"');
      rendererStubWrite(str_repeat('trailing-diagnostic-', 10000) . 'FINAL-TAIL', STDERR);
      exit(0);
    case 'nonzero':
      rendererStubWrite(rendererStubEvent('key', ['key' => 'last-key']));
      rendererStubWrite(str_repeat('exit-diagnostic-', 10000) . 'FINAL-TAIL', STDERR);
      exit(7);
    case 'close_exit': rendererStubWrite(rendererStubEvent('close_requested')); exit(0);
    case 'close_wait':
      rendererStubWrite(rendererStubEvent('close_requested'));
      usleep(50000);
      exit(0);
    case 'exit': exit(0);
    case 'oversized': rendererStubWrite(str_repeat('x', 2048)); break;
    case 'event_flood': rendererStubWrite(str_repeat(rendererStubEvent('key', ['key' => 'x']), 20)); break;
    case 'chunked':
      foreach (str_split(rendererStubEvent('key', ['key' => 'W']), 5) as $chunk) {
        rendererStubWrite($chunk); usleep(5000);
      }
      break;
    case 'stderr_flood':
      rendererStubWrite(str_repeat('diagnostic-', 100000) . 'RUNTIME-TAIL', STDERR);
      rendererStubWrite(rendererStubEvent('key', ['key' => 'after-stderr']));
      break;
    default:
      $key = ($message['token'] ?? 'received') . ':' . hash('sha256', $message['data'] ?? '');
      rendererStubWrite(rendererStubEvent('key', ['key' => $key]));
  }
}
exit(96);
