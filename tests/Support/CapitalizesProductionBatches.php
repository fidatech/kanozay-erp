<?php

namespace Tests\Support;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\ProductionBatch;
use App\Models\User;
use App\Services\Accounting\JournalPostingService;
use App\Services\ManufacturingLedgerPolicyService;
use Illuminate\Support\Facades\DB;

/**
 * Test fixture for sales/return tests that start from an already released batch.
 *
 * The manufacturing ledger policy is created and independently approved through the
 * real ManufacturingLedgerPolicyService, and every journal is posted through the real
 * JournalPostingService so GL balances are genuine. The production_batch_cost_postings
 * row itself is inserted directly: this fixture does NOT exercise
 * ManufacturingCostAccountingService::capitalize(), which needs a full GRN, supplier
 * invoice and FIFO issue chain and must be covered by its own end-to-end test.
 */
trait CapitalizesProductionBatches
{
    /**
     * Currency-specific manufacturing ledger mapping, proposed and independently approved
     * through the real ManufacturingLedgerPolicyService.
     *
     * @return array{policy:object,ledgers:array<string,LedgerAccount>,reviewer:User}
     */
    protected function approvedManufacturingPolicyForTest(Currency $currency): array
    {
        $ledgers = [];
        foreach ([
            'material' => ['T-RM-'.$currency->code, 'Test raw material inventory', 'asset'],
            'wip' => ['T-WIP-'.$currency->code, 'Test work in progress', 'asset'],
            'finished' => ['T-FG-'.$currency->code, 'Test finished goods inventory', 'asset'],
            'cogs' => ['T-COGS-'.$currency->code, 'Test cost of goods sold', 'expense'],
            'opening' => ['T-OPEN-'.$currency->code, 'Test opening equity', 'equity'],
        ] as $key => [$code, $name, $type]) {
            $ledgers[$key] = LedgerAccount::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'is_active' => true, 'is_group' => false,
            ]);
        }

        $proposer = User::factory()->create();
        $reviewer = User::factory()->create();
        $policies = app(ManufacturingLedgerPolicyService::class);
        $policy = DB::table('manufacturing_cost_ledger_policies')->where('currency_id', $currency->id)->first();
        if (! $policy) {
            $policyId = $policies->create([
                'currency_id' => $currency->id,
                'material_inventory_ledger_id' => $ledgers['material']->id,
                'work_in_progress_ledger_id' => $ledgers['wip']->id,
                'finished_inventory_ledger_id' => $ledgers['finished']->id,
                'cogs_ledger_id' => $ledgers['cogs']->id,
                'authorization_reference' => 'TEST-MFG-POLICY',
                'notes' => 'Synthetic manufacturing ledger mapping for automated tests.',
            ], $proposer->id);
            $policies->approve($policyId, 'TEST-MFG-POLICY-APPROVAL', $reviewer->id);
        }
        $policy = $policies->approved($currency->id);

        return ['policy' => $policy, 'ledgers' => $ledgers, 'reviewer' => $reviewer];
    }

    /** @return array{policy:object,posting:object,ledgers:array<string,LedgerAccount>} */
    protected function capitalizeBatchForTest(ProductionBatch $batch, Currency $currency, string $totalCost, string $date): array
    {
        ['policy' => $policy, 'ledgers' => $ledgers, 'reviewer' => $reviewer] = $this->approvedManufacturingPolicyForTest($currency);

        $journal = function (string $suffix, int $debit, int $credit, string $text) use ($batch, $currency, $totalCost, $date, $reviewer) {
            $entry = JournalEntry::create([
                'number' => 'T-MFG-'.$batch->id.'-'.$suffix, 'entry_date' => $date,
                'reference' => $batch->batch_number, 'description' => $text,
                'currency_id' => $currency->id, 'status' => 'draft', 'created_by' => $reviewer->id,
            ]);
            $entry->lines()->createMany([
                ['ledger_account_id' => $debit, 'debit' => $totalCost, 'credit' => '0'],
                ['ledger_account_id' => $credit, 'debit' => '0', 'credit' => $totalCost],
            ]);
            app(JournalPostingService::class)->post($entry, $reviewer->id);

            return $entry;
        };
        $journal('OPEN', (int) $policy->material_inventory_ledger_id, $ledgers['opening']->id, 'Synthetic raw material opening value');
        $issue = $journal('ISSUE', (int) $policy->work_in_progress_ledger_id, (int) $policy->material_inventory_ledger_id, 'Synthetic material issue to WIP');
        $capitalization = $journal('CAP', (int) $policy->finished_inventory_ledger_id, (int) $policy->work_in_progress_ledger_id, 'Synthetic WIP to finished goods');

        $id = DB::table('production_batch_cost_postings')->insertGetId([
            'production_batch_id' => $batch->id,
            'manufacturing_cost_ledger_policy_id' => $policy->id,
            'currency_id' => $currency->id,
            'accepted_packages' => (int) $batch->accepted_packages,
            'material_amount' => $totalCost, 'overhead_amount' => '0', 'total_amount' => $totalCost,
            'accounting_date' => $date,
            'issue_journal_id' => $issue->id, 'capitalization_journal_id' => $capitalization->id,
            'approval_reference' => 'TEST-BATCH-COST', 'reconciliation_notes' => 'Synthetic test fixture.',
            'approved_by' => $reviewer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            'policy' => $policy,
            'posting' => DB::table('production_batch_cost_postings')->where('id', $id)->first(),
            'ledgers' => $ledgers,
        ];
    }

    /** Posted GL balance (debit minus credit) of one ledger in one currency. */
    protected function ledgerBalanceForTest(int $ledgerId, int $currencyId): string
    {
        $balance = \Brick\Math\BigDecimal::zero();
        foreach (DB::table('journal_lines as line')
            ->join('journal_entries as entry', 'entry.id', '=', 'line.journal_entry_id')
            ->where('entry.status', 'posted')->where('entry.currency_id', $currencyId)
            ->where('line.ledger_account_id', $ledgerId)->get(['line.debit', 'line.credit']) as $row) {
            $balance = $balance->plus((string) $row->debit)->minus((string) $row->credit);
        }

        return (string) $balance->toScale(4);
    }
}
