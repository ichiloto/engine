<?php

declare(strict_types=1);

use Ichiloto\Engine\Audio\AudioManager;
use Ichiloto\Engine\Audio\AudioMutePreflight;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Core\GameLaunchContext;
use Ichiloto\Engine\Core\GameStartLocation;
use Ichiloto\Engine\Core\GameStartupMode;
use Ichiloto\Engine\Diagnostics\LatencyTrace;
use Ichiloto\Engine\IO\LaunchDataRoot;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEvent;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlayerSettings;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Debug;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

// Adapts the historical Garden gated-driver.php without modifying its sealed evidence.
const FIELD_SCROLL_SILENT_AUDIO = ['music' => false, 'sfx' => false, 'voice' => false, 'master_volume' => 0];

function parseFieldScrollOptions(array $arguments): array
{
    $options = [];
    foreach ($arguments as $argument) {
        if (in_array($argument, ['--preflight', '--run', '--gate'], true)) {
            $key = substr($argument, 2);
            $value = true;
        } elseif (preg_match('/\A--(project|run-root|renderer|map|x|y)=(.+)\z/D', $argument, $match) === 1) {
            [, $key, $value] = $match;
        } else {
            throw new InvalidArgumentException('Unknown argument. Use --preflight or --run, --project=PATH, --run-root=PATH, --renderer=gpui|terminal, --map=ID, --x=N, --y=N and optional --gate.');
        }
        if (array_key_exists($key, $options)) { throw new InvalidArgumentException('Duplicate option: ' . $key); }
        $options[$key] = $value;
    }
    if (isset($options['preflight']) === isset($options['run'])) {
        throw new InvalidArgumentException('Choose exactly one of --preflight and --run; no launch is implicit.');
    }
    foreach (['project', 'run-root', 'renderer', 'map', 'x', 'y'] as $key) {
        if (!isset($options[$key])) { throw new InvalidArgumentException('Missing --' . $key); }
    }
    if (!in_array($options['renderer'], ['gpui', 'terminal'], true)) {
        throw new InvalidArgumentException('Renderer must be gpui or terminal.');
    }
    foreach (['x', 'y'] as $key) {
        $value = filter_var($options[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($value === false) { throw new InvalidArgumentException('Start cells must be nonnegative integers.'); }
        $options[$key] = $value;
    }
    return $options;
}

function writeFieldScrollJson(string $path, array $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($path, $json) !== strlen($json)) { throw new RuntimeException('Incomplete probe output: ' . $path); }
}

function createFieldScrollSilentAudio(Game $game): AudioManager
{
    // Same backend-free manager injection as FieldEncounterAcceptance.php, not an audioBackends option.
    return new class($game) extends AudioManager {
        public function __construct(Game $game) { parent::__construct($game); }
        protected function createBackends(): array { return []; }
    };
}

function assertFieldScrollSilent(AudioManager $audio): void
{
    $project = ConfigStore::get(ProjectConfig::class);
    if (!$project instanceof ProjectConfig) { throw new RuntimeException('Missing effective project configuration.'); }
    AudioMutePreflight::assertMuted($project);
    foreach ([ProjectConfig::class, PlaySettings::class] as $class) {
        $config = ConfigStore::get($class);
        foreach (FIELD_SCROLL_SILENT_AUDIO as $key => $value) {
            if ($config->get('audio.' . $key) !== $value) { throw new RuntimeException('Unsafe effective audio: ' . $key); }
        }
        if ($config->get('save.autosave') !== false) { throw new RuntimeException('Autosave must remain disabled.'); }
    }
    if (new ReflectionProperty(AudioManager::class, 'backends')->getValue($audio) !== []) {
        throw new RuntimeException('The timing probe must register zero audio backends.');
    }
}

function waitForFieldScrollLoopGate(Closure $pump, Closure $isReady, Closure $isReleased, Closure $flush): void
{
    $deadline = hrtime(true) + 10_000_000_000;
    do {
        $pump();
        $flush();
        if ($isReady() && $isReleased()) { return; }
        if (hrtime(true) >= $deadline) { throw new RuntimeException('Probe readiness/LoopGate exceeded 10 seconds.'); }
        usleep(10000);
    } while (true);
}

function collectFieldScrollCacheCounters(string $chunk, string &$pending, array &$counters): void
{
    $pending .= $chunk;
    while (($newline = strpos($pending, "\n")) !== false) {
        $line = substr($pending, 0, $newline);
        $pending = substr($pending, $newline + 1);
        $record = json_decode($line, true);
        if (!is_array($record)) { $counters['unparsed_lines']++; continue; }
        $diagnostic = $record['diagnostic'] ?? '';
        if (is_string($diagnostic) && str_starts_with($diagnostic, 'cannot retire display raster:')) {
            $counters['drop_image_errors']++;
        }
        if ($diagnostic === 'summary') { $counters['native_dropped_records'][] = $record['dropped_records'] ?? null; }
        if ($diagnostic !== 'geometry' || ($record['stage'] ?? '') !== 'glyph_reservation') { continue; }
        $values = array_column($record['values'] ?? [], 1, 0);
        if (!isset($values['live_before_bytes'], $values['live_after_bytes'], $values['candidate_bytes'],
            $values['scratch_bytes'], $values['new_images'], $values['live_images'])) {
            $counters['incomplete_reservations']++;
            continue;
        }
        $counters['reservation_observations']++;
        $counters['first_reservation'] ??= $values;
        $counters['last_reservation'] = $values;
        $counters['max_live_before_bytes'] = max($counters['max_live_before_bytes'] ?? 0, $values['live_before_bytes']);
        $counters['max_admission_bytes'] = max($counters['max_admission_bytes'] ?? 0,
            $values['live_after_bytes'] + $values['candidate_bytes'] + $values['scratch_bytes']);
    }
    if (strlen($pending) > 1024 * 1024) { throw new RuntimeException('Native diagnostic line exceeds the probe parser bound.'); }
}

final class FieldScrollTimingProbeGame extends Game
{
    private int $step = 0;
    private ?int $began = null;
    private ?int $finished = null;
    private string $completion = 'not_started';

    public function __construct(private readonly string $runRoot, private readonly string $mapId,
        GameLaunchContext $context, private readonly bool $gate, private readonly Closure $isReady,
        private readonly Closure $flushTrace)
    {
        if (AudioManager::getCurrentInstance() !== null) { throw new RuntimeException('The probe requires a fresh PHP process.'); }
        new ReflectionProperty(AudioManager::class, 'instance')->setValue(null, createFieldScrollSilentAudio($this));
        parent::__construct('Ichiloto - Field scroll timing (muted)', options: ['width' => 135, 'height' => 36,
            'fps' => 30, 'audio' => FIELD_SCROLL_SILENT_AUDIO, 'save' => ['autosave' => false]], launchContext: $context);
        // Process-local test control only; never persist this change to config.php.
        ConfigStore::get(ProjectConfig::class)->set('save.autosave', false);
        assertFieldScrollSilent($this->audioManager);
        if (!$this->hasIsolatedDataRoot() || $this->getDataRoot() !== $runRoot) {
            throw new RuntimeException('The game did not retain the isolated launch dataRoot.');
        }
    }

    protected function startInputSession(): void
    {
        assertFieldScrollSilent($this->audioManager);
        parent::startInputSession();
        if ([Console::getWidth(), Console::getHeight()] !== [135, 36]) {
            throw new RuntimeException('The owned Terminal/GPUI surface must support the prescribed 135x36 grid.');
        }
    }

    protected function start(): void
    {
        assertFieldScrollSilent($this->audioManager);
        parent::start();
        $scene = $this->getProbeScene();
        $scene->camera->detach();
        $this->render();
        LatencyTrace::record('probe.gate.begin');
        waitForFieldScrollLoopGate(function (): void {
            $runtime = $this->getRendererRuntime();
            $runtime?->pump();
            // Staged uploads and rejection recovery need normal presentation passes too.
            $runtime?->present($this->getProbeScene(), $this->notificationManager);
        }, $this->isReady,
            fn() => !$this->gate || is_file(LaunchDataRoot::validatePath($this->runRoot, $this->runRoot . '/logs/go')),
            $this->flushTrace);
        LatencyTrace::record('probe.gate.end');
        $this->began = LatencyTrace::getTimeNow();
        $this->completion = 'running';
        LatencyTrace::record('probe.start', ['map' => $this->mapId, 'seed' => 12345,
            'idle_steps' => 12, 'pan_steps' => 36, 'physical_input_tested' => false]);
    }

    // Camera scripting isolates rendering; it intentionally does not exercise physical input cadence.
    protected function handleInput(): void {}

    protected function update(): void
    {
        assertFieldScrollSilent($this->audioManager);
        if ($this->began === null) { throw new RuntimeException('Probe update preceded its readiness gate.'); }
        if ($this->step >= 48 || hrtime(true) - $this->began >= 30_000_000_000) {
            $this->finished = hrtime(true);
            $this->completion = $this->step === 48 ? 'complete' : 'deadline';
            LatencyTrace::record('probe.finish', ['steps' => $this->step, 'completion' => $this->completion]);
            $this->quit();
            return;
        }
        $phase = $this->step < 12 ? 'idle' : 'pan';
        LatencyTrace::record('probe.phase', ['phase' => $phase, 'step' => $this->step,
            'explicit_field_recomposition' => $phase === 'pan']);
        $started = LatencyTrace::getTimeNow();
        LatencyTrace::record('probe.update.begin', ['phase' => $phase]);
        parent::update();
        LatencyTrace::end('probe.update.end', $started, ['phase' => $phase]);
        if ($this->hasStopped()) { return; }
        if ($phase === 'pan') {
            $scene = $this->getProbeScene();
            $position = $this->step - 12;
            $scene->camera->moveTo($position, $position);
            $started = LatencyTrace::getTimeNow();
            LatencyTrace::record('probe.field.recompose.begin', ['phase' => $phase]);
            $scene->fieldState->renderTheField();
            LatencyTrace::end('probe.field.recompose', $started, ['phase' => $phase,
                'requested_x' => $position, 'requested_y' => $position,
                'camera_x' => $scene->camera->position->x, 'camera_y' => $scene->camera->position->y]);
        }
        $this->step++;
    }

    protected function render(): void
    {
        parent::render();
        // Disk output stays outside the measured composition/presentation stage pairs.
        ($this->flushTrace)();
    }

    private function getProbeScene(): GameScene
    {
        $scene = $this->sceneManager->currentScene;
        if (!$scene instanceof GameScene || $scene->currentMapId !== $this->mapId) {
            throw new RuntimeException('The timing workload left its requested field scene.');
        }
        return $scene;
    }

    public function getProbeResult(): array
    {
        return ['steps' => $this->step, 'completion' => $this->completion === 'running' ? 'interrupted' : $this->completion,
            'elapsed_ns' => $this->began === null ? null : ($this->finished ?? hrtime(true)) - $this->began];
    }
}

try {
    $options = parseFieldScrollOptions(array_slice($argv, 1));
    $project = $options['project'];
    $root = $options['run-root'];
    $scratch = realpath('/Volumes/Macintosh HD 2/IchilotoBuildCaches/test-tmp');
    if (realpath($project) !== $project || !is_file($project . '/vendor/autoload.php') || !is_dir($project . '/assets')
        || $scratch === false || !str_starts_with($root, $scratch . DIRECTORY_SEPARATOR) || realpath($root) !== $root) {
        throw new InvalidArgumentException('Use a real project root and an existing canonical run root under secondary test-tmp.');
    }
    require $project . '/vendor/autoload.php';
    LaunchDataRoot::validate($root, $project);
    foreach (['stdout', 'stderr'] as $name) {
        $path = LaunchDataRoot::validatePath($root, $root . '/logs/' . $name . '.log');
        $expected = @stat($path);
        $actual = fstat($name === 'stdout' ? STDOUT : STDERR);
        if ($expected === false || $actual === false || $expected['dev'] !== $actual['dev'] || $expected['ino'] !== $actual['ino']) {
            throw new RuntimeException('Redirect ' . $name . ' to ' . $path . ' before invoking the probe.');
        }
    }
    foreach ([GameLaunchContext::ENVIRONMENT_VARIABLE, GameStartupMode::ENVIRONMENT_VARIABLE] as $name) {
        if (getenv($name) !== false) { throw new RuntimeException('Remove competing launch environment: ' . $name); }
    }
    chdir($project);
    ini_set('error_log', $root . '/logs/php-errors.log');
    Debug::configure(['log_directory' => $root . '/logs']);
    LatencyTrace::configure(); // Never enable the default project/logs trace sink.
    $settingsPath = $root . '/player-settings.json';
    if (!is_file($settingsPath)) { writeFieldScrollJson($settingsPath, ['audio' => FIELD_SCROLL_SILENT_AUDIO]); }
    ConfigStore::put(PlayerSettings::class, new PlayerSettings($project, $root));
    $effective = new ProjectConfig();
    $effective->set('save.autosave', false);
    ConfigStore::put(ProjectConfig::class, $effective);
    ConfigStore::put(PlaySettings::class, new PlaySettings(['audio' => FIELD_SCROLL_SILENT_AUDIO, 'save' => ['autosave' => false]]));
    $context = new GameLaunchContext(GameStartupMode::NEW_GAME, $root,
        new GameStartLocation($options['map'], $options['x'], $options['y']));
    $context->validateOptions([], null);
    $schema = ['schema' => 'ichiloto.field-scroll-timing/1', 'mode' => isset($options['run']) ? 'run' : 'preflight',
        'project' => $project, 'launch_context' => ['mode' => 'new-game', 'dataRoot' => $root,
            'start' => ['mapId' => $context->start->mapId, 'x' => $context->start->x, 'y' => $context->start->y]],
        'renderer' => $options['renderer'], 'seed' => 12345, 'grid' => [135, 36, 10, 20], 'fps' => 30,
        'idle_steps' => 12, 'camera_positions' => array_map(static fn($n) => [$n, $n], range(0, 35)),
        'workload_deadline_seconds' => 30, 'gate_deadline_seconds' => 10, 'gate' => isset($options['gate']),
        'output_directory' => $root . '/logs', 'paths' => ['stdout' => $root . '/logs/stdout.log',
            'stderr' => $root . '/logs/stderr.log', 'php_errors' => $root . '/logs/php-errors.log',
            'trace' => $root . '/logs/engine.ndjson', 'gate' => $root . '/logs/go',
            'result' => $root . '/logs/probe-result.json', 'profile_directory' => $root . '/logs'],
        'boundary' => 'CPU observations, not GPU completion or visible FPS; camera scripting does not test physical input. Idle performs ordinary updates, not explicit field recomposition. Terminal stdout is captured to a file, not measured as emulator presentation. The deadline is checked between updates, not an OS process-termination watchdog.'];

    if (isset($options['preflight'])) {
        $owner = new class extends Game { public function __construct() {} public function __destruct() {} };
        $audio = createFieldScrollSilentAudio($owner);
        assertFieldScrollSilent($audio);
        foreach ([Game::class => ['getDataRoot', 'hasIsolatedDataRoot', 'getRendererRuntime'],
            Console::class => ['getWidth', 'getHeight'],
            LatencyTrace::class => ['getTimeNow', 'configure', 'record', 'end'],
            GameScene::class => ['configure']] as $class => $methods) {
            foreach ($methods as $method) {
                if (!method_exists($class, $method)) { throw new RuntimeException('Missing probe contract: ' . $class . '::' . $method); }
            }
        }
        require_once dirname(__DIR__, 2) . '/Support/Input/FakeRendererTransport.php';
        $records = [];
        LatencyTrace::configure(static function (array $record) use (&$records): void { $records[] = $record; });
        $transport = new Tests\Support\Input\FakeRendererTransport();
        $runtime = new RendererRuntime(new RendererRuntimeConfig(new RendererProcessConfig(['cpu-only-fixture']), $project . '/assets'), $transport);
        $runtime->start('CPU-only feedback contract', 135, 36);
        $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"frame_ack","generation":1,"frame":0,"presented":true}')];
        $initialPolls = $transport->polls;
        waitForFieldScrollLoopGate($runtime->pump(...), static function () use (&$records): bool {
            return in_array('presentation.frame.acknowledged', array_column($records, 'stage'), true);
        },
            static fn() => $transport->polls >= $initialPolls + 2, static function (): void {});
        $transport->batches[] = [RendererEvent::fromJson('{"protocol":2,"type":"frame_rejected","generation":1,"expectedGeneration":1,"message":"CPU-only preflight rejection","resyncRequired":true}')];
        $runtime->pump();
        $stages = array_column($records, 'stage');
        if ($transport->polls <= $initialPolls || !in_array('presentation.frame.acknowledged', $stages, true)
            || !in_array('presentation.frame.rejected', $stages, true)) {
            throw new RuntimeException('LoopGate/validated feedback trace contract did not pass.');
        }
        $feedback = array_values(array_filter($records, static fn($r) => $r['stage'] === 'presentation.frame.rejected'));
        $ack = array_values(array_filter($records, static fn($r) => $r['stage'] === 'presentation.frame.acknowledged'));
        if (count($feedback) !== 1 || $feedback[0]['expected_generation'] !== 1 || $feedback[0]['resync_required'] !== true
            || count($ack) !== 1 || $ack[0]['frame'] !== 0 || $ack[0]['presented'] !== true) {
            throw new RuntimeException('Validated feedback fields changed.');
        }
        $parser = ['reservation_observations' => 0, 'first_reservation' => null, 'last_reservation' => null,
            'max_live_before_bytes' => null, 'max_admission_bytes' => null, 'incomplete_reservations' => 0,
            'drop_image_errors' => 0, 'unparsed_lines' => 0, 'native_dropped_records' => []];
        $partial = '';
        $line = json_encode(['diagnostic' => 'geometry', 'stage' => 'glyph_reservation', 'values' => [
            ['live_before_bytes', 64], ['live_after_bytes', 32], ['candidate_bytes', 10], ['scratch_bytes', 40],
            ['new_images', 1], ['live_images', 1]]], JSON_THROW_ON_ERROR) . "\n";
        collectFieldScrollCacheCounters(substr($line, 0, 17), $partial, $parser);
        collectFieldScrollCacheCounters(substr($line, 17), $partial, $parser);
        if ($partial !== '' || $parser['reservation_observations'] !== 1 || $parser['max_admission_bytes'] !== 82) {
            throw new RuntimeException('Fragmented native cache-counter parsing changed.');
        }
        $runtime->shutdown();
        $audio->shutdown();
        LatencyTrace::configure();
        $report = [...$schema, 'contracts_passed' => true, 'native_started' => false,
            'field_composed' => false, 'registered_audio_backends' => 0,
            'synthetic_feedback_and_cache_parser_passed' => true,
            'launch_cell_validation' => 'Deferred to GameLaunchPreflight before native input/output; no Game constructor or normal save was used.'];
        writeFieldScrollJson($root . '/logs/preflight.json', $report);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
        exit(0);
    }

    putenv('ICHILOTO_RENDERER=' . $options['renderer']);
    putenv('ICHILOTO_GPUI_TRACE=1');
    $buffer = [];
    $bufferBytes = $dropped = 0;
    $counters = ['drained_acknowledgements' => 0, 'presented_acknowledgements' => 0, 'rejections' => 0, 'resync_requested' => 0];
    $nativePending = '';
    $cacheCounters = ['reservation_observations' => 0, 'first_reservation' => null, 'last_reservation' => null,
        'max_live_before_bytes' => null, 'max_admission_bytes' => null, 'incomplete_reservations' => 0,
        'drop_image_errors' => 0, 'unparsed_lines' => 0, 'native_dropped_records' => []];
    $tracePath = $root . '/logs/engine.ndjson';
    $stream = fopen($tracePath, 'xb');
    if ($stream === false) { throw new RuntimeException('Use a fresh run root; the timing trace must not be overwritten.'); }
    $flush = static function () use (&$buffer, &$bufferBytes, &$dropped, $stream): void {
        if ($dropped !== 0) { $buffer[] = json_encode(['stage' => 'trace.dropped', 'pid' => getmypid(), 'count' => $dropped]) . "\n"; }
        $bytes = implode('', $buffer);
        while ($bytes !== '') {
            $written = fwrite($stream, $bytes);
            if ($written === false || $written === 0) { throw new RuntimeException('The isolated timing trace could not drain.'); }
            $bytes = substr($bytes, $written);
        }
        fflush($stream);
        $buffer = [];
        $bufferBytes = $dropped = 0;
    };
    LatencyTrace::configure(static function (array $record) use (&$buffer, &$bufferBytes, &$dropped, &$counters,
        &$nativePending, &$cacheCounters): void {
        if ($record['stage'] === 'presentation.frame.acknowledged') {
            $counters['drained_acknowledgements']++;
            if ($record['presented']) { $counters['presented_acknowledgements']++; }
        } elseif ($record['stage'] === 'presentation.frame.rejected') {
            $counters['rejections']++;
            if ($record['resync_required']) { $counters['resync_requested']++; }
        } elseif ($record['stage'] === 'renderer.stderr') {
            collectFieldScrollCacheCounters($record['text'], $nativePending, $cacheCounters);
        }
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (count($buffer) >= 4096 || $bufferBytes + strlen($line) > 4 * 1024 * 1024) { $dropped++; return; }
        $buffer[] = $line;
        $bufferBytes += strlen($line);
    });
    $game = null;
    register_shutdown_function(static function () use (&$game, &$counters, &$nativePending, &$cacheCounters,
        $schema, $root, $flush, $stream): void {
        $flush();
        fclose($stream);
        writeFieldScrollJson($root . '/logs/probe-result.json', [...$schema, 'workload' => $game?->getProbeResult(),
            'feedback_counters' => $counters, 'feedback_scope' => 'Whole session including warmup; drained/coalesced feedback, not raw wire event counts.',
            'cache_counters' => [...$cacheCounters, 'trailing_stderr_bytes' => strlen($nativePending)],
            'cache_scope' => 'Whole-session CPU raster accounting, not GPU allocation/retirement. Missing reservation observations mean unavailable, not zero bytes; zero drop_image errors does not prove GPU success.',
            'last_php_error' => error_get_last()]);
    });
    mt_srand(12345);
    $game = new FieldScrollTimingProbeGame($root, $options['map'], $context, isset($options['gate']),
        static function () use ($options, &$counters): bool {
            return $options['renderer'] === 'terminal' || $counters['presented_acknowledgements'] > 0;
        }, $flush);
    $game->run();
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
