<?php

use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\Actions\AttackAction;
use Ichiloto\Engine\Battle\Presentation\BattleAnnouncement;
use Ichiloto\Engine\Localization\EngineMessageTerms;
use Ichiloto\Engine\Localization\MessageCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;
use Ichiloto\Engine\Util\Config\ProjectConfig;

beforeEach(function () {
  $this->priorMessageConfig = ConfigStore::has(ProjectConfig::class) ? ConfigStore::get(ProjectConfig::class) : null;
  ConfigStore::remove(ProjectConfig::class);
});

afterEach(function () {
  $this->priorMessageConfig === null ? ConfigStore::remove(ProjectConfig::class)
    : ConfigStore::put(ProjectConfig::class, $this->priorMessageConfig);
});

it('exposes defaults, accepted tokens and help for authoring every registered Engine message', function () {
  $terms = EngineMessageTerms::listTerms();
  expect($terms)->not->toBeEmpty();
  foreach ($terms as $path => $term) {
    expect($path)->toMatch('/^[a-z_]+(?:\.[a-z_]+)*$/D')
      ->and($term['default'])->toBeString()
      ->and($term['description'])->not->toBeEmpty()
      ->and(EngineMessageTerms::getDefault($path))->toBe($term['default'])
      ->and(MessageCatalog::resolveTerm($path))->toBe($term['default']);
    preg_match_all('/[%$][1-9][0-9]*/', $term['default'], $matches);
    foreach ($matches[0] as $token) { expect($term['placeholders'])->toHaveKey($token); }
    foreach ($term['placeholders'] as $token => $meaning) {
      expect($token)->toMatch('/^[%$][1-9][0-9]*$/D')->and($meaning)->not->toBeEmpty();
    }
  }
  foreach (BattleCommandType::cases() as $type) {
    expect($terms)->toHaveKey('battle.announcements.' . $type->value);
  }
});

it('uses project and locale overrides with the same catalog defaults used by editors', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['locale' => 'fr', 'messages' => [
    'obtained_gold' => 'Base %1 %2', 'save' => ['write_failed' => 'Try saving again.'],
    'fr' => ['obtained_gold' => 'Reçu %1 %2'],
  ]]));
  expect(get_engine_message('obtained_gold', 12, 'G'))->toBe('Reçu 12 G')
    ->and(MessageCatalog::getTerm('save.write_failed'))->toBe('Try saving again.')
    ->and(MessageCatalog::resolveTerm('prompt.load'))->toBe(EngineMessageTerms::getDefault('prompt.load'))
    ->and(EngineMessageTerms::listTerms()['obtained_gold']['default'])->toBe('%1 %2 found!');
});

it('preserves legacy vocabulary defaults and explicit message precedence', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['locale' => 'fr', 'vocab' => [
    'shop' => ['exp_total' => 'Owned %1', 'possession' => 'Owned'],
    'fr' => ['shop' => ['exp_total' => 'Actuel %1']],
  ], 'messages' => ['inventory' => ['possession' => 'In your bag']]]));
  expect(EngineMessageTerms::listTerms()['exp_total']['default'])->toBe('Actuel %1')
    ->and(get_engine_message('exp_total', 'EXP'))->toBe('Actuel EXP')
    ->and(EngineMessageTerms::listTerms()['inventory.possession']['default'])->toBe('Owned')
    ->and(get_engine_message('inventory.possession'))->toBe('In your bag');
});

it('preserves blank callouts and generic localized fallback behavior', function () {
  ConfigStore::put(ProjectConfig::class, new PlaySettings(['locale' => 'fr', 'messages' => [
    'prompt' => ['load' => 'Choose one.'],
    'battle' => ['announcements' => ['attack' => '%1 uses %2']],
    'fr' => ['prompt' => ['load' => ''], 'battle' => ['announcements' => ['attack' => '']]],
  ]]));
  expect(MessageCatalog::resolveTerm('prompt.load'))->toBe('Choose one.')
    ->and(MessageCatalog::resolveTerm('battle.announcements.attack', allowEmpty: true))->toBe('')
    ->and(BattleAnnouncement::formatForAction(new AttackAction('Cut'), 'Hero', 'Cut'))->toBe('');
});

it('refuses unknown Engine terms while retaining custom project message lookup', function () {
  expect(fn() => MessageCatalog::getTerm('project.custom'))->toThrow(InvalidArgumentException::class)
    ->and(get_message('project.custom', 'A custom %1', 'message'))->toBe('A custom message');
});
