<?php

declare(strict_types=1);

namespace Ichiloto\Engine\Rendering\Presentation\Text;

use InvalidArgumentException;
use RuntimeException;

/** Optional project font roles; no machine font discovery or renderer session state. */
final readonly class ProjectTextCatalog
{
  public const string FILE = 'Data/Presentation/text.php';
  public const string SCHEMA = 'ichiloto.text/1';
  public const int MAX_ROLES = 128;

  /** @param array<string, TextRole> $roles */
  private function __construct(private array $roles) {}

  public static function fromArray(mixed $data): self
  {
    if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA
      || array_diff(array_keys($data), ['schema', 'roles']) !== [] || !is_array($data['roles'] ?? null)
      || count($data['roles']) > self::MAX_ROLES) {
      throw new InvalidArgumentException('Text catalog requires schema ichiloto.text/1 and at most 128 keyed roles.');
    }
    $roles = [];
    foreach ($data['roles'] as $id => $role) {
      self::assertRoleId($id);
      $roles[$id] = TextRole::fromArray($role);
    }
    return new self($roles);
  }

  public static function load(string $assetRoot): ?self
  {
    $file = $assetRoot . '/' . self::FILE;
    if (!file_exists($file) && !is_link($file)) { return null; }
    $root = realpath($assetRoot);
    $path = realpath($file);
    if ($root === false || $path === false || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)
      || !is_file($path) || !is_readable($path)) {
      throw new RuntimeException('Project text catalog must be a readable file inside assets.');
    }
    $data = (static fn(string $file): mixed => require $file)($path);
    if (!is_array($data)) { throw new RuntimeException(self::FILE . ' must return a plain theme array.'); }
    return self::fromArray($data);
  }

  public function getRole(string $id): TextRole
  {
    self::assertRoleId($id);
    return $this->roles[$id] ?? throw new InvalidArgumentException("Unknown project text role '{$id}'.");
  }

  /** @return array{schema: string, roles: array<string, array>} */
  public function toArray(): array
  {
    $roles = [];
    foreach ($this->roles as $id => $role) { $roles[$id] = $role->toArray(); }
    return ['schema' => self::SCHEMA, 'roles' => $roles];
  }

  public static function assertRoleId(mixed $id): void
  {
    if (!is_string($id) || preg_match('/\A[a-z][a-z0-9._-]{0,127}\z/', $id) !== 1) {
      throw new InvalidArgumentException('Text role IDs require stable lowercase identifiers of 1..128 characters.');
    }
  }
}
