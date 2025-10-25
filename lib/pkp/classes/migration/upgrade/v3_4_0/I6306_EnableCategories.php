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

        // Validasi tambahan untuk context settings table
        $allowedSettingsTables = ['journal_settings', 'press_settings', 'context_settings'];
        if (!in_array($contextSettingsTable, $allowedSettingsTables, true)) {
            throw new \RuntimeException("Invalid context settings table: {$contextSettingsTable}");
        }

        // Ambil semua ID context yang ada dengan chunk untuk menghindari memory issue
        DB::table($contextTable)
            ->select($contextIdColumn)
            ->chunkById(100, function ($contexts) use ($contextSettingsTable, $contextIdColumn) {
                $insertData = [];
                
                foreach ($contexts as $context) {
                    $insertData[] = [
                        $contextIdColumn => $context->{$contextIdColumn},
                        'setting_name' => 'submitWithCategories',
                        'setting_value' => '1',
                    ];
                }

                // Insert data dengan batch untuk efisiensi
                if (!empty($insertData)) {
                    DB::table($contextSettingsTable)->insert($insertData);
                }
            }, $contextIdColumn);
    }

    public function down(): void
    {
        $contextSettingsTable = $this->getContextSettingsTable();
        
        // Validasi nama tabel settings
        $allowedSettingsTables = ['journal_settings', 'press_settings', 'context_settings'];
        if (!in_array($contextSettingsTable, $allowedSettingsTables, true)) {
            throw new \RuntimeException("Invalid context settings table: {$contextSettingsTable}");
        }

        // Hapus setting yang ditambahkan - sudah aman dengan Query Builder
        DB::table($contextSettingsTable)
            ->where('setting_name', 'submitWithCategories')
            ->where('setting_value', '1')
            ->delete();
    }
}