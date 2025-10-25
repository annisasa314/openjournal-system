<?php

/**
 * @file classes/migration/upgrade/v3_4_0/I7265_EditorialDecisions.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2000-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I7265_EditorialDecisions
 *
 * @brief Database migrations for editorial decision refactor.
 */

namespace PKP\migration\upgrade\v3_4_0;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

abstract class I7265_EditorialDecisions extends \PKP\migration\Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->upReviewRounds();
        $this->upNotifyAllAuthors();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->downReviewRounds();
        $this->downNotifyAllAuthors();
    }

    /**
     * Use null instead of 0 for editorial decisions not in review rounds
     */
    protected function upReviewRounds()
    {
        Schema::table('edit_decisions', function (Blueprint $table) {
            $table->bigInteger('review_round_id')->nullable()->change();
            $table->bigInteger('round')->nullable()->change();
        });

        // Menggunakan Query Builder dengan kondisi yang aman
        DB::table('edit_decisions')
            ->where('review_round_id', 0)
            ->orWhere('round', 0)
            ->update([
                'review_round_id' => null,
                'round' => null
            ]);

        Schema::table('edit_decisions', function (Blueprint $table) {
            $table->foreign('review_round_id')->references('review_round_id')->on('review_rounds');
            $table->index(['review_round_id'], 'edit_decisions_review_round_id');
        });
    }

    /**
     * Restore 0 values instead of null for editorial decisions not in review rounds
     */
    protected function downReviewRounds()
    {
        Schema::table('edit_decisions', function (Blueprint $table) {
            $table->dropForeign(['review_round_id']);
        });

        // Menggunakan Query Builder dengan kondisi yang aman
        DB::table('edit_decisions')
            ->whereNull('review_round_id')
            ->orWhereNull('round')
            ->update([
                'review_round_id' => 0,
                'round' => 0
            ]);

        Schema::table('edit_decisions', function (Blueprint $table) {
            $table->bigInteger('review_round_id')->nullable(false)->change();
            $table->bigInteger('round')->nullable(false)->change();
        });
    }

    /**
     * Enable the new context setting "notifyAllAuthors"
     */
    protected function upNotifyAllAuthors()
    {
        $contextTable = $this->getContextTable();
        $contextSettingsTable = $this->getContextSettingsTable();
        $contextIdColumn = $this->getContextIdColumn();

        // Validasi nama tabel dan kolom untuk mencegah SQL injection
        $this->validateTableAndColumnNames($contextTable, $contextSettingsTable, $contextIdColumn);

        // Menggunakan chunk untuk menghindari memory issues dengan data besar
        DB::table($contextTable)
            ->select($contextIdColumn)
            ->chunkById(100, function ($contexts) use ($contextSettingsTable, $contextIdColumn) {
                $insertData = [];
                
                foreach ($contexts as $context) {
                    $insertData[] = [
                        $contextIdColumn => $context->{$contextIdColumn},
                        'setting_name' => 'notifyAllAuthors',
                        'setting_value' => '1',
                    ];
                }

                // Insert data secara batch
                if (!empty($insertData)) {
                    DB::table($contextSettingsTable)->insert($insertData);
                }
            }, $contextIdColumn);
    }

    /**
     * Delete the new context setting "notifyAllAuthors"
     */
    protected function downNotifyAllAuthors()
    {
        $contextSettingsTable = $this->getContextSettingsTable();

        // Validasi nama tabel
        $allowedSettingsTables = ['journal_settings', 'press_settings', 'context_settings'];
        if (!in_array($contextSettingsTable, $allowedSettingsTables, true)) {
            throw new \RuntimeException("Invalid context settings table: {$contextSettingsTable}");
        }

        DB::table($contextSettingsTable)
            ->where('setting_name', 'notifyAllAuthors')
            ->delete();
    }

    /**
     * Validate table and column names to prevent SQL injection
     */
    private function validateTableAndColumnNames(string $contextTable, string $contextSettingsTable, string $contextIdColumn): void
    {
        $allowedContextTables = ['journals', 'presses', 'contexts'];
        $allowedSettingsTables = ['journal_settings', 'press_settings', 'context_settings'];
        $allowedContextColumns = ['journal_id', 'press_id', 'context_id'];

        if (!in_array($contextTable, $allowedContextTables, true)) {
            throw new \RuntimeException("Invalid context table: {$contextTable}");
        }

        if (!in_array($contextSettingsTable, $allowedSettingsTables, true)) {
            throw new \RuntimeException("Invalid context settings table: {$contextSettingsTable}");
        }

        if (!in_array($contextIdColumn, $allowedContextColumns, true)) {
            throw new \RuntimeException("Invalid context column: {$contextIdColumn}");
        }
    }

    abstract protected function getContextTable(): string;
    abstract protected function getContextSettingsTable(): string;
    abstract protected function getContextIdColumn(): string;
}