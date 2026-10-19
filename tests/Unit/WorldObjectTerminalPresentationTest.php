<?php

use Ichiloto\Engine\Field\WorldObjectTerminalPresentation;
use Ichiloto\Engine\IO\Console\SgrColorParser;
use Ichiloto\Engine\IO\Console\TerminalText;

it('changes foreground while preserving the actual glyph background and emphasis', function () {
    $presentation = WorldObjectTerminalPresentation::read(['color' => 'white']);
    $cell = $presentation->getSymbol("\e[1;33;44mm\e[0m");
    $colors = SgrColorParser::parse($cell);
    expect($presentation->usesOwnedFootprint())->toBeTrue()
        ->and(TerminalText::stripAnsi($cell))->toBe('m')
        ->and($colors['foreground']->toArray())->toBe(['kind' => 'ansi16', 'index' => 15])
        ->and($colors['background']->toArray())->toBe(['kind' => 'ansi16', 'index' => 4]);
});

it('supports an explicit anchor symbol and refuses malformed footprint styling', function () {
    $presentation = WorldObjectTerminalPresentation::read('<fg=yellow>m</>');
    expect($presentation->usesOwnedFootprint())->toBeFalse()
        ->and(TerminalText::stripAnsi($presentation->getSymbol('x')))->toBe('m')
        ->and(WorldObjectTerminalPresentation::read(null))->toBeNull();
    foreach ([[], ['color' => []], ['color' => ''], ['color' => 'white', 'cells' => []], ['color' => 'nonsense']] as $data) {
        expect(fn() => WorldObjectTerminalPresentation::read($data))->toThrow(InvalidArgumentException::class);
    }
});
