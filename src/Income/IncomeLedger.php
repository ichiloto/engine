<?php

namespace Ichiloto\Engine\Income;

/**
 * Every schedule's saved standing, keyed by schedule id. Accounts for ids
 * the project no longer declares are kept as saved, so removing and
 * restoring a schedule during development never loses earned pay.
 *
 * @package Ichiloto\Engine\Income
 */
final class IncomeLedger
{
  /** @var array<string, IncomeAccount> */
  private array $accounts = [];

  /** The account for a schedule, opened empty on first use. */
  public function getAccount(string $scheduleId): IncomeAccount
  {
    return $this->accounts[$scheduleId] ??= new IncomeAccount();
  }

  /** @return array<string, array{progress: float, held: int, adjustment: int}> */
  public function toArray(): array
  {
    $data = [];
    foreach ($this->accounts as $id => $account) {
      if (! $account->isEmpty) {
        $data[$id] = $account->toArray();
      }
    }
    ksort($data);
    return $data;
  }

  /**
   * Restores saved accounts. Saves written before the feature existed have
   * none, which means nothing counted, held or owed.
   *
   * @param mixed $data The saved ledger.
   * @return self The ledger.
   */
  public static function fromArray(mixed $data): self
  {
    $ledger = new self();
    foreach (is_array($data) ? $data : [] as $id => $account) {
      if (is_string($id) && trim($id) !== '') {
        $ledger->accounts[trim($id)] = IncomeAccount::fromArray($account);
      }
    }
    return $ledger;
  }
}
