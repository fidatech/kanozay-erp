<?php

namespace App\Models\Concerns;

/**
 * Persist `date` casts as plain `Y-m-d`.
 *
 * Eloquent writes date-cast attributes using the connection datetime format
 * (`Y-m-d H:i:s`). MySQL DATE columns truncate that to the date, but SQLite keeps
 * the literal `2026-11-02 00:00:00`. Posting services compare these columns as
 * strings (and in SQL) against `Y-m-d` accounting dates, so on SQLite a same-day
 * document looked "later" than its own posting date and was rejected.
 *
 * Storing the date only makes both databases hold the identical value, so
 * period, chronology and reconciliation checks behave the same everywhere.
 */
trait StoresDateOnlyColumns
{
    public function setAttribute($key, $value)
    {
        if ($value !== null && $value !== ''
            && $this->isDateOnlyCast($key)
            && ! $this->hasSetMutator($key)
            && ! $this->hasAttributeSetMutator($key)) {
            $this->attributes[$key] = $this->asDateTime($value)->format('Y-m-d');

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    private function isDateOnlyCast(string $key): bool
    {
        $cast = $this->getCasts()[$key] ?? null;
        if (! is_string($cast)) {
            return false;
        }

        return in_array($cast, ['date', 'immutable_date'], true)
            || str_starts_with($cast, 'date:')
            || str_starts_with($cast, 'immutable_date:');
    }
}
