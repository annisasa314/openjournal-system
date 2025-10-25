<?php

/**
 * @file classes/migration/upgrade/v3_4_0/I6306_EnableCategories.php
 *
 * Copyright (c) 2014-2024 Simon Fraser University
 * Copyright (c) 2000-2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I6306_EnableCategories
 *
 * @brief Set the new context setting, `submitWithCategories`, to `true` for
 *   existing journals to preserve the pre-existing behaviour.
 */

namespace PKP\migration\upgrade\v3_4_0;

use Illuminate\Support\Facades\DB;
use PKP\migration\Migration;

abstract class I6306_EnableCategories extends Migration
{
    abstract protected function getContextTable(): string;
    abstract protected function getContextSettingsTable(): string;
    abstract protected function getContextIdColumn(): string;

    public function up(): void
    {
        // Ambil nama tabel dan kolom dari class turunan
        $contextTable = $this->getContextTable();
        $contextSettingsTable = $this->getContextSettingsTable();
        $contextIdColumn = $this->getContextIdColumn();

        // Validasi nama tabel dan kolom agar tidak berasal dari input user
        $allowedContextTables = ['journals', 'presses', 'contexts'];
        $allowedContextColumns = ['journal_id', 'press_id', 'context_id'];

        if (!in_array($contextTable, $allowedContextTables, true)) {
            throw new \RuntimeException("Invalid context table: {$contextTable}");
        }

        if (!in_array($contextIdColumn, $allowedContextColumns, true)) {
            throw new \RuntimeException("Invalid context column: {$contextIdColumn}");
        }

        // Ambil semua ID context yang ada
        $contextIds = DB::table($contextTable)->pluck($contextIdColumn);

        if ($contextIds->isEmpty()) {
            return;
        }

        // Siapkan data untuk diinsert secara massal
        $insertData = $contextIds->map(fn ($id) => [
            $contextIdColumn => $id,
            'setting_name' => 'submitWithCategories',
            'setting_value' => '1',
        ])->toArray();

        // Gunakan Query Builder yang aman (parameter binding)
        DB::table($contextSettingsTable)->insert($insertData);
    }

    public function down(): void
    {
        $contextSettingsTable = $this->getContextSettingsTable();

        // Hapus setting yang ditambahkan
        DB::table($contextSettingsTable)
            ->where('setting_name', 'submitWithCategories')
            ->delete();
    }
}
