<?php

namespace Modules\Finance\Repositories;

use Illuminate\Support\Facades\DB;
use Modules\Finance\Models\Journal;
use Spatie\QueryBuilder\QueryBuilder;

class JournalRepository
{

    public function paginate(bool $manualOnly = false, ?string $q = null, ?string $createdSince = null)
    {
        return QueryBuilder::for(Journal::class)
            ->when($manualOnly, fn ($query) => $query->where('journal_type', Journal::TYPE_MANUAL))
            ->when($q, function ($query) use ($q) {
                $query->where(function ($w) use ($q) {
                    $w->where('journal_no', 'ilike', "%{$q}%")
                        ->orWhere('source_doc_no', 'ilike', "%{$q}%")
                        ->orWhere('notes', 'ilike', "%{$q}%");
                });
            })
            ->when($createdSince, fn ($query) => $query->where('transaction_date', '>=', $createdSince))
            ->allowedSorts('transaction_date', 'journal_no', 'created_at')
            ->defaultSort('-transaction_date')
            ->paginate(request('per_page', 10))
            ->appends(request()->query());
    }

    public function findWithDetails(string $id): ?Journal
    {
        return Journal::with('details.account')->find($id);
    }

    public function findBySourceDoc(string $type, string $id): ?Journal
    {
        return Journal::with('details.account')
            ->where('source_doc_type', $type)
            ->where('source_doc_id', $id)
            ->first();
    }

    public function nextJournalNo(): string
    {
        $last = Journal::query()
            ->lockForUpdate()
            ->orderByDesc('journal_no')
            ->value('journal_no');

        $seq = $last ? ((int) substr($last, 3)) + 1 : 1;

        return 'GJ-' . str_pad((string) $seq, 7, '0', STR_PAD_LEFT);
    }

    public function createWithLines(array $header, array $lines): Journal
    {
        $journal = Journal::create($header + [
            'total_debit' => array_sum(array_column($lines, 'debit')),
            'total_credit' => array_sum(array_column($lines, 'credit')),
        ]);

        $journal->details()->createMany($lines);

        return $journal->load('details.account');
    }

    public function replaceLines(Journal $journal, array $header, array $lines): Journal
    {
        $journal->details()->delete();
        $journal->details()->createMany($lines);

        $journal->update($header + [
            'total_debit' => array_sum(array_column($lines, 'debit')),
            'total_credit' => array_sum(array_column($lines, 'credit')),
        ]);

        return $journal->load('details.account');
    }

    public function existsForSourceDoc(string $type, string $id): bool
    {
        return DB::table('journals')
            ->where('source_doc_type', $type)
            ->where('source_doc_id', $id)
            ->exists();
    }
}
