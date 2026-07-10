<?php

namespace Modules\Finance\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Http\Resources\JournalDetailResource;
use Modules\Finance\Http\Resources\JournalResource;
use Modules\Finance\Models\Journal;
use Modules\Finance\Repositories\JournalRepository;

class JournalService
{
    public function __construct(
        protected JournalRepository $repository,
    ) {}

    public function listAll(?string $q = null, ?string $createdSince = null)
    {
        return $this->repository->paginate(false, $q, $createdSince)
            ->through(fn (Journal $j) => $this->mapItem($j));
    }

    public function listManual(?string $q = null, ?string $createdSince = null)
    {
        return $this->repository->paginate(true, $q, $createdSince)
            ->through(fn (Journal $j) => $this->mapItem($j));
    }

    public function detail(string $id): ?array
    {
        if (! $this->isValidUuid($id)) {
            return null;
        }

        $journal = $this->repository->findWithDetails($id);

        return $journal ? $this->mapDetail($journal) : null;
    }

    public function saveManual(array $data, ?string $userId = null): ?array
    {
        $journalId = $data['journal_id'] ?? 0;
        $isCreate = $journalId === 0 || $journalId === '0' || $journalId === null || $journalId === '';

        $lines = array_map(fn (array $line) => [
            'account_id' => $line['account_id'],
            'debit' => round((float) ($line['debit'] ?? 0), 4),
            'credit' => round((float) ($line['credit'] ?? 0), 4),
            'description' => $line['description'] ?? null,
        ], $data['accounts']);

        $totalDebit = round(array_sum(array_column($lines, 'debit')), 4);
        $totalCredit = round(array_sum(array_column($lines, 'credit')), 4);
        if ($totalDebit !== $totalCredit) {
            throw new \DomainException("Jurnal tidak seimbang: total debit {$totalDebit} ≠ total kredit {$totalCredit}.");
        }

        $header = [
            'transaction_date' => $data['transaction_date'] ?? now(),
            'notes' => $data['notes'] ?? null,
            'source_doc_no' => $data['source_doc_no'] ?? null,
        ];

        if ($isCreate) {
            return $this->createManual($header, $lines, $userId);
        }

        return $this->updateManual((string) $journalId, $header, $lines);
    }

    protected function createManual(array $header, array $lines, ?string $userId): array
    {
        $journal = $this->withNumberingRetry(function () use ($header, $lines, $userId) {
            return DB::transaction(function () use ($header, $lines, $userId) {
                return $this->repository->createWithLines($header + [
                    'journal_no' => $this->repository->nextJournalNo(),
                    'journal_type' => Journal::TYPE_MANUAL,
                    'created_by' => $userId,
                ], $lines);
            });
        });

        return $this->mapDetail($journal);
    }

    protected function updateManual(string $journalId, array $header, array $lines): ?array
    {
        if (! $this->isValidUuid($journalId)) {
            return null;
        }

        return DB::transaction(function () use ($journalId, $header, $lines) {
            $journal = $this->repository->findForUpdate($journalId);

            if (! $journal) {
                return null;
            }

            if (! $journal->isManual()) {
                throw new \DomainException('Jurnal otomatis tidak dapat diubah — hanya jurnal manual yang bisa diedit.');
            }

            return $this->mapDetail($this->repository->replaceLines($journal, $header, $lines));
        });
    }

    protected function withNumberingRetry(callable $fn): Journal
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException $e) {
            return $fn();
        }
    }

    public function mapItem(Journal $journal): array
    {
        return (new JournalResource($journal))->toArray(request());
    }

    public function mapDetail(Journal $journal): array
    {
        return $this->mapItem($journal) + [
            'accounts' => $journal->details
                ->map(fn ($d) => (new JournalDetailResource($d))->toArray(request()))
                ->all(),
        ];
    }

    protected function isValidUuid(string $id): bool
    {
        $normalized = str_replace('-', '', $id);

        return strlen($normalized) === 32 && ctype_xdigit($normalized);
    }
}
