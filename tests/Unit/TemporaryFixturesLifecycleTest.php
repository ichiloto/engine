<?php

declare(strict_types=1);

/** Real child processes isolate fixture locks and shutdown from Pest's own scope. */
function startFixtureLifecycleChild(string $base, string $code): array
{
    $source = 'require ' . var_export(__DIR__ . '/../Support/TemporaryFixtures.php', true) . ';'
        . '$fixtures = \\Ichiloto\\Engine\\Tests\\Support\\TemporaryFixtures::class;' . $code;
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=64M', '-r', $source],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
        array_replace(getenv(), ['TMPDIR' => $base, 'TEMP' => $base, 'TMP' => $base]));
    if (!is_resource($process)) { throw new RuntimeException('Cannot start fixture lifecycle child.'); }
    foreach ([$pipes[1], $pipes[2]] as $pipe) { stream_set_timeout($pipe, 5); }
    return [$process, $pipes];
}

function finishFixtureLifecycleChild(array $child): array
{
    [$process, $pipes] = $child;
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $stdout, $stderr];
}

it('cleans each test scope and keeps process fixtures until shutdown', function () {
    $base = createTestDirectory('fixture-lifecycle-');
    $child = startFixtureLifecycleChild($base, <<<'PHP'
        $processRoot = $fixtures::getDirectory();
        file_put_contents($processRoot.'/shared', 'shared helper');
        $fixtures::beginTest('first'); $first=$fixtures::getDirectory();
        file_put_contents($first.'/a', 'first'); $fixtures::endTest();
        $fixtures::beginTest('second'); $second=$fixtures::getDirectory();
        file_put_contents($second.'/b', 'second'); $fixtures::endTest();
        echo json_encode([$processRoot,$first,$second,!file_exists($first),!file_exists($second),is_file($processRoot.'/shared')]);
        PHP);
    [$status, $stdout, $stderr] = finishFixtureLifecycleChild($child);
    [$processRoot,$first,$second,$firstGone,$secondGone,$sharedAlive] = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
    expect($status)->toBe(0)->and($stderr)->toBe('')->and($first)->not->toBe($second)
        ->and($firstGone)->toBeTrue()->and($secondGone)->toBeTrue()->and($sharedAlive)->toBeTrue()
        ->and(file_exists($processRoot))->toBeFalse()->and(scandir($base))->toBe(['.','..']);
});

it('removes fatal-exit fixtures without following links or losing their targets', function () {
    $base = createTestDirectory('fixture-fatal-');
    $outside = createTestDirectory('fixture-external-'); file_put_contents($outside.'/keep', 'original');
    $child = startFixtureLifecycleChild($base, '$outside = '.var_export($outside,true).';'. <<<'PHP'
        $root=$fixtures::getDirectory();
        mkdir($root.'/read-only'); file_put_contents($root.'/read-only/file','owned'); chmod($root.'/read-only',0500);
        if (PHP_OS_FAMILY!=='Windows') symlink($outside,$root.'/linked');
        echo json_encode($root)."\n";
        throw new RuntimeException('intentional fatal lifecycle probe');
        PHP);
    [$status, $stdout, $stderr] = finishFixtureLifecycleChild($child);
    $root = json_decode(strtok($stdout,"\n"), true, flags: JSON_THROW_ON_ERROR);
    expect($status)->not->toBe(0)->and($stderr)->toContain('intentional fatal lifecycle probe')
        ->and(file_exists($root))->toBeFalse()->and(file_get_contents($outside.'/keep'))->toBe('original')
        ->and(scandir($base))->toBe(['.','..']);
});

it('removes files flushed by services registered later for shutdown', function () {
    $base=createTestDirectory('fixture-final-flush-');
    $child=startFixtureLifecycleChild($base, <<<'PHP'
        $root=$fixtures::getDirectory();
        register_shutdown_function(static function () use ($root): void {
            file_put_contents($root.'/late-diagnostic','final diagnostic');
        });
        echo json_encode($root);
        PHP);
    [$status,$stdout,$stderr]=finishFixtureLifecycleChild($child);
    expect($status)->toBe(0)->and($stderr)->toBe('')
        ->and(file_exists(json_decode($stdout,true,flags:JSON_THROW_ON_ERROR)))->toBeFalse()
        ->and(scandir($base))->toBe(['.','..']);
});

it('preserves a live run and recovers only that run after its process is killed', function () {
    if (PHP_OS_FAMILY === 'Windows') { $this->markTestSkipped('SIGKILL lifecycle probe requires Unix process signals.'); }
    $base = createTestDirectory('fixture-killed-');
    $child = startFixtureLifecycleChild($base, <<<'PHP'
        $root=$fixtures::getDirectory(); file_put_contents($root.'/owned','unfinished');
        echo json_encode($root)."\n"; fflush(STDOUT); fgets(STDIN);
        PHP);
    [$process,$pipes] = $child;
    try {
        $root = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
        expect(is_file($root.'/owned'))->toBeTrue();
        $second = startFixtureLifecycleChild($base, '$fixtures::getDirectory();');
        [$status,, $stderr] = finishFixtureLifecycleChild($second);
        expect($status)->toBe(0)->and($stderr)->toBe('')->and(is_file($root.'/owned'))->toBeTrue();
        expect(proc_terminate($process,9))->toBeTrue();
        finishFixtureLifecycleChild($child); $child=null;
        expect(is_file($root.'/owned'))->toBeTrue();
        $third = startFixtureLifecycleChild($base, '$fixtures::getDirectory();');
        [$status,, $stderr] = finishFixtureLifecycleChild($third);
        expect($status)->toBe(0)->and($stderr)->toBe('')->and(file_exists($root))->toBeFalse()
            ->and(scandir($base))->toBe(['.','..']);
    } finally {
        if ($child !== null) { proc_terminate($process,9); finishFixtureLifecycleChild($child); }
    }
});

it('recovers diagnostics recreated by an object destructor after shutdown cleanup', function () {
    $base=createTestDirectory('fixture-destructor-');
    $child=startFixtureLifecycleChild($base, <<<'PHP'
        $root=$fixtures::getDirectory();
        $GLOBALS['lateFixtureWriter']=new class($root) {
            public function __construct(private string $root) {}
            public function __destruct() {
                if (!is_dir($this->root)) mkdir($this->root,0700,true);
                file_put_contents($this->root.'/late','destructor diagnostic');
            }
        };
        echo json_encode($root);
        PHP);
    [$status,$stdout,$stderr]=finishFixtureLifecycleChild($child);
    $root=json_decode($stdout,true,flags:JSON_THROW_ON_ERROR);
    expect($status)->toBe(0)->and($stderr)->toBe('')->and(is_file($root.'/late'))->toBeTrue();
    [$status,,$stderr]=finishFixtureLifecycleChild(startFixtureLifecycleChild($base,'$fixtures::getDirectory();'));
    expect($status)->toBe(0)->and($stderr)->toBe('')->and(file_exists($root))->toBeFalse()
        ->and(scandir($base))->toBe(['.','..']);
});

it('reports cleanup failures and retains them for a successful shutdown retry', function () {
    $base = createTestDirectory('fixture-failure-');
    $code = <<<'PHP'
        namespace Ichiloto\Engine\Tests\Support {
            function unlink(string $path): bool {
                if (($GLOBALS['denyFixtureRemoval']??false) && basename($path)==='deny') {
                    trigger_error('simulated filesystem refusal',E_USER_WARNING); return false;
                }
                return \unlink($path);
            }
        }
        namespace {
            require HELPER;
            $fixtures=\Ichiloto\Engine\Tests\Support\TemporaryFixtures::class;
            $fixtures::beginTest('failed cleanup'); $root=$fixtures::getDirectory(); file_put_contents($root.'/deny','owned');
            $GLOBALS['denyFixtureRemoval']=true;
            try { $fixtures::endTest(); throw new \RuntimeException('cleanup did not fail'); }
            catch (\RuntimeException $error) { echo json_encode([$root,$error->getMessage()]); }
            $GLOBALS['denyFixtureRemoval']=false;
        }
        PHP;
    $helper=var_export(__DIR__.'/../Support/TemporaryFixtures.php',true);
    $source=str_replace('HELPER',$helper,$code);
    $process=proc_open([PHP_BINARY,'-d','memory_limit=64M','-r',$source],
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,array_replace(getenv(),['TMPDIR'=>$base]));
    [$status,$stdout,$stderr]=finishFixtureLifecycleChild([$process,$pipes]);
    [$root,$message]=json_decode($stdout,true,flags:JSON_THROW_ON_ERROR);
    expect($status)->toBe(0)->and($stderr)->toBe('')->and($message)->toContain('failed cleanup','/deny','simulated filesystem refusal')
        ->and(file_exists($root))->toBeFalse()->and(scandir($base))->toBe(['.','..']);
});

it('leaves unknown lock files and external data outside its owned run untouched', function () {
    $base = createTestDirectory('fixture-unowned-');
    $foreign=$base.'/ichiloto-engine-fixtures-unowned'; mkdir($foreign); file_put_contents($foreign.'/keep','untouched');
    file_put_contents($foreign.'.lock','some other owner');
    [$status,,$stderr]=finishFixtureLifecycleChild(startFixtureLifecycleChild($base,'$fixtures::getDirectory();'));
    expect($status)->toBe(0)->and($stderr)->toBe('')->and(file_get_contents($foreign.'/keep'))->toBe('untouched')
        ->and(file_get_contents($foreign.'.lock'))->toBe('some other owner');
});

it('refuses a replaced scope directory and removes only its link', function () {
    if (PHP_OS_FAMILY==='Windows') { $this->markTestSkipped('Symlink lifecycle probe requires Unix link privileges.'); }
    $base=createTestDirectory('fixture-replaced-scope-');
    $external=createTestDirectory('fixture-external-scope-');file_put_contents($external.'/keep','original');
    [$status,$stdout,$stderr]=finishFixtureLifecycleChild(startFixtureLifecycleChild($base,'$external='.var_export($external,true).';'. <<<'PHP'
        $fixtures::beginTest('replaced');$root=$fixtures::getDirectory();rmdir($root);symlink($external,$root);
        try { $fixtures::getDirectory();echo 'scope accepted'; }
        catch (RuntimeException $error) { echo $error->getMessage(); }
        $fixtures::endTest();
        PHP));
    expect($status)->toBe(0)->and($stderr)->toBe('')->and($stdout)->toContain('scope ownership changed')
        ->and(file_get_contents($external.'/keep'))->toBe('original')->and(scandir($base))->toBe(['.','..']);
});
