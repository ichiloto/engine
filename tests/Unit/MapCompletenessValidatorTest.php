<?php

use Ichiloto\Engine\Events\Enumerations\LootType;
use Ichiloto\Engine\Events\Triggers\ChestEventTrigger;
use Ichiloto\Engine\Exceptions\NotFoundException;
use Ichiloto\Engine\Field\MapGraphics;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\Field\MapTileLayer;
use Ichiloto\Engine\Field\Validation\MapCompletenessValidator;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use Ichiloto\Engine\Util\Debug;
use function Tests\Support\Rendering\writeTestPng;
use function Tests\Support\Rendering\writeCharacterSheetPng;

require_once __DIR__ . '/../Support/Rendering/GraphicalSpriteFixtures.php';

beforeEach(function () {
    $this->oldDebug = new ReflectionClass(Debug::class)->getStaticProperties();
    $this->root = createTestDirectory('ichiloto-completeness-');
    Debug::configure(['log_directory' => $this->root . '/logs']);
});
afterEach(function () {
    foreach ($this->oldDebug as $name => $value) { new ReflectionProperty(Debug::class, $name)->setValue(null, $value); }
});

it('uses one strict chest rule for drafts and runtime catalogs without granting or completing anything', function (array $data, bool $valid) {
    $calls = [];
    $resolve = function (string $reference) use (&$calls): ?string {
        $calls[] = $reference;
        if ($reference === 'ambiguous') { throw new RuntimeException('Ambiguous reference'); }
        if ($reference === 'runtime-missing') { throw new NotFoundException('Inventory reference'); }
        return in_array($reference, ['item.test', 'Current name', 'old alias'], true) ? 'item.test' : null;
    };
    $before = $data;
    $issues = MapCompletenessValidator::validateChestReward($data, 'test/map', $resolve, 'C', ['events', 'C', 'data']);
    expect($data)->toBe($before)->and($issues === [])->toBe($valid);
    foreach ($issues as $issue) {
        expect($issue->rule)->toBe('chest.reward')->and($issue->mapId)->toBe('test/map')->and($issue->marker)->toBe('C')
            ->and(array_slice($issue->path, 0, 3))->toBe(['events', 'C', 'data']);
    }
})->with([
    'stable id' => [['loot' => 'item.test'], true],
    'display name' => [['loot' => 'Current name', 'quantity' => 2], true],
    'alias' => [['lootType' => LootType::WEAPON, 'loot' => 'old alias'], true],
    'empty' => [['loot' => ''], false],
    'missing' => [['loot' => 'absent'], false],
    'runtime missing exception' => [['loot' => 'runtime-missing'], false],
    'ambiguous' => [['loot' => 'ambiguous'], false],
    'unsupported' => [['lootType' => LootType::SKILL, 'loot' => 'item.test'], false],
    'unknown' => [['lootType' => 'ITEM', 'loot' => 'item.test'], false],
    'zero items' => [['loot' => 'item.test', 'quantity' => 0], false],
    'fractional quantity' => [['loot' => 'item.test', 'quantity' => 1.5], false],
    'whole numeric string' => [['loot' => 'item.test', 'quantity' => '2'], true],
    'gold amount' => [['lootType' => LootType::GOLD, 'loot' => 20], true],
    'gold quantity' => [['lootType' => 'gold', 'loot' => null, 'quantity' => 10], true],
    'blank gold quantity' => [['lootType' => 'gold', 'loot' => '', 'quantity' => 10], true],
    'named gold' => [['lootType' => 'gold', 'loot' => 'treasure'], false],
    'zero gold' => [['lootType' => 'gold', 'loot' => 0], false],
    'negative gold' => [['lootType' => 'gold', 'loot' => -10], false],
    'fractional gold' => [['lootType' => 'gold', 'loot' => 1.9], false],
]);

it('reports all current event and chest defects together while terminal-only sources stay valid', function () {
    $layers = new MapLayerSet([new MapLayer('ground', 0, false, 'synthetic', '  ')]);
    $data = ['events' => ['C' => ['class' => ChestEventTrigger::class, 'data' => ['loot' => '']],
        'F' => ['class' => ChestEventTrigger::class, 'subject' => ['kind' => 'world_object', 'id' => 'absent'], 'data' => ['lootType' => 'gold', 'loot' => 0]]]];
    $issues = MapCompletenessValidator::validate('test/map', $data, [['C', ' ']], $layers, null, $this->root, fn() => null);
    expect(array_column($issues, 'rule'))->toContain('event.subject', 'chest.reward')->and($issues)->toHaveCount(3);
    expect(MapCompletenessValidator::validate('test/map', [], [[' ', ' ']], $layers, null, $this->root, fn() => null))->toBe([]);
    $required = MapCompletenessValidator::validate('test/map', [], [[' ', ' ']], $layers, null, $this->root, fn() => null, requiresGraphics: true);
    expect(array_column($required, 'rule'))->toBe(['graphics.missing']);
});

it('uses current tile and object assets for glyph coverage and identifies exact cells', function () {
    writeTestPng($this->root . '/Graphics/B.png', 32, 32);
    $tileset = new Tileset('test', 'Test', ['B' => 'Graphics/B.png']);
    $layers = new MapLayerSet([new MapLayer('fixtures', 0, false, 'synthetic', 'ix')]);
    $graphics = new MapGraphics($tileset, [new MapTileLayer('fixtures', 0, 'synthetic', '1 0')], owners: ['fixtures' => 'fixtures']);
    $issues = MapCompletenessValidator::validateCoverage('test/map', [], $layers, $graphics, $this->root);
    expect(array_column($issues, 'rule'))->toBe(['graphics.glyph'])->and($issues[0]->cells)->toBe([[1, 0]]);
    $data = ['worldObjects' => [[
        'id' => 'fixture', 'anchor' => ['x' => 1, 'y' => 0], 'pivot' => ['x' => .5, 'y' => 1.],
        'sprites2d' => ['asset' => 'Graphics/object.png'],
        'covers' => ['layer' => 'fixtures', 'cells' => [[1, 0]], 'tileLayers' => []],
    ]]];
    writeTestPng($this->root . '/Graphics/object.png', 8, 12);
    expect(MapCompletenessValidator::validateCoverage('test/map', $data, $layers, $graphics, $this->root))->toBe([]);
    // Replacement dimensions and bytes are developer-owned; no receipt hash or duplicate size gate.
    writeTestPng($this->root . '/Graphics/object.png', 19, 21);
    expect(MapCompletenessValidator::validateCoverage('test/map', $data, $layers, $graphics, $this->root))->toBe([]);
    unlink($this->root . '/Graphics/object.png');
    $invalid = MapCompletenessValidator::validateCoverage('test/map', $data, $layers, $graphics, $this->root);
    expect(array_column($invalid, 'rule'))->toContain('object.art', 'graphics.glyph');
});

it('refuses missing NPC artwork and glyphs revealed by conditionally absent fixtures', function () {
    writeTestPng($this->root . '/Graphics/B.png', 32, 32);
    writeTestPng($this->root . '/Graphics/object.png', 8, 12);
    $layers = new MapLayerSet([new MapLayer('fixtures', 0, false, 'synthetic', 'i ')]);
    $graphics = new MapGraphics(new Tileset('test', 'Test', ['B' => 'Graphics/B.png']), []);
    $data = ['worldObjects' => [[
        'id' => 'fixture', 'anchor' => ['x' => 0, 'y' => 0], 'pivot' => ['x' => .5, 'y' => 1.], 'sprites2d' => null,
        'covers' => ['layer' => 'fixtures', 'cells' => [[0, 0]], 'tileLayers' => []],
        'conditions' => [['type' => 'switch', 'name' => 'present']],
    ]], 'npcs' => [['id' => 'person', 'name' => 'Person', 'x' => 1, 'y' => 0]]];
    $issues = MapCompletenessValidator::validateCoverage('test/map', $data, $layers, $graphics, $this->root);
    expect(array_column($issues, 'rule'))->toBe(['graphics.glyph', 'npc.art']);
    writeCharacterSheetPng($this->root . '/Graphics/People.png');
    $data['npcs'][0]['sprites2d'] = ['sheet' => 'Graphics/People.png', 'index' => 0];
    unset($data['worldObjects'][0]['conditions']);
    expect(MapCompletenessValidator::validateCoverage('test/map', $data, $layers, $graphics, $this->root))->toBe([]);
});
