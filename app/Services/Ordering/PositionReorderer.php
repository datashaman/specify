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
        $owned = DB::table($table)->where($scopeColumn, $scopeId)->pluck('id')->all();
        $clean = array_values(array_filter(
            array_map('intval', $orderedIds),
            fn (int $id) => in_array($id, $owned, true),
        ));

        if (count($clean) !== count($owned)) {
            return false;
        }

        DB::transaction(function () use ($table, $clean) {
            $offset = count($clean) + 1;

            foreach ($clean as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => $offset + $i]);
            }

            foreach ($clean as $i => $id) {
                DB::table($table)->where('id', $id)->update(['position' => $i + 1]);
            }
        });

        return true;
    }
}
