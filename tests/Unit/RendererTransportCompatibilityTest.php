<?php

declare(strict_types=1);

use Ichiloto\Engine\Rendering\Runtime\RendererRuntime;
use Ichiloto\Engine\Rendering\Runtime\RendererRuntimeConfig;
use Ichiloto\Engine\Rendering\Transport\RendererEventType;
use Ichiloto\Engine\Rendering\Transport\RendererMessage;
use Ichiloto\Engine\Rendering\Transport\RendererMessageType;
use Ichiloto\Engine\Rendering\Transport\RendererProcessConfig;
use Ichiloto\Engine\Rendering\Transport\RendererProtocolVersion;
use Ichiloto\Engine\Rendering\Transport\RendererSessionConfig;
use Ichiloto\Engine\Rendering\Transport\RendererTransportInterface;
use Ichiloto\Engine\Rendering\Transport\RendererTransportState;
use Ichiloto\Engine\Rendering\Transport\Enumerations;
use Ichiloto\Engine\Rendering\Transport\Interfaces;

it('retains the identity of every relocated public transport enum', function (string $legacy, string $current) {
  expect(enum_exists($legacy))->toBeTrue()->and($legacy::cases())->toBe($current::cases());
  foreach ($legacy::cases() as $case) {
    expect($case)->toBeInstanceOf($current)->and(unserialize(serialize($case)))->toBe($case);
  }
})->with([
  [RendererEventType::class, Enumerations\RendererEventType::class],
  [RendererMessageType::class, Enumerations\RendererMessageType::class],
  [RendererProtocolVersion::class, Enumerations\RendererProtocolVersion::class],
  [RendererTransportState::class, Enumerations\RendererTransportState::class],
]);

it('accepts a custom transport written against the previous public namespace', function () {
  $transport = new class implements RendererTransportInterface {
    public function start(RendererSessionConfig $session): void {}
    public function isRunning(): bool { return false; }
    public function send(RendererMessage $message): void {}
    public function pollEvents(float $waitSeconds = 0.0): array { return []; }
    public function shutdown(): ?int { return 0; }
    public function getState(): RendererTransportState { return RendererTransportState::STOPPED; }
    public function getExitCode(): ?int { return 0; }
    public function getDiagnostics(): string { return ''; }
  };
  $config = new RendererRuntimeConfig(new RendererProcessConfig(['not-launched']), __DIR__,
    protocol: RendererProtocolVersion::V2);
  $runtime = new RendererRuntime($config, $transport);
  expect($transport)->toBeInstanceOf(Interfaces\RendererTransportInterface::class)
    ->and($transport->getState())->toBe(Enumerations\RendererTransportState::STOPPED)
    ->and($runtime->getAssetRoot())->toBe(__DIR__);
});
