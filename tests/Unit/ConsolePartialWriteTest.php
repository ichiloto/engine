<?php

use Ichiloto\Engine\IO\Console\Console;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * A ConsoleOutput whose stream is supplied by the test.
 *
 * Console types its output property as ConsoleOutput, so the test double has
 * to extend it rather than swap in a bare StreamOutput.
 */
class TestStreamConsoleOutput extends ConsoleOutput
{
  /** @var resource */
  private $testStream;

  /**
   * @param resource $testStream The stream terminal output should go to.
   */
  public function __construct($testStream)
  {
    parent::__construct();
    $this->testStream = $testStream;
  }

  public function getStream()
  {
    return $this->testStream;
  }
}

/**
 * Records the largest payload PHP offers to the backing stream.
 *
 * Terminal writes are deliberately bounded so a full styled scene cannot be
 * handed to a PTY as one oversized request. This wrapper verifies that policy
 * independently from the OS-pipe delivery test below.
 */
class ConsoleWriteProbeStream
{
  public $context;
  public static int $largestWrite = 0;
  public static string $received = '';

  public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
  {
    self::$largestWrite = 0;
    self::$received = '';

    return true;
  }

  public function stream_write(string $data): int
  {
    self::$largestWrite = max(self::$largestWrite, strlen($data));
    self::$received .= $data;

    return strlen($data);
  }

  public function stream_eof(): bool
  {
    return false;
  }

  public function stream_stat(): array
  {
    return [];
  }

  public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
  {
    return true;
  }
}

/**
 * Runs a payload through Console's terminal writer into a real non-blocking
 * OS pipe drained by a child process, and reports how many bytes arrived.
 *
 * A userland stream wrapper cannot reproduce a short OS write: PHP's stream
 * layer retries wrapper writes internally, so fwrite() reports the complete
 * amount. A genuine non-blocking descriptor exercises the partial-write and
 * back-pressure path used during controlled terminal flushes.
 *
 * @param string $payload The payload to write.
 * @return int|null The number of bytes that reached the far end, or null when
 *   the child process could not be started.
 */
function writePayloadThroughConsole(string $payload): ?int
{
  $sinkPath = tempnam(sys_get_temp_dir(), 'ichiloto-console-sink-');

  $descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $sinkPath, 'w'],
    2 => ['file', '/dev/null', 'w'],
  ];

  // "cat" drains the pipe steadily, so the writer sees the buffer fill and
  // free repeatedly — the same short-write pattern a terminal produces.
  $process = @proc_open(['cat'], $descriptors, $pipes);

  if (! is_resource($process)) {
    @unlink($sinkPath);

    return null;
  }

  // The condition that triggers the bug.
  stream_set_blocking($pipes[0], false);

  $outputProperty = new ReflectionProperty(Console::class, 'output');
  $previousOutput = $outputProperty->getValue();
  $outputProperty->setValue(null, new TestStreamConsoleOutput($pipes[0]));

  try {
    new ReflectionMethod(Console::class, 'writeToTerminal')->invoke(null, $payload);
  } finally {
    $outputProperty->setValue(null, $previousOutput);
    @fclose($pipes[0]);
    proc_close($process);
  }

  $received = (int) filesize($sinkPath);
  @unlink($sinkPath);

  return $received;
}

it('delivers a payload larger than the pipe buffer in full', function () {
  // Comfortably larger than any terminal or pipe buffer, and the same order
  // of magnitude as a frame for a large map.
  $payload = str_repeat('ABCDEFGH', 100_000); // 800 KB

  $received = writePayloadThroughConsole($payload);

  if ($received === null) {
    $this->markTestSkipped('proc_open is unavailable in this environment.');
  }

  expect($received)->toBe(strlen($payload));
})->skipOnWindows();

it('offers complete scene payloads to the terminal in bounded chunks', function () {
  $scheme = 'ichiloto-write-probe';

  if (! in_array($scheme, stream_get_wrappers(), true)) {
    stream_wrapper_register($scheme, ConsoleWriteProbeStream::class);
  }

  $stream = fopen($scheme . '://terminal', 'w');
  $outputProperty = new ReflectionProperty(Console::class, 'output');
  $previousOutput = $outputProperty->getValue();
  $outputProperty->setValue(null, new TestStreamConsoleOutput($stream));
  $payload = str_repeat('styled-field-row;', 10_000);

  try {
    new ReflectionMethod(Console::class, 'writeToTerminal')->invoke(null, $payload);
  } finally {
    $outputProperty->setValue(null, $previousOutput);
    fclose($stream);
  }

  expect(ConsoleWriteProbeStream::$received)->toBe($payload)
    ->and(ConsoleWriteProbeStream::$largestWrite)->toBeLessThanOrEqual(4096);
});

it('restores the terminal stream blocking mode after a controlled flush', function () {
  $stream = fopen('/dev/null', 'w');
  stream_set_blocking($stream, true);
  $outputProperty = new ReflectionProperty(Console::class, 'output');
  $previousOutput = $outputProperty->getValue();
  $outputProperty->setValue(null, new TestStreamConsoleOutput($stream));

  try {
    new ReflectionMethod(Console::class, 'writeToTerminal')->invoke(
      null,
      str_repeat('scene', 10_000),
    );
  } finally {
    $outputProperty->setValue(null, $previousOutput);
  }

  expect(stream_get_meta_data($stream)['blocked'])->toBeTrue();
  fclose($stream);
})->skipOnWindows();

it('delivers a payload that fits in the buffer', function () {
  $payload = str_repeat('x', 256);

  $received = writePayloadThroughConsole($payload);

  if ($received === null) {
    $this->markTestSkipped('proc_open is unavailable in this environment.');
  }

  expect($received)->toBe(strlen($payload));
})->skipOnWindows();

it('ignores empty payloads without touching the stream', function () {
  $received = writePayloadThroughConsole('');

  if ($received === null) {
    $this->markTestSkipped('proc_open is unavailable in this environment.');
  }

  expect($received)->toBe(0);
})->skipOnWindows();
