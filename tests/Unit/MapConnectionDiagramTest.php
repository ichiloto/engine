<?php

use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\UI\Presentation\MapConnectionDiagram;

it('compresses distant stations while preserving cardinal order and distinct one-cell locations',function() {
    $layout=MapConnectionDiagram::getLayout(['a'=>[0,0],'b'=>[1000000,1000000],'c'=>[2000000,1000000]],40,12);
    expect($layout['width'])->toBeLessThanOrEqual(40)->and($layout['height'])->toBeLessThanOrEqual(12)
        ->and($layout['positions']['a'][0])->toBeLessThan($layout['positions']['b'][0])
        ->and($layout['positions']['a'][1])->toBeLessThan($layout['positions']['b'][1])
        ->and($layout['positions']['b'][0])->toBeLessThan($layout['positions']['c'][0]);
});

it('routes a direct link around an unrelated intermediate location',function() {
    $layout=MapConnectionDiagram::getLayout(['a'=>[0,0],'middle'=>[1,0],'b'=>[2,0]],40,12);
    $rows=MapConnectionDiagram::renderMap($layout['positions'],[['a','b'],['b','a']],40,12,'a','a');
    [$x,$y]=$layout['positions']['middle'];
    $line=TerminalText::visibleSymbols(TerminalText::stripAnsi($rows[$y]));
    expect($line[$x])->toBe('■')->and($line[$x-1])->toBe(' ')->and($line[$x+1])->toBe(' ')
        ->and(substr_count(TerminalText::stripAnsi(implode('',$rows)),'■'))->toBe(3);
});

it('distinguishes one-way connections from reciprocal routes without changing block positions',function() {
    $layout=MapConnectionDiagram::getLayout(['a'=>[0,0],'b'=>[1,0]],20,8);
    $one=MapConnectionDiagram::renderMap($layout['positions'],[['a','b']],20,8,'a','a');
    $both=MapConnectionDiagram::renderMap($layout['positions'],[['a','b'],['b','a']],20,8,'a','b');
    expect(implode('',$one))->toContain('→')->and(implode('',$both))->not->toContain('→');
    foreach([$one,$both] as $rows) {
        foreach($layout['positions'] as [$x,$y]) {
            expect(TerminalText::visibleSymbols(TerminalText::stripAnsi($rows[$y]))[$x])->toBe('■');
        }
        foreach($rows as $row) { expect(TerminalText::displayWidth($row))->toBe(20); }
    }
});
