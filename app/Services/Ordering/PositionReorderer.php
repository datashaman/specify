<?php

namespace App\Services\Ordering;

use Illuminate\Support\Facades\DB;

/**
 * Two-pass position rewrite that avoids violating a `(scope, position)`
 * unique index. Used by both the Livewire UI and the MCP tools that expose
 * reordering — single source for the shift dance.
 */
class PositionReorderer
{
    /**
     * Rewrite positions on the rows owned by `$scopeId` so they match the
     * supplied id order. Returns true when the rewrite ran, false when the
     * payload didn't cover every owned id (no-op in that case).
     *
     * @param  array<int, int>  $orderedIds  Row IDs in their new visual order.
     */
    public function reorder(string $table, string $scopeColumn, int $scopeId, array $orderedIds): bool
    {
        return DB::transaction(function () use ($table, $scopeColumn, $scopeId, $orderedIds): bool {
            $owned = DB::table($table)
                ->where($scopeColumn, $scopeId)
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $clean = array_values(array_filter(
                array_map('intval', $orderedIds),
                fn (int $id) => in_array($id, $owned, true),
            ));

            if (count($clean) !== count($owned)) {
                return false;
            }

            // Stage to positions safely above any current row so phase 1 can't
            // collide with the existing (scope, position) values, then snap to
            // 1..N in phase 2.
            $maxPosition = (int) DB::table($table)
                ->where($scopeColumn, $scopeId)
                ->max('position');
            $offset = $maxPosition + 1;

            foreach ($clean as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => $offset + $i]);
            }

            foreach ($clean as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => $i + 1]);
            }

            return true;
        });
    }
}
