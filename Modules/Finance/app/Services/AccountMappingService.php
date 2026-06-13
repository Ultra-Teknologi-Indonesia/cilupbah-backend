<?php

namespace Modules\Finance\Services;

use Modules\Finance\Repositories\AccountMappingRepository;
use Modules\Finance\Repositories\AccountRepository;
use Modules\Finance\Support\AccountMappingKey;

class AccountMappingService
{
    public function __construct(
        protected AccountMappingRepository $repository,
        protected AccountRepository $accountRepository,
    ) {}

    public function list(): array
    {
        $stored = $this->repository->allWithAccount()->keyBy('key');

        $result = [];
        foreach (AccountMappingKey::keys() as $key) {
            $mapping = $stored->get($key);
            $configured = $mapping && $mapping->account;

            $result[] = [
                'key' => $key,
                'label' => AccountMappingKey::label($key),
                'configured' => (bool) $configured,
                'account' => $configured
                    ? $mapping->account
                    : $this->accountRepository->findByCode(AccountMappingKey::defaultCode($key)),
            ];
        }

        return $result;
    }

    public function save(array $items): array
    {
        foreach ($items as $item) {
            $this->repository->upsert($item['key'], $item['account_id'] ?? null);
        }

        return $this->list();
    }

    public function resolveAccountId(string $key, string $fallbackCode): ?string
    {
        $mapping = $this->repository->findByKey($key);

        if ($mapping && $mapping->account_id && $mapping->account) {
            return $mapping->account_id;
        }

        return $this->accountRepository->findByCode($fallbackCode)?->id;
    }
}
