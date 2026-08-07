<?php

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Util\Config\AbstractConfig;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;

function registerVocabConfig(array $vocab): void
{
  $stub = new class($vocab) extends AbstractConfig {
    public function __construct(protected array $vocabConfig = [])
    {
      parent::__construct();
    }

    protected function load(): array
    {
      return ['vocab' => $this->vocabConfig];
    }

    public function persist(): void
    {
    }
  };

  ConfigStore::put(ProjectConfig::class, $stub);
}

afterEach(function () {
  ConfigStore::remove(ProjectConfig::class);
});

it('uses the default summon label without configuration', function () {
  expect(BattleCommandType::SUMMON->labelForRole('Oracle'))->toBe('Summon');
});

it('renames the summon command per role from project config', function () {
  registerVocabConfig([
    'command' => [
      'summon_by_role' => [
        'Oracle' => 'Petition',
        'Vanguard' => 'Request',
      ],
    ],
  ]);

  expect(BattleCommandType::SUMMON->labelForRole('Oracle'))->toBe('Petition')
    ->and(BattleCommandType::SUMMON->labelForRole('Vanguard'))->toBe('Request')
    ->and(BattleCommandType::SUMMON->labelForRole('Hero'))->toBe('Summon');
});

it('resolves role-specific labels back to the summon command', function () {
  registerVocabConfig([
    'command' => [
      'summon_by_role' => [
        'Oracle' => 'Petition',
      ],
    ],
  ]);

  expect(BattleCommandType::fromCommandName('Petition'))->toBe(BattleCommandType::SUMMON)
    ->and(BattleCommandType::fromCommandName('petition'))->toBe(BattleCommandType::SUMMON)
    ->and(BattleCommandType::fromCommandName('Summon'))->toBe(BattleCommandType::SUMMON);
});

it('renames the summon command game-wide from project config', function () {
  registerVocabConfig([
    'command' => [
      'summon' => 'Eidolon',
    ],
  ]);

  expect(BattleCommandType::SUMMON->label())->toBe('Eidolon')
    ->and(BattleCommandType::SUMMON->labelForRole('Anyone'))->toBe('Eidolon')
    ->and(BattleCommandType::fromCommandName('Eidolon'))->toBe(BattleCommandType::SUMMON);
});
