<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Events\EventManager;
use Ichiloto\Engine\IO\Console\Console;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Modal\CreditsModal;
use Ichiloto\Engine\UI\Presentation\CreditsContent;
use Ichiloto\Engine\UI\Presentation\CreditsMenuPresentation;
use Ichiloto\Engine\UI\Presentation\CreditsPlayback;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\PlaySettings;

final class CreditsPresentationGame extends Game
{
  public function __construct() {}
  public function __destruct() {}
}

final class CreditsAlertProbe extends CreditsModal
{
  public function prepareAndRender(): void
  {
    $this->fitContentToWidth();
    $this->positionForOpen();
    $this->show();
    $this->render();
  }
  public function advancePage(): void { $this->submit(); }
  public function dismiss(): void { $this->cancel(); }
  protected function playInteractionSound(\Ichiloto\Engine\Audio\Enumerations\SystemSound $sound): void {}
}

beforeEach(function () {
  $this->statics = [];
  foreach ([Console::class, ConfigStore::class, EventManager::class] as $class) {
    $this->statics[$class] = new ReflectionClass($class)->getStaticProperties();
  }
  Console::setTerminalOutputEnabled(false);
  foreach (['width' => 80, 'height' => 24, 'buffer' => []] as $key => $value) {
    new ReflectionProperty(Console::class, $key)->setValue(null, $value);
  }
  new ReflectionProperty(EventManager::class, 'instance')->setValue(null, null);
  ConfigStore::put(PlaySettings::class, new PlaySettings(['width' => 80, 'height' => 24]));
});

afterEach(function () {
  foreach ($this->statics as $class => $properties) {
    foreach ($properties as $key => $value) { new ReflectionProperty($class, $key)->setValue(null, $value); }
  }
});

it('preserves authored order and wraps every credit into bounded pages', function () {
  $content = new CreditsContent([
    ['title' => 'Design', 'lines' => ['Andrew Masiye', 'Second contributor']],
    ['title' => 'Thanks', 'lines' => ['Players everywhere']],
    false, ['title' => 'Empty', 'lines' => [false]],
  ]);
  expect(array_column($content->getLines(40), 'text'))->toBe([
    'Design', 'Andrew Masiye', 'Second contributor', '', 'Thanks', 'Players everywhere',
  ]);
  $pages = $content->getPages(10, 3);
  foreach ($pages as $page) {
    expect(count(explode("\n", $page)))->toBeLessThanOrEqual(3);
    foreach (explode("\n", $page) as $line) { expect(TerminalText::displayWidth($line))->toBeLessThanOrEqual(10); }
  }
  expect(implode('', array_map(static fn(string $page) => str_replace(["\n", ' '], '', $page), $pages)))
    ->toBe('DesignAndrewMasiyeSecondcontributorThanksPlayerseverywhere');
});

it('advances by update time without catching up pauses or long frame stalls', function () {
  $playback = new CreditsPlayback(100, 20);
  $playback->advance(0);
  $playback->advance(0.05);
  expect($playback->offset)->toBe(1.0);
  $playback->advance(30, true);
  $playback->advance(90);
  expect($playback->offset)->toBe(1.0);
  $playback->advance(190);
  expect($playback->offset)->toBe(3.0);
  for ($i = 1; $i <= 100; $i++) { $playback->advance(190 + $i * 0.1); }
  expect($playback->finished)->toBeTrue()->and($playback->offset)->toBe(100.0);
  expect(fn() => $playback->advance(0))->toThrow(InvalidArgumentException::class);
});

it('clips a complete credit roll independently of rendering and theme', function (array $data) {
  $theme = new MenuPresentationCatalog(sys_get_temp_dir(), ['schema' => 'ichiloto.menu/1', ...$data]);
  $content = new CreditsContent([['title' => 'Made by', 'lines' => array_map(static fn(int $i) => 'Contributor ' . $i, range(1, 80))]]);
  $playback = CreditsMenuPresentation::createPlayback($content, $theme);
  $viewport = CreditsMenuPresentation::getViewport($theme);
  $playback->advance(0);
  $seen = [];
  for ($tick = 1; !$playback->finished; $tick++) {
    $playback->advance($tick / 10);
    if ($tick % 10 !== 0 && !$playback->finished) { continue; }
    $frame = CreditsMenuPresentation::compose($content, $playback, $theme);
    expect(CreditsMenuPresentation::compose($content, $playback, $theme)->toArray())->toBe($frame->toArray());
    expect(count($frame->textLayers))->toBeLessThanOrEqual(64);
    foreach ($frame->textLayers as $layer) {
      expect($layer->id)->not->toContain('cursor');
      if ($layer->id !== 'credits-roll') { continue; }
      expect($layer->clipRect)->toEqual($viewport);
      foreach ($layer->runs as $run) {
        $seen[$run->text] = true;
        $center = $layer->x + ($run->column + mb_strlen($run->text) / 2) * $layer->grid->cellWidth;
        expect(abs($center - 675))->toBeLessThanOrEqual($theme->metrics->cellWidth / 2);
      }
    }
  }
  foreach (['Made by', ...$content->sections[0]['lines']] as $line) { expect($seen[$line] ?? false)->toBeTrue(); }
})->with([
  [[]],
  [['colors' => ['text' => [30, 20, 10], 'panel' => [240, 230, 200]], 'metrics' => ['cellWidth' => 8, 'cellHeight' => 16, 'rowHeight' => 32]]],
]);

it('centers the terminal alert and its prose and dismisses without visiting remaining pages', function () {
  $game = new CreditsPresentationGame();
  $modal = new CreditsAlertProbe($game, new CreditsContent([['title' => 'Design', 'lines' => ['Andrew Masiye', ...array_fill(0, 25, 'Contributor')]]]));
  $modal->prepareAndRender();
  $box = $modal->getPresentationBounds();
  expect($box->getX())->toBe(intdiv(80 - $box->getWidth(), 2))
    ->and($box->getY())->toBe(intdiv(24 - $box->getHeight(), 2))
    ->and($box->getHeight())->toBeLessThanOrEqual(24)
    ->and($modal->activeButton)->toBe('Next');
  $rows = array_map(TerminalText::stripAnsi(...), Console::getBuffer());
  expect(mb_strpos($rows[$box->getY() + 2], 'Andrew Masiye'))
    ->toBe($box->getX() + 1 + intdiv($box->getWidth() - 2 - mb_strlen('Andrew Masiye'), 2));
  $modal->advancePage();
  $modal->render();
  expect($modal->isShowing())->toBeTrue()->and($modal->message)->not->toContain('Design');
  $modal->dismiss();
  expect($modal->isShowing())->toBeFalse();
});

it('keeps a short credit alert to one centered confirmation', function () {
  $game = new CreditsPresentationGame();
  $modal = new CreditsAlertProbe($game, new CreditsContent([['title' => 'Design', 'lines' => ['Andrew Masiye']]]));
  $modal->prepareAndRender();
  expect($modal->activeButton)->toBe('OK')->and($modal->getModalPresentation()->singleConfirmation)->toBeTrue();
  $modal->advancePage();
  expect($modal->isShowing())->toBeFalse();
});
