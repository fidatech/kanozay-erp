<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\EmployeeProfile;
use App\Models\FixedAsset;
use App\Models\FixedAssetAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FixedAssetAssignmentService
{
    public function assign(FixedAsset $asset, EmployeeProfile $employee, string $date, ?string $notes, int $actorId): FixedAssetAssignment
    {
        return DB::transaction(function () use ($asset, $employee, $date, $notes, $actorId) {
            $locked = FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $holder = EmployeeProfile::whereKey($employee->id)->where('is_active', true)->first();
            if ($locked->status !== 'active' || ! $holder) $this->reject('Assign only active assets to active employees.');
            if ($locked->custodian_id || $locked->assignments()->whereNull('returned_on')->exists()) $this->reject('Return the existing assignment before assigning this asset again.');
            if ($date < $locked->acquired_on->toDateString()) $this->reject('Assignment date cannot precede acquisition.');
            $assignment = $locked->assignments()->create([
                'employee_profile_id' => $holder->id, 'assigned_on' => $date,
                'notes' => $notes, 'assigned_by' => $actorId,
            ]);
            $locked->update(['custodian_id' => $holder->id]);
            ActivityLog::record('fixed_asset.assigned', 'fixed_asset', $locked->id, 'Asset '.$locked->asset_tag.' assigned');
            return $assignment;
        });
    }

    public function returnAssignment(FixedAsset $asset, FixedAssetAssignment $assignment, string $date, int $actorId): void
    {
        DB::transaction(function () use ($asset, $assignment, $date, $actorId) {
            $locked = FixedAsset::whereKey($asset->id)->lockForUpdate()->firstOrFail();
            $record = FixedAssetAssignment::whereKey($assignment->id)->where('fixed_asset_id', $locked->id)->lockForUpdate()->firstOrFail();
            if ($record->returned_on || (int) $locked->custodian_id !== (int) $record->employee_profile_id) $this->reject('This assignment has already been returned.');
            if ($date < $record->assigned_on->toDateString()) $this->reject('Return date cannot precede assignment.');
            $record->update(['returned_on' => $date, 'returned_by' => $actorId]);
            $locked->update(['custodian_id' => null]);
            ActivityLog::record('fixed_asset.returned', 'fixed_asset', $locked->id, 'Asset '.$locked->asset_tag.' returned');
        });
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['assignment' => $message]);
    }
}
