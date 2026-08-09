<?php

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Field\RegionMap;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Scenes\Interfaces\SceneInterface;
use Ichiloto\Engine\UI\UIManager;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

// pest()->extend(Tests\TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Shared State
|--------------------------------------------------------------------------
|
| A few engine services cache answers in static properties for the life of
| the process: terminal capability detection, and the per-symbol metric
| caches behind text measurement. Left alone they leak between tests, so a
| test that configures a stub can change the result of one that runs later
| and the suite fails depending on order. Clear them before every test.
|
*/

uses()->beforeEach(function () {
    Ichiloto\Engine\IO\Console\TerminalCapabilities::reset();
})->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Creates a lightweight scene stub for camera-oriented unit tests.
 *
 * @return SceneInterface
 */
function makeCameraTestScene(): SceneInterface
{
    $uiManager = (new ReflectionClass(UIManager::class))->newInstanceWithoutConstructor();

    return new class($uiManager) implements SceneInterface {
        public Camera $camera;
        public string $name = 'Test';

        public function __construct(private UIManager $uiManager)
        {
        }

        public function start(): void
        {
        }

        public function stop(): void
        {
        }

        public function resume(): void
        {
        }

        public function suspend(): void
        {
        }

        public function render(): void
        {
        }

        public function erase(): void
        {
        }

        public function update(): void
        {
        }

        public function getBackgroundMusic(): ?string
        {
            return null;
        }

        public function getGame(): Game
        {
            throw new RuntimeException('Not required for camera unit tests.');
        }

        public function getRootGameObjects(): array
        {
            return [];
        }

        public function getUI(): UIManager
        {
            return $this->uiManager;
        }

        public function isStarted(): bool
        {
            return true;
        }

        public function renderBackgroundTile(int $x, int $y): void
        {
        }
    };
}

/**
 * Writes a throwaway project's maps and points the region map at them.
 *
 * Doors are given a position on a 20x10 event layer, which is where the
 * direction of the place behind them comes from.
 *
 * @param array<string, array{name: string, region: string, to?: array<string, array{0: int, 1: int}>}> $maps The maps, keyed by id.
 * @return string The maps directory.
 */
function writeTestMaps(array $maps): string
{
  $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('ichiloto-region-', true);

  foreach ($maps as $id => $map) {
    $directory = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $id);
    $leaf = basename($id);

    if (! is_dir($directory)) {
      mkdir($directory, 0777, true);
    }

    $events = '';
    $layer = array_fill(0, 10, array_fill(0, 20, ' '));

    foreach (array_values($map['to'] ?? []) as $index => $position) {
      $destination = array_keys($map['to'])[$index];
      $marker = chr(65 + $index);
      $layer[$position[1]][$position[0]] = $marker;

      $events .= <<<PHP
        '{$marker}' => [
          'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
          'data' => ['destinationMap' => '{$destination}'],
        ],

      PHP;
    }

    file_put_contents($directory . DIRECTORY_SEPARATOR . "{$leaf}.data.php", <<<PHP
    <?php

    return [
      'name' => '{$map['name']}',
      'region' => '{$map['region']}',
      'events' => [
    {$events}  ],
    ];
    PHP);

    $rows = implode("\n", array_map(static fn(array $row): string => implode('', $row), $layer));

    file_put_contents($directory . DIRECTORY_SEPARATOR . "{$leaf}.event.php", <<<PHP
    <?php

    return <<<'ICHILOTO_EVENT_MAP'
    {$rows}
    ICHILOTO_EVENT_MAP;

    PHP);
  }

  RegionMap::loadFrom($root);

  return $root;
}
