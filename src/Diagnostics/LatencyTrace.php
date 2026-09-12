<?php

namespace Ichiloto\Engine\Diagnostics;

use Closure;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use WeakMap;

/** @internal Opt-in observations only; never part of the renderer wire or gameplay state. */
final class LatencyTrace
{
  private static bool $initialized = false;
  private static ?Closure $sink = null;
  private static ?Closure $clock = null;
  /** @var WeakMap<RendererEvent, array{id: int, key: string, received_ns: int}>|null */
  private static ?WeakMap $keys = null;
  /** @var array{id: int, key: string, received_ns: int}|null */
  private static ?array $input = null;
  private static int $nextKey = 0;
  private static int $iteration = 0;
  private static int $consumed = 0;
  /** @var list<array<string, mixed>> */
  private static array $records = [];
  private static int $dropped = 0;
  /** @var resource|null */
  private static $stream = null;

  /** Inject a sink/clock for deterministic tests; null disables tracing. */
  public static function configure(?Closure $sink = null, ?Closure $clock = null): void
  {
    self::flush();
    if (is_resource(self::$stream)) { fclose(self::$stream); }
    self::$stream = null;
    self::$initialized = true;
    self::$sink = $sink;
    self::$clock = $clock;
    self::$keys = null;
    self::$input = null;
    self::$nextKey = self::$iteration = self::$consumed = self::$dropped = 0;
  }

  public static function enabled(): bool
  {
    if (!self::$initialized) {
      self::$initialized = true;
      if (getenv('ICHILOTO_ENGINE_TRACE') === '1') {
        $directory = getcwd() . '/logs';
        if (!is_dir($directory)) { @mkdir($directory, 0777, true); }
        self::$stream = @fopen($directory . '/latency.ndjson', 'ab');
        if (is_resource(self::$stream)) {
          self::$sink = static function (array $record): void {
            if (count(self::$records) < 4096) { self::$records[] = $record; }
            else { self::$dropped++; }
          };
          register_shutdown_function(self::flush(...));
        }
      }
    }
    return self::$sink !== null;
  }

  public static function now(): ?int
  {
    return self::enabled() ? (self::$clock !== null ? (self::$clock)() : hrtime(true)) : null;
  }

  /** @param array<string, mixed> $data */
  public static function record(string $stage, array $data = []): void
  {
    if (($now = self::now()) !== null) {
      (self::$sink)([
        'stage' => $stage, 'at_ns' => $now, 'pid' => getmypid(),
        'iteration' => self::$iteration, 'input_id' => self::$input['id'] ?? null,
        ...$data,
      ]);
    }
  }

  /** @param array<string, mixed> $data */
  public static function end(string $stage, ?int $start, array $data = []): void
  {
    if ($start !== null) { self::record($stage, ['duration_ns' => self::now() - $start, ...$data]); }
  }

  public static function beginIteration(): void
  {
    if (!self::enabled()) { return; }
    self::$iteration++;
    self::$consumed = 0;
    self::$input = null;
    self::record('game.iteration.begin');
  }

  public static function endIteration(): void
  {
    self::record('game.iteration.end', ['keys_consumed' => self::$consumed]);
    self::flush();
  }

  public static function beginPoll(): void
  {
    self::$input = null;
  }

  public static function keyStage(RendererEvent $event, string $stage, bool $activate = false): void
  {
    if (!self::enabled() || $event->key === null) { return; }
    self::$keys ??= new WeakMap();
    $key = self::$keys[$event] ??= ['id' => ++self::$nextKey, 'key' => $event->key, 'received_ns' => self::now()];
    if ($activate) { self::$input = $key; }
    self::record($stage, ['input_id' => $key['id'], 'key' => $key['key'],
      'observed_age_ns' => self::now() - $key['received_ns']]);
  }

  public static function queue(int $count, ?RendererEvent $oldest): void
  {
    if (!self::enabled()) { return; }
    $received = $oldest === null ? null : (self::$keys[$oldest]['received_ns'] ?? null);
    self::record('input.queue', ['queued_keys' => $count,
      'oldest_age_ns' => $received === null ? null : self::now() - $received]);
  }

  public static function accepted(string $key, string $source): void
  {
    if (!self::enabled()) { return; }
    self::$input ??= ['id' => ++self::$nextKey, 'key' => $key, 'received_ns' => self::now()];
    self::$consumed++;
    self::record('input.accepted', ['key' => $key, 'source' => $source]);
  }

  public static function returned(string $key, string $source, ?int $started = null): void
  {
    if (!self::enabled()) { return; }
    self::$input ??= ['id' => ++self::$nextKey, 'key' => $key, 'received_ns' => self::now()];
    self::record('input.source.returned', ['key' => $key, 'source' => $source,
      'poll_ns' => $started === null ? null : self::now() - $started]);
  }

  /** Flush outside measured frame work; bounded records prevent diagnostic backlogs. */
  public static function flush(): void
  {
    if (!is_resource(self::$stream)) { return; }
    if (self::$dropped > 0) {
      self::$records[] = ['stage' => 'trace.dropped', 'count' => self::$dropped];
      self::$dropped = 0;
    }
    $lines = '';
    foreach (self::$records as $record) { $lines .= json_encode($record, JSON_INVALID_UTF8_SUBSTITUTE) . "\n"; }
    self::$records = [];
    while ($lines !== '') {
      $written = @fwrite(self::$stream, $lines);
      if ($written === false || $written === 0) { self::$sink = null; break; }
      $lines = substr($lines, $written);
    }
  }
}
