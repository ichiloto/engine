<?php

use Ichiloto\Engine\Core\Vector2;
use Ichiloto\Engine\IO\Console\{Console,TerminalText};
use Ichiloto\Engine\UI\Windows\Window;
use Ichiloto\Engine\UI\Windows\Enumerations\WindowHeightPolicy;

it('keeps long styled titles and hints inside their window without overwriting a neighbour',function(int $width,string $caption) {
    $prior=new ReflectionClass(Console::class)->getStaticProperties();
    try {
        Console::setTerminalOutputEnabled(false);
        foreach(['width'=>100,'height'=>8,'buffer'=>[],'frameDepth'=>0,'frameRows'=>[],'terminalHandedBack'=>false] as $name=>$value) {
            new ReflectionProperty(Console::class,$name)->setValue(null,$value);
        }
        $window=new Window($caption,$caption,new Vector2(0,0),$width,4,heightPolicy:WindowHeightPolicy::FIXED);
        Console::write('!',$width,0); Console::write('!',$width,3);
        $window->render();
        $rows=Console::getBuffer();
        foreach([0=>$window->getBorderPack()::getTopRightCorner(),3=>$window->getBorderPack()::getBottomRightCorner()] as $y=>$corner) {
            $line=TerminalText::stripAnsi($rows[$y]);
            $border=TerminalText::truncateToWidth($line,$width);
            expect(TerminalText::displayWidth($border))->toBe($width)
                ->and(str_ends_with($border,TerminalText::stripAnsi($corner)))->toBeTrue()
                ->and(str_ends_with(TerminalText::truncateToWidth($line,$width+1),'!'))->toBeTrue();
        }
        expect($window->getTitle())->toBe($caption)->and($window->getHelp())->toBe($caption);
    } finally {
        foreach($prior as $name=>$value) { new ReflectionProperty(Console::class,$name)->setValue(null,$value); }
    }
})->with([[24,str_repeat('A long control hint ',12)],[78,'<fg=cyan>'.str_repeat('界 é 💎 ',30).'</>']]);
