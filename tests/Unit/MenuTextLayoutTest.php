<?php

declare(strict_types=1);

use Ichiloto\Engine\IO\Enumerations\Color;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;
use Ichiloto\Engine\Rendering\Presentation\PresentationColor;
use Ichiloto\Engine\UI\Presentation\MenuCanvas;
use Ichiloto\Engine\UI\Presentation\MenuPresentationCatalog;
use Ichiloto\Engine\UI\Presentation\MenuTextLayout;
use Ichiloto\Engine\UI\Presentation\MenuTextWrap;
use Ichiloto\Engine\UI\Windows\Enumerations\HorizontalAlignment;

it('measures styled text by visible codepoints and carries colour across soft wraps and blank paragraphs', function () {
  $source = "Lead \e[31mred words\r\n\r\nstill red\e[39m plain\tend \n";
  $plain = "Lead red words\n\nstill red plain    end \n";
  foreach (range(1, 40) as $columns) {
    $layout = new MenuTextLayout($source, $columns);
    expect($layout->lines)->toBe(MenuTextWrap::lines($plain, $columns));
    $runs = $layout->getRuns(PresentationColor::rgb(10, 20, 30));
    foreach ($layout->lines as $row => $line) {
      $rowRuns = array_values(array_filter($runs, fn($run) => $run->row === $row));
      expect(implode('', array_column($rowRuns, 'text')))->toBe($line);
      foreach ($rowRuns as $run) {
        $run->assertFits($columns, count($layout->lines));
        expect($run->text)->not->toContain("\e");
      }
    }
  }
  $runs = (new MenuTextLayout($source, 40))->getRuns(PresentationColor::rgb(10, 20, 30));
  $red = array_filter($runs, fn($run) => $run->foreground == PresentationColor::ansi16(1));
  expect(implode('', array_column($red, 'text')))->toBe('red wordsstill red')
    ->and($runs[array_key_last($runs)]->foreground)->toEqual(PresentationColor::rgb(10, 20, 30));
});

it('preserves indexed RGB and independently reset backgrounds without emitting terminal commands', function () {
  $layout = new MenuTextLayout("\e[38;5;123;48;2;4;5;6mA\e[39mB\e[49mC\e[2J", 10);
  $runs = $layout->getRuns(PresentationColor::rgb(1, 2, 3));
  expect($layout->lines)->toBe(['ABC'])
    ->and($runs)->toHaveCount(3)
    ->and($runs[0]->foreground)->toEqual(PresentationColor::ansi256(123))
    ->and($runs[0]->background)->toEqual(PresentationColor::rgb(4, 5, 6))
    ->and($runs[1]->foreground)->toEqual(PresentationColor::rgb(1, 2, 3))
    ->and($runs[1]->background)->toEqual(PresentationColor::rgb(4, 5, 6))
    ->and($runs[2]->background)->toBeNull();
});

it('projects supported formatter tags through the same shared colour parser', function () {
  $layout = new MenuTextLayout('Use <fg=red>key</> now', 30);
  $runs = $layout->getRuns(PresentationColor::rgb(10, 20, 30));
  expect($layout->lines)->toBe(['Use key now'])
    ->and($runs)->toHaveCount(3)
    ->and($runs[1]->text)->toBe('key')->and($runs[1]->foreground)->not->toEqual($runs[0]->foreground)
    ->and($runs[2]->foreground)->toEqual($runs[0]->foreground);
});

it('keeps Unicode scalars nonbreaking words whitespace and wrap alignment unchanged', function (HorizontalAlignment $alignment) {
  $plain = "cafe\u{0301} a\u{00A0}b \u{65E5}\u{672C}\u{8A9E} \u{1F680} ";
  $source = Color::apply($plain, Color::CYAN);
  foreach (range(1, 16) as $width) {
    $layout = new MenuTextLayout($source, $width);
    expect($layout->lines)->toBe(MenuTextWrap::lines($plain, $width));
    $runs = $layout->getRuns(alignment: $alignment);
    foreach ($layout->lines as $row => $line) {
      $rowRuns = array_values(array_filter($runs, fn($run) => $run->row === $row));
      expect(implode('', array_column($rowRuns, 'text')))->toBe($line);
      $space = $width - mb_strlen($line);
      $expected = match ($alignment) {
        HorizontalAlignment::LEFT => 0,
        HorizontalAlignment::CENTER => intdiv($space, 2),
        HorizontalAlignment::RIGHT => $space,
      };
      if ($rowRuns !== []) { expect($rowRuns[0]->column)->toBe($expected); }
      foreach ($rowRuns as $run) { $run->assertFits($width, count($layout->lines)); }
    }
  }
})->with(HorizontalAlignment::cases());

it('uses the same formatted layout for shared menu prose without changing its source or centering', function () {
  $theme = new MenuPresentationCatalog(sys_get_temp_dir(), ['schema' => 'ichiloto.menu/1']);
  $view = new MenuCanvas($theme, 500, 200);
  $source = 'Press ' . Color::apply('T', Color::YELLOW) . ' to watch.';
  $view->prose('hint', $source, new CanvasRectangle(20, 20, 190, 100), alignment: HorizontalAlignment::CENTER);
  $layer = array_find($view->finish()->textLayers, fn($layer) => $layer->id === 'hint');
  expect(implode('', array_column($layer->runs, 'text')))->toBe('Press T to watch.')
    ->and($layer->runs)->toHaveCount(3)->and($layer->runs[0]->column)->toBe(1)
    ->and($source)->toContain(Color::YELLOW->value);
});

it('retains strict invalid-text and width diagnostics and omits empty styled runs', function () {
  expect(fn() => new MenuTextLayout("\xff", 5))->toThrow(InvalidArgumentException::class)
    ->and(fn() => new MenuTextLayout('Text', 0))->toThrow(InvalidArgumentException::class)
    ->and(fn() => (new MenuTextLayout("\0", 5))->getRuns())->toThrow(InvalidArgumentException::class)
    ->and((new MenuTextLayout("\e[31m\e[0m\n", 5))->lines)->toBe(['', ''])
    ->and((new MenuTextLayout("\e[31m\e[0m\n", 5))->getRuns())->toBeEmpty();
});
