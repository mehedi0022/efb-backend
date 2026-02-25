<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('productsizes')) {
            return;
        }

        if (!Schema::hasColumn('productsizes', 'is_default')) {
            Schema::table('productsizes', function (Blueprint $table) {
                $table->boolean('is_default')->default(false)->after('sort_order');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // Ensure at most one default row per product; if none exists, set the first row as default.
        DB::statement(
            'UPDATE productsizes ps
            INNER JOIN (
                SELECT product_id, MIN(id) AS min_id
                FROM productsizes
                WHERE product_id IS NOT NULL
                GROUP BY product_id
            ) grouped ON grouped.product_id = ps.product_id
            SET ps.is_default = CASE WHEN ps.id = grouped.min_id THEN 1 ELSE 0 END
            WHERE grouped.product_id IS NOT NULL
              AND grouped.product_id NOT IN (
                    SELECT product_id
                    FROM (
                        SELECT product_id
                        FROM productsizes
                        WHERE is_default = 1 AND product_id IS NOT NULL
                        GROUP BY product_id
                    ) existing_defaults
                )'
        );

        DB::statement(
            'UPDATE productsizes ps
            INNER JOIN (
                SELECT product_id, MIN(id) AS keep_id
                FROM productsizes
                WHERE is_default = 1 AND product_id IS NOT NULL
                GROUP BY product_id
            ) keepers ON keepers.product_id = ps.product_id
            SET ps.is_default = CASE WHEN ps.id = keepers.keep_id THEN 1 ELSE 0 END
            WHERE ps.product_id IS NOT NULL'
        );

        $indexExists = DB::selectOne(
            'SELECT COUNT(1) AS aggregate_count
             FROM information_schema.statistics
             WHERE table_schema = DATABASE()
               AND table_name = ?
               AND index_name = ?',
            ['productsizes', 'productsizes_product_default_idx']
        );

        if ((int) ($indexExists->aggregate_count ?? 0) === 0) {
            DB::statement('CREATE INDEX productsizes_product_default_idx ON productsizes (product_id, is_default)');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('productsizes')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            $indexExists = DB::selectOne(
                'SELECT COUNT(1) AS aggregate_count
                 FROM information_schema.statistics
                 WHERE table_schema = DATABASE()
                   AND table_name = ?
                   AND index_name = ?',
                ['productsizes', 'productsizes_product_default_idx']
            );

            if ((int) ($indexExists->aggregate_count ?? 0) > 0) {
                DB::statement('DROP INDEX productsizes_product_default_idx ON productsizes');
            }
        }

        if (Schema::hasColumn('productsizes', 'is_default')) {
            Schema::table('productsizes', function (Blueprint $table) {
                $table->dropColumn('is_default');
            });
        }
    }
};
