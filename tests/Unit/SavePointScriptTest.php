<?php

declare(strict_types=1);

use Ichiloto\Engine\Events\Interpreter\EventInterpreter;
use Ichiloto\Engine\IO\Saves\SaveFeatures;
use Ichiloto\Engine\Localization\EngineMessageTerms;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

/** A project whose config.php holds these values. */
function useSavePointProject(array $data): void
{
  ConfigStore::put(ProjectConfig::class, new class($data) extends ProjectConfig {
    public function __construct(private readonly array $authored) { parent::__construct(); }
    protected function load(): array { return $this->authored; }
  });
}

/** Records the scripts a scene would run, without running them. */
final class SavePointScriptRecorder extends EventInterpreter
{
  public array $runs = [];
  public function __construct() {}
  public function run(array $commands, ?string $scriptId = null, mixed $completionTarget = null, array $origin = []): null
  {
    $this->runs[] = [$commands, $scriptId];
    return null;
  }
}

beforeEach(function () {
  $this->previousConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
});

afterEach(function () {
  if ($this->previousConfig === null) { ConfigStore::remove(ProjectConfig::class); }
  else { ConfigStore::put(ProjectConfig::class, $this->previousConfig); }
});

it('reads a project save point script, and none when the project names none', function () {
  useSavePointProject([]);
  expect(SaveFeatures::getSavePointScript())->toBeNull();

  useSavePointProject(['save' => ['point_script' => '']]);
  expect(SaveFeatures::getSavePointScript())->toBeNull();

  useSavePointProject(['save' => ['point_script' => 'guild-ledger']]);
  expect(SaveFeatures::getSavePointScript())->toBe('guild-ledger');
});

it('refuses a save point script that is not a stable script id', function (mixed $value) {
  useSavePointProject(['save' => ['point_script' => $value]]);

  expect(fn() => SaveFeatures::getSavePointScript())
    ->toThrow(InvalidArgumentException::class, 'save.point_script must name an event script by its stable id');
})->with(['a path' => ['../secrets'], 'a list' => [['guild-ledger']], 'a number' => [7]]);

it('runs the project script on arriving at a save point', function () {
  useSavePointProject(['save' => ['point_script' => 'guild-ledger']]);
  $scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
  $interpreter = new SavePointScriptRecorder();
  new ReflectionProperty(GameScene::class, 'eventInterpreter')->setValue($scene, $interpreter);

  $scene->arriveAtSavePoint();

  expect($interpreter->runs)->toBe([[[['type' => 'common_event', 'id' => 'guild-ledger']], 'save-point:guild-ledger']]);
});

it('keeps the Engine save point notice as a rewordable default', function () {
  $terms = EngineMessageTerms::listTerms();

  expect($terms['save.point_prompt']['default'])->toBe('Access the Menu to save your progress.')
    ->and($terms['save.point_title']['default'])->toBe('Save Point');
});
