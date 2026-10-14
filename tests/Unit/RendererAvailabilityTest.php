<?php

use Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver;
use Ichiloto\Engine\Rendering\Launch\RendererAvailability;
use Ichiloto\Engine\Rendering\Launch\RendererDescriptor;
use Ichiloto\Engine\Rendering\Launch\RendererExecutableResolverInterface;
use Ichiloto\Engine\Rendering\Launch\RendererRegistry;
use Ichiloto\Engine\Rendering\Launch\RendererUnavailableException;

beforeEach(function () {
  $this->root = createTestDirectory('renderer-availability-');
  $this->package = $this->root . '/installed';
  mkdir($this->package);
  $this->manifest = $this->package . '/manifest.json';
  $this->platform = PackagedRendererExecutableResolver::hostPlatform();
  $this->registry = new RendererRegistry(new PackagedRendererExecutableResolver($this->manifest));
});

it('always offers terminal and diagnoses absent malformed or missing host installations', function (?array $manifest, ?string $raw, string $diagnostic) {
  if ($manifest !== null) {
    $manifest['renderers']['gpui'] = [$this->platform => $manifest['renderers']['gpui']];
    file_put_contents($this->manifest, json_encode($manifest, JSON_THROW_ON_ERROR));
  } elseif ($raw !== null) {
    file_put_contents($this->manifest, $raw);
  }
  $report = $this->registry->getReport();
  expect($report['schema'])->toBe('ichiloto.renderers/1')
    ->and($report['preferredId'])->toBe('terminal')
    ->and($report['renderers'][0])->toBe(['id' => 'terminal', 'displayName' => 'Native Terminal', 'available' => true, 'reason' => null])
    ->and($report['renderers'][1]['id'])->toBe('gpui')
    ->and($report['renderers'][1]['displayName'])->toBe('GPUI')
    ->and($report['renderers'][1]['available'])->toBeFalse()
    ->and($report['renderers'][1]['reason'])->toContain($diagnostic);
})->with([
  'absent' => [null, null, 'No installed renderer manifest'],
  'invalid json' => [null, '{', 'Invalid installed renderer manifest'],
  'invalid shape' => [null, 'null', 'Invalid installed renderer manifest'],
  'unknown version' => [null, '{"version":2,"renderers":{}}', 'Invalid installed renderer manifest'],
  'no current platform' => [null, '{"version":1,"renderers":{"gpui":{"unsupported-host":"renderer"}}}', 'No implementation is installed for this platform'],
  'missing executable' => [['version' => 1, 'renderers' => ['gpui' => 'missing']], null, 'missing or not executable'],
  'traversal' => [['version' => 1, 'renderers' => ['gpui' => '../outside']], null, 'Invalid packaged executable entry'],
  'absolute path' => [['version' => 1, 'renderers' => ['gpui' => '/outside']], null, 'Invalid packaged executable entry'],
  'windows absolute path' => [['version' => 1, 'renderers' => ['gpui' => 'C:\\outside']], null, 'Invalid packaged executable entry'],
]);

it('prefers a resolver-validated executable without launching it or changing installation files', function () {
  $marker = $this->root . '/native-launched';
  $executable = $this->package . '/renderer with spaces';
  file_put_contents($executable, "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\n");
  chmod($executable, 0755);
  $manifest = json_encode(['version' => 1, 'renderers' => ['gpui' => [$this->platform => basename($executable)]]], JSON_THROW_ON_ERROR);
  file_put_contents($this->manifest, $manifest);
  $contents = file_get_contents($executable);
  expect($this->registry->getReport())->toBe([
    'schema' => 'ichiloto.renderers/1',
    'renderers' => [
      ['id' => 'terminal', 'displayName' => 'Native Terminal', 'available' => true, 'reason' => null],
      ['id' => 'gpui', 'displayName' => 'GPUI', 'available' => true, 'reason' => null],
    ],
    'preferredId' => 'gpui',
  ])->and(is_file($marker))->toBeFalse()
    ->and(file_get_contents($this->manifest))->toBe($manifest)
    ->and(file_get_contents($executable))->toBe($contents);
});

it('uses the resolver executable and containment checks without a separate discovery interpretation', function () {
  $executable = $this->package . '/renderer';
  file_put_contents($this->manifest, json_encode(['version' => 1, 'renderers' => ['gpui' => [$this->platform => 'renderer']]], JSON_THROW_ON_ERROR));
  file_put_contents($executable, 'not executable');
  chmod($executable, 0644);
  expect($this->registry->getReport()['renderers'][1]['available'])->toBeFalse();
  unlink($executable);
  $outside = $this->root . '/outside';
  file_put_contents($outside, '#!/bin/sh');
  chmod($outside, 0755);
  symlink($outside, $executable);
  expect($this->registry->getReport()['renderers'][1]['available'])->toBeFalse()
    ->and($this->registry->getReport()['preferredId'])->toBe('terminal');
});

it('queries the installed resolver once and never constructs a runtime', function () {
  $resolver = new class implements RendererExecutableResolverInterface {
    public array $calls = [];
    public function resolve(string $rendererId): string { $this->calls[] = $rendererId; return '/synthetic/validated'; }
  };
  $report = new RendererRegistry($resolver)->getReport();
  expect($report['preferredId'])->toBe('gpui')->and($resolver->calls)->toBe(['gpui']);
});

it('supports alternative descriptors and separates discovery from runtime factories', function () {
  $neverCreate = static fn(string $root) => throw new RuntimeException('Discovery constructed a runtime.');
  $registry = new RendererRegistry(descriptors: [
    new RendererDescriptor('legacy', $neverCreate),
    new RendererDescriptor('canvas', $neverCreate, 'Custom Canvas', static fn(): RendererAvailability => new RendererAvailability(true)),
    new RendererDescriptor('offline', $neverCreate, 'Offline', static fn() => throw new RendererUnavailableException('Not installed.')),
  ]);
  $report = $registry->getReport();
  expect($report['preferredId'])->toBe('canvas')
    ->and($report['renderers'][0]['displayName'])->toBe('legacy')
    ->and($report['renderers'][0]['available'])->toBeFalse()
    ->and($report['renderers'][0]['reason'])->toContain('not supported')
    ->and($report['renderers'][1])->toBe(['id' => 'canvas', 'displayName' => 'Custom Canvas', 'available' => true, 'reason' => null])
    ->and($report['renderers'][2]['reason'])->toBe('Not installed.')
    ->and(new RendererRegistry(descriptors: [])->getReport()['preferredId'])->toBeNull();
});

it('keeps terminal ahead of other available alternatives when GPUI is unavailable', function () {
  $descriptor = static fn(string $id) => new RendererDescriptor($id, static fn($root) => null,
    availabilityProbe: static fn(): RendererAvailability => new RendererAvailability(true));
  expect(new RendererRegistry(descriptors: [$descriptor('canvas'), $descriptor('terminal')])->getReport()['preferredId'])
    ->toBe('terminal');
});

it('does not hide unexpected discovery errors as an unavailable installation', function () {
  $descriptor = new RendererDescriptor('broken', static fn($root) => null,
    availabilityProbe: static fn() => throw new LogicException('Implementation bug.'));
  expect(fn() => $descriptor->getAvailability())->toThrow(LogicException::class, 'Implementation bug.');
});

it('requires coherent availability diagnostics', function (bool $available, ?string $reason) {
  expect(fn() => new RendererAvailability($available, $reason))->toThrow(InvalidArgumentException::class);
})->with([[true, 'not unavailable'], [false, null], [false, '   ']]);
