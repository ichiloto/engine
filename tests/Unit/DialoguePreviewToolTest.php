<?php

it('previews a different skit background while preserving non-actor speaker bindings', function () {
    $directory = sys_get_temp_dir() . '/dialogue-preview-' . bin2hex(random_bytes(6));
    mkdir($directory . '/Data/Presentation', 0777, true);
    $chunk = static fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 48, 64, 8, 6, 0, 0, 0))
        . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat("\xA0\xB0\xC0\xFF", 48), 64))) . $chunk('IEND', '');
    foreach (['speaker', 'background', 'proposal'] as $name) { file_put_contents($directory . '/' . $name . '.png', $png); }
    $cataloguePath = $directory . '/Data/Presentation/dialogue.php';
    $catalogue = '<?php return ' . var_export([
        'schema' => 'ichiloto.dialogue/1', 'theme' => ['schema' => 'ichiloto.menu/1'],
        'resources' => ['innkeeper-art' => ['portrait' => 'speaker.png', 'bust' => 'speaker.png']],
        'speakers' => ['Innkeeper' => 'innkeeper-art'],
        'skits' => ['sample' => ['background' => 'background.png']],
    ], true) . ';';
    file_put_contents($cataloguePath, $catalogue);
    $snapshot = $directory . '/snapshot.json';
    file_put_contents($snapshot, json_encode([
        'speaker' => 'Innkeeper', 'text' => 'Welcome.', 'actorId' => 'innkeeper-art',
        'skitId' => 'sample', 'participants' => [
            ['actorId' => 'innkeeper-art', 'name' => 'Innkeeper', 'emotion' => 'Neutral'],
        ],
    ], JSON_THROW_ON_ERROR));
    $executable = $directory . '/renderer';
    $capture = $directory . '/wire.ndjson';
    file_put_contents($executable, '#!' . PHP_BINARY . "\n<?php\n\$argv = "
        . var_export([$executable, 'dialogue_preview', $capture], true)
        . ";\nrequire " . var_export(__DIR__ . '/../Fixtures/Renderer/renderer-stub.php', true) . ";\n");
    chmod($executable, 0755);
    $process = null;
    try {
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/tools/gpui-dialogue-preview.php',
            '--renderer=' . $executable, '--asset-root=' . $directory, '--snapshot=' . $snapshot,
            '--background=proposal.png', '--duration=1'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exit = proc_close($process); $process = null;
        expect($exit)->toBe(0, $stderr)
            ->and($stdout)->toContain('Proposed background is preview-only.', 'Preview acknowledged', 'Preview closed cleanly.')
            ->and(file_get_contents($cataloguePath))->toBe($catalogue);
        $wire = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($capture, FILE_IGNORE_NEW_LINES));
        expect($wire[0]['type'])->toBe('hello')->and(end($wire)['type'])->toBe('shutdown');
        $frames = array_values(array_filter($wire, fn($message) => $message['type'] === 'frame'));
        $assets = [];
        foreach ($frames as $frame) {
            foreach ($frame['operations'] as $operation) {
                if ($operation['op'] === 'put' && $operation['kind'] === 'canvas_image') {
                    $assets[] = $operation['value']['asset'];
                }
            }
        }
        expect($assets)->toContain('proposal.png', 'speaker.png')->not->toContain('background.png');
    } finally {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
});
