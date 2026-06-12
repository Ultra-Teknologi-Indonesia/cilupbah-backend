<?php

namespace Modules\Finance\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Finance\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\Journal;
use Tests\TestCase;

/**
 * Endpoint Journal (PLAN-JOURNAL.md U1, U8–U18, U22):
 * lookup COA, list (+q/createdSince), detail, POST manual create/edit + semua guard.
 */
class JournalApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->seed(ChartOfAccountsSeeder::class);
    }

    private function accountId(string $code): string
    {
        return Account::where('account_code', $code)->value('id');
    }

    private function validBody(array $override = []): array
    {
        return array_merge([
            'journal_id' => 0,
            'notes' => 'Penyesuaian kas kecil',
            'transaction_date' => '2026-06-12T08:00:00',
            'accounts' => [
                ['account_id' => $this->accountId('1-1000'), 'debit' => 150000, 'credit' => 0, 'description' => 'Kas masuk'],
                ['account_id' => $this->accountId('3-3000'), 'debit' => 0, 'credit' => 150000, 'description' => 'Setoran modal'],
            ],
        ], $override);
    }

    // ── U1: lookup COA ──

    public function test_account_lookup_returns_seeded_accounts_in_jubelio_format(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/accounts/lookup/all');

        $response->assertStatus(200)
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('data.0.account_code', '1-1000')
            ->assertJsonPath('data.0.account_name', '1-1000 - Kas'); // urut code, format Jubelio
    }

    // ── U11: create manual journal ──

    public function test_create_manual_journal_balanced(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $this->validBody());

        $response->assertStatus(200)
            ->assertJsonPath('data.journal_no', 'GJ-0000001')
            ->assertJsonPath('data.journal_type', 'Manual Jurnal')
            ->assertJsonPath('data.debit', '150000.0000')
            ->assertJsonPath('data.credit', '150000.0000')
            ->assertJsonCount(2, 'data.accounts');

        $this->assertDatabaseHas('journals', ['journal_no' => 'GJ-0000001', 'journal_type' => 'Manual Jurnal']);
    }

    public function test_journal_numbering_is_sequential(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/journal/manual-journal', $this->validBody());
        $second = $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/journal/manual-journal', $this->validBody());

        $second->assertJsonPath('data.journal_no', 'GJ-0000002');
    }

    // ── U12–U14: guard validasi ──

    public function test_unbalanced_journal_returns_422(): void
    {
        $body = $this->validBody();
        $body['accounts'][1]['credit'] = 100000; // ≠ 150000

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts']);
    }

    public function test_single_line_returns_422(): void
    {
        $body = $this->validBody();
        $body['accounts'] = [$body['accounts'][0]];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts']);
    }

    public function test_line_with_both_sides_or_neither_returns_422(): void
    {
        $both = $this->validBody();
        $both['accounts'][0]['credit'] = 150000; // dua sisi terisi

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $both)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts.0']);

        $neither = $this->validBody();
        $neither['accounts'][0]['debit'] = 0; // dua sisi nol

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $neither)
            ->assertStatus(422);
    }

    public function test_invalid_account_id_returns_422_not_500(): void
    {
        $nonUuid = $this->validBody();
        $nonUuid['accounts'][0]['account_id'] = 'bukan-uuid';

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $nonUuid)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['accounts.0.account_id']);

        $ghost = $this->validBody();
        $ghost['accounts'][0]['account_id'] = (string) Str::uuid();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $ghost)
            ->assertStatus(422);
    }

    // ── U15–U17: edit ──

    public function test_edit_manual_journal_replaces_lines(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $this->validBody())
            ->json('data');

        $edit = $this->validBody([
            'journal_id' => $created['journal_id'],
            'notes' => 'Direvisi',
        ]);
        $edit['accounts'][0]['debit'] = 200000;
        $edit['accounts'][1]['credit'] = 200000;

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $edit)
            ->assertStatus(200)
            ->assertJsonPath('data.journal_no', $created['journal_no']) // nomor tetap
            ->assertJsonPath('data.debit', '200000.0000')
            ->assertJsonPath('data.notes', 'Direvisi')
            ->assertJsonCount(2, 'data.accounts');

        $this->assertEquals(1, Journal::count()); // edit, bukan duplikat
    }

    public function test_edit_automatic_journal_rejected_422(): void
    {
        $auto = Journal::create([
            'journal_no' => 'GJ-0000001',
            'transaction_date' => now(),
            'journal_type' => null, // otomatis
            'notes' => 'auto',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $this->validBody(['journal_id' => $auto->id]))
            ->assertStatus(422);
    }

    public function test_edit_unknown_journal_returns_404(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $this->validBody(['journal_id' => (string) Str::uuid()]))
            ->assertStatus(404);
    }

    // ── U8–U10, U18: listing & detail ──

    public function test_journal_list_paginates_and_searches(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/journal/manual-journal', $this->validBody());
        }

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal')
            ->assertStatus(200)
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.total', 12);

        // q mencari journal_no.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal?q=GJ-0000003')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.journal_no', 'GJ-0000003');
    }

    public function test_created_since_invalid_returns_422(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/journal?createdSince=bukan-tanggal')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['createdSince']);
    }

    public function test_manual_list_excludes_automatic_journals(): void
    {
        Journal::create(['journal_no' => 'GJ-0000099', 'transaction_date' => now(), 'journal_type' => null]);
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/journal/manual-journal', $this->validBody());

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal/manual-journal')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.journal_type', 'Manual Jurnal');

        // /journal berisi keduanya.
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal')
            ->assertJsonPath('meta.total', 2);
    }

    public function test_journal_detail_and_404_guards(): void
    {
        $created = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/journal/manual-journal', $this->validBody())
            ->json('data');

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/journal/' . $created['journal_id'])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.accounts')
            ->assertJsonPath('data.accounts.0.account_name', '1-1000 - Kas');

        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal/bukan-uuid')->assertStatus(404);
        $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/journal/' . Str::uuid())->assertStatus(404);
    }

    // ── U22: auth ──

    public function test_all_endpoints_require_auth(): void
    {
        $this->getJson('/api/v1/accounts/lookup/all')->assertStatus(401);
        $this->getJson('/api/v1/journal')->assertStatus(401);
        $this->getJson('/api/v1/journal/manual-journal')->assertStatus(401);
        $this->postJson('/api/v1/journal/manual-journal', [])->assertStatus(401);
    }
}
