<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Date columns must hold the same `Y-m-d` value on SQLite and MySQL so string and SQL
 * date comparisons in the posting services cannot differ between the two databases.
 */
class DateOnlyStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_date_casts_are_persisted_without_a_time_component(): void
    {
        $user = User::factory()->create();
        $currency = Currency::create(['code' => 'AFN', 'name' => 'Afghani', 'is_active' => true, 'is_base' => true]);
        $base = ['currency_id' => $currency->id, 'status' => 'draft', 'description' => 'Synthetic', 'created_by' => $user->id];

        $fromString = JournalEntry::create($base + ['number' => 'T-DATE-1', 'entry_date' => '2026-11-02']);
        $fromCarbon = JournalEntry::create($base + ['number' => 'T-DATE-2', 'entry_date' => CarbonImmutable::parse('2026-11-02 17:45:10')]);
        $fromCarbon->update(['entry_date' => '2026-11-03 09:00:00']);

        $this->assertSame('2026-11-02', (string) DB::table('journal_entries')->where('id', $fromString->id)->value('entry_date'));
        $this->assertSame('2026-11-03', (string) DB::table('journal_entries')->where('id', $fromCarbon->id)->value('entry_date'));
        $this->assertSame('2026-11-02', $fromString->fresh()->entry_date->toDateString());

        // Plain string comparison in SQL now matches same-day accounting dates.
        $this->assertSame(1, DB::table('journal_entries')->where('entry_date', '<=', '2026-11-02')->count());
        $this->assertSame(2, DB::table('journal_entries')->where('entry_date', '<=', '2026-11-03')->count());
        // posted_at is a datetime and keeps its time.
        $fromString->update(['posted_at' => '2026-11-02 08:30:00']);
        $this->assertStringContainsString('08:30:00', (string) DB::table('journal_entries')->where('id', $fromString->id)->value('posted_at'));
    }
}
