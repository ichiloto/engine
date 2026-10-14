<?php

use Ichiloto\Engine\Field\MapGridSource;

it('reads only a literal nowdoc while preserving its grid content', function (): void {
    $source = "<?php\r\n\r\n// Authored grid.\r\nreturn <<<'TERRAIN'\r\n;~\r\n  \r\nTERRAIN;\r\n";

    expect(MapGridSource::parseSource($source, 'terrain.map.php'))->toBe(";~\r\n  ");
    expect(MapGridSource::parseSource("<?php\nreturn <<<'EMPTY'\nEMPTY;\n", 'empty.map.php'))->toBe('');
    expect(MapGridSource::parseSource("<?php\nreturn <<< 'INDENT'\n    first\n  \n      \n    second\n    INDENT;\n", 'indent.map.php'))
        ->toBe("first\n\n  \nsecond");
    expect(MapGridSource::parseSource("<?php\r\nreturn <<<'TAB'\r\n\tfirst\r\n\t\r\n\tsecond\r\n\tTAB;\r\n", 'tab.map.php'))
        ->toBe("first\r\n\r\nsecond");
});

it('refuses executable and non-nowdoc grids without executing them', function (string $source): void {
    $marker = sys_get_temp_dir() . '/ichiloto-map-grid-execution-' . bin2hex(random_bytes(8));
    $source = str_replace('EXECUTION_MARKER', var_export($marker, true), $source);

    try {
        expect(fn (): string => MapGridSource::parseSource($source, 'unsafe.map.php'))
            ->toThrow(InvalidArgumentException::class, 'literal nowdoc');
        expect(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
    }
})->with([
    'expression' => "<?php return file_put_contents(EXECUTION_MARKER, 'ran');",
    'statement before nowdoc' => "<?php file_put_contents(EXECUTION_MARKER, 'ran'); return <<<'GRID'\na\nGRID;",
    'class before nowdoc' => "<?php class GridBuilder {} return <<<'GRID'\na\nGRID;",
    'array' => "<?php return ['a'];",
    'interpolated heredoc' => "<?php return <<<GRID\na\nGRID;",
    'additional statement' => "<?php return <<<'GRID'\na\nGRID; file_put_contents(EXECUTION_MARKER, 'ran');",
]);

it('names the offending token and line when refusing executable source', function (): void {
    expect(fn (): string => MapGridSource::parseSource("<?php\nreturn <<<'GRID'\na\nGRID;\nprint 'bad';", 'bad.map.php'))
        ->toThrow(InvalidArgumentException::class, 'T_PRINT at line 5');
});

it('chooses a safe nowdoc label even when grid rows resemble the preferred closer', function (): void {
    $body = "ICHILOTO_MAP;\n  ICHILOTO_MAP_1;\nordinary row";
    $source = MapGridSource::buildSource($body, 'ICHILOTO_MAP');

    expect($source)->toContain("<<<'ICHILOTO_MAP_2'")
        ->and(MapGridSource::parseSource($source, 'generated.map.php'))->toBe($body);
});
