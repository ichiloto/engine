<?php

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicPresentationManager;
use Ichiloto\Engine\Cutscenes\Cinematics\TimedPresentationOperation;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Rendering\Camera;
use Ichiloto\Engine\Rendering\Presentation\ScenePresentationContext;
use Ichiloto\Engine\Rendering\Transport\RendererGridConfig;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Scenes\Game\GameScene;
use Ichiloto\Engine\Util\Debug;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/ichiloto-timed-dialogue-' . bin2hex(random_bytes(6));
    mkdir($this->root . '/Data/Presentation', 0777, true);
    $this->consoleBefore = new ReflectionClass(Console::class)->getStaticProperties();
    $this->debugBefore = new ReflectionClass(Debug::class)->getStaticProperties();
    Debug::configure(['log_directory' => $this->root]);
    $this->scene = new ReflectionClass(GameScene::class)->newInstanceWithoutConstructor();
    new ReflectionProperty(GameScene::class, 'camera')->setValue($this->scene,
        new Camera($this->scene, 24, 8, worldSpace: array_fill(0, 30, str_repeat('.', 60))));
    $this->presentation = new CinematicPresentationManager($this->scene);
    new ReflectionProperty(GameScene::class, 'cinematicPresentation')->setValue($this->scene, $this->presentation);
    $this->theme = ['schema' => 'ichiloto.menu/1', 'colors' => ['text' => [41, 83, 127]],
        'metrics' => ['cellWidth' => 10, 'cellHeight' => 28, 'panelPadding' => 24]];
    $this->writeTheme = function (array $theme): void {
        file_put_contents($this->root . '/Data/Presentation/dialogue.php', '<?php return ' . var_export([
            'schema' => 'ichiloto.dialogue/1', 'theme' => $theme,
        ], true) . ';');
    };
    ($this->writeTheme)($this->theme);
    $this->caps = [RendererSessionConfig::GRAPHICAL_CANVAS, RendererSessionConfig::CANVAS_OVERLAY,
        RendererSessionConfig::CANVAS_CLIP_OPACITY, RendererSessionConfig::SPRITE_SOURCE_RECT];
    new ReflectionProperty(GameScene::class, 'presentationContext')->setValue($this->scene,
        new ScenePresentationContext(new RendererGridConfig(135, 36, 10, 20),
            fn(string $cap): bool => in_array($cap, $this->caps, true), assetRoot: $this->root));
    $this->getProse = static fn($canvas) => array_find($canvas->textLayers,
        static fn($layer) => $layer->id === 'dialogue-text');
});

afterEach(function () {
    foreach ([Console::class => $this->consoleBefore, Debug::class => $this->debugBefore] as $class => $state) {
        foreach ($state as $name => $value) { new ReflectionProperty($class, $name)->setValue(null, $value); }
    }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($this->root);
});

it('uses full themed reading width independently of field-camera tiles and removes timed controls', function (bool $warm) {
    if ($warm) {
        ($this->writeTheme)(array_replace_recursive($this->theme,
            ['colors' => ['text' => [113, 71, 29], 'panel' => [224, 216, 198]] ]));
    }
    $words = str_repeat('Synthetic prose stays on its shared reading surface. ', 4);
    $this->presentation->showOverlay('narration', $words);
    new TimedPresentationOperation($this->presentation, 12.0);
    $canvas = $this->scene->getPresentationOverlay(1350, 720);
    $prose = ($this->getProse)($canvas);
    expect($prose->clipRect->width)->toBeGreaterThan(1100)
        ->and(count($prose->runs))->toBeLessThanOrEqual(3)
        ->and($prose->runs[0]->foreground->toArray())->toBe(['kind' => 'rgb',
            'r' => $warm ? 113 : 41, 'g' => $warm ? 71 : 83, 'b' => $warm ? 29 : 127])
        ->and($this->scene->getExcludedOverlayLayers())->toBe(['cinematic-overlay'])
        ->and(array_any($canvas->textLayers, static fn($layer) => preg_match('/dialogue-(auto|advance|ready|footer)/', $layer->id)))
        ->toBeFalse()
        ->and(array_column($canvas->textLayers, 'id'))->not->toContain('menu-background');
})->with([false, true]);

it('keeps title cards centred and narration at the top with left-aligned prose', function () {
    $this->presentation->showOverlay('title_card', 'A synthetic subtitle.', 'Synthetic title');
    $title = ($this->getProse)($this->scene->getPresentationOverlay(1350, 720));
    expect($title->runs[0]->column)->toBe(intdiv($title->grid->columns - mb_strlen($title->runs[0]->text), 2))
        ->and($title->y)->toBeGreaterThan(250);
    $this->presentation->showOverlay('narration', 'A synthetic subtitle.');
    $narration = ($this->getProse)($this->scene->getPresentationOverlay(1350, 720));
    expect($narration->runs[0]->column)->toBe(0)->and($narration->y)->toBeLessThan(100);
});

it('advances long passages using operation time without rendering changing the clock', function () {
    $this->presentation->showOverlay('narration', "First\nSecond\nThird\nFourth\nFifth\nSixth\nSeventh");
    $operation = new TimedPresentationOperation($this->presentation, 7.0);
    $getText = fn() => implode("\n", array_column(($this->getProse)($this->scene->getPresentationOverlay(1350, 720))->runs, 'text'));
    expect($getText())->toBe("First\nSecond\nThird");
    for ($index = 0; $index < 5; $index++) { expect($getText())->toBe("First\nSecond\nThird"); }
    expect($operation->update(3.0))->toBeFalse()->and($getText())->toBe("Fourth\nFifth\nSixth");
    expect($operation->update(3.0))->toBeFalse()->and($getText())->toBe('Seventh');
    expect($operation->update(1.0))->toBeTrue()
        ->and($this->scene->getPresentationOverlay(1350, 720))->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
});

it('allocates reading time by page word count rather than spending equal time on a short last page', function () {
    $this->presentation->showOverlay('narration', "One two three\nFour five six\nSeven eight nine\nEnd");
    $operation = new TimedPresentationOperation($this->presentation, 10.0);
    $getText = fn() => implode("\n", array_column(($this->getProse)($this->scene->getPresentationOverlay(1350, 720))->runs, 'text'));
    $operation->update(6.0);
    expect($getText())->toBe("One two three\nFour five six\nSeven eight nine");
    $operation->update(3.0);
    expect($getText())->toBe('End');
});

it('reserves page metadata outside timed prose even with a zero-padding theme', function () {
    ($this->writeTheme)(array_replace_recursive($this->theme, ['metrics' => ['panelPadding' => 0]]));
    $this->presentation->showOverlay('narration', "One\nTwo\nThree\nFour");
    new TimedPresentationOperation($this->presentation, 10.0);
    $canvas = $this->scene->getPresentationOverlay(1350, 720);
    $prose = ($this->getProse)($canvas);
    $caption = array_find($canvas->textLayers, static fn($layer) => $layer->id === 'dialogue-page');
    expect($caption->y)->toBeGreaterThanOrEqual($prose->y + $prose->bounds->height);
});

it('does not let an older operation clear or advance replacement text', function (bool $cancel) {
    $this->presentation->showOverlay('narration', 'Old cue.');
    $old = new TimedPresentationOperation($this->presentation, 1.0);
    $this->presentation->showOverlay('narration', "New first\nNew second\nNew third\nNew fourth");
    $current = new TimedPresentationOperation($this->presentation, 10.0);
    $cancel ? $old->cancel() : $old->update(1.0);
    $canvas = $this->scene->getPresentationOverlay(1350, 720);
    expect(implode("\n", array_column(($this->getProse)($canvas)->runs, 'text')))->toBe("New first\nNew second\nNew third");
    $current->cancel();
    expect($this->scene->getPresentationOverlay(1350, 720))->toBeNull();
})->with([false, true]);

it('retains standard Terminal windows when the graphical theme is invalid and does not mask their text', function () {
    ($this->writeTheme)(array_replace($this->theme, ['schema' => 'unsupported']));
    $this->presentation->showOverlay('narration', 'Visible fallback text.');
    expect($this->scene->getPresentationOverlay(1350, 720))->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
    $rows = Console::capturePresentation(135, 36, fn() => $this->presentation->render())->rows;
    $text = implode("\n", array_map(TerminalText::stripAnsi(...), $rows));
    expect($text)->toContain('Visible fallback text.')->not->toContain('+---');
});

it('uses the shared procedural frame when optional border artwork is missing', function () {
    ($this->writeTheme)(array_replace($this->theme,
        ['frames' => ['dialogue' => ['asset' => 'missing.png', 'cuts' => [2, 2, 2, 2]]]]));
    $this->presentation->showOverlay('narration', 'Visible shared dialogue text.');
    $canvas = $this->scene->getPresentationOverlay(1350, 720);
    expect(($this->getProse)($canvas)->runs[0]->text)->toBe('Visible shared dialogue text.')
        ->and($canvas->images)->toBe([])
        ->and($this->scene->getExcludedOverlayLayers())->toBe(['cinematic-overlay']);
});

it('clears a successful projection when an isolated host no longer supplies an asset root', function () {
    $this->presentation->showOverlay('narration', 'Root-owned cue.');
    expect($this->scene->getPresentationOverlay(1350, 720))->not->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe(['cinematic-overlay']);
    new ReflectionProperty(GameScene::class, 'presentationContext')->setValue($this->scene,
        new ScenePresentationContext(new RendererGridConfig(135, 36, 10, 20), static fn(): bool => true));
    expect($this->scene->getPresentationOverlay(1350, 720))->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
});

it('requires the isolated host asset root and its graphical capabilities before replacing Terminal text', function (string $missing) {
    $this->caps = array_values(array_diff($this->caps, [$missing]));
    $this->presentation->showOverlay('narration', 'Capability-safe text.');
    expect($this->scene->getPresentationOverlay(1350, 720))->toBeNull()
        ->and($this->scene->getExcludedOverlayLayers())->toBe([]);
})->with(['graphical_canvas', 'canvas_overlay', 'canvas_clip_opacity', 'sprite_source_rect']);
