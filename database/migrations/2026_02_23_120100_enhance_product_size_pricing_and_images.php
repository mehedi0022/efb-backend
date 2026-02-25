<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->enhanceProductSizesTable();
        $this->enhanceProductImagesTable();
    }

    public function down(): void
    {
        if (Schema::hasTable('productsizes') && DB::getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('productsizes', 'productsizes_product_id_fk');
            $this->dropForeignKeyIfExists('productsizes', 'productsizes_size_id_fk');
        }

        if (Schema::hasTable('productsizes')) {
            $hasSizeName = Schema::hasColumn('productsizes', 'size_name');
            $hasPrice = Schema::hasColumn('productsizes', 'price');
            $hasSortOrder = Schema::hasColumn('productsizes', 'sort_order');

            Schema::table('productsizes', function (Blueprint $table) use ($hasSizeName, $hasPrice, $hasSortOrder) {
                if ($hasSortOrder) {
                    $table->dropColumn('sort_order');
                }
                if ($hasPrice) {
                    $table->dropColumn('price');
                }
                if ($hasSizeName) {
                    $table->dropColumn('size_name');
                }
            });
        }

        if (Schema::hasTable('productimages') && DB::getDriverName() === 'mysql') {
            $this->dropForeignKeyIfExists('productimages', 'productimages_product_id_fk');
        }

        if (Schema::hasTable('productimages')) {
            $hasIsFeature = Schema::hasColumn('productimages', 'is_feature');
            $hasSortOrder = Schema::hasColumn('productimages', 'sort_order');

            Schema::table('productimages', function (Blueprint $table) use ($hasIsFeature, $hasSortOrder) {
                if ($hasSortOrder) {
                    $table->dropColumn('sort_order');
                }
                if ($hasIsFeature) {
                    $table->dropColumn('is_feature');
                }
            });
        }
    }

    private function enhanceProductSizesTable(): void
    {
        if (!Schema::hasTable('productsizes')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE productsizes MODIFY product_id BIGINT UNSIGNED NULL');
            DB::statement('ALTER TABLE productsizes MODIFY size_id INT UNSIGNED NULL');
        }

        $hasSizeName = Schema::hasColumn('productsizes', 'size_name');
        $hasPrice = Schema::hasColumn('productsizes', 'price');
        $hasSortOrder = Schema::hasColumn('productsizes', 'sort_order');

        Schema::table('productsizes', function (Blueprint $table) use ($hasSizeName, $hasPrice, $hasSortOrder) {
            if (!$hasSizeName) {
                $table->string('size_name', 100)->nullable()->after('size_id');
            }
            if (!$hasPrice) {
                $table->decimal('price', 10, 2)->nullable()->after('size_name');
            }
            if (!$hasSortOrder) {
                $table->unsignedInteger('sort_order')->default(0)->after('price');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                "UPDATE productsizes ps
                LEFT JOIN sizes s ON s.id = ps.size_id
                SET ps.size_name = COALESCE(NULLIF(TRIM(ps.size_name), ''), s.sizeName)
                WHERE ps.size_name IS NULL OR TRIM(ps.size_name) = ''"
            );

            DB::statement(
                'UPDATE productsizes ps
                INNER JOIN products p ON p.id = ps.product_id
                SET ps.price = p.new_price
                WHERE ps.price IS NULL'
            );

            DB::statement(
                'DELETE ps FROM productsizes ps
                LEFT JOIN products p ON p.id = ps.product_id
                WHERE ps.product_id IS NOT NULL AND p.id IS NULL'
            );

            DB::statement(
                'UPDATE productsizes ps
                LEFT JOIN sizes s ON s.id = ps.size_id
                SET ps.size_id = NULL
                WHERE ps.size_id IS NOT NULL AND s.id IS NULL'
            );

            if (!$this->hasIndex('productsizes', 'productsizes_product_id_idx')) {
                DB::statement('CREATE INDEX productsizes_product_id_idx ON productsizes (product_id)');
            }

            if (!$this->hasIndex('productsizes', 'productsizes_size_id_idx')) {
                DB::statement('CREATE INDEX productsizes_size_id_idx ON productsizes (size_id)');
            }

            if (!$this->hasForeignKey('productsizes', 'productsizes_product_id_fk')) {
                DB::statement(
                    'ALTER TABLE productsizes
                    ADD CONSTRAINT productsizes_product_id_fk
                    FOREIGN KEY (product_id) REFERENCES products(id)
                    ON DELETE CASCADE'
                );
            }

            if (!$this->hasForeignKey('productsizes', 'productsizes_size_id_fk')) {
                DB::statement(
                    'ALTER TABLE productsizes
                    ADD CONSTRAINT productsizes_size_id_fk
                    FOREIGN KEY (size_id) REFERENCES sizes(id)
                    ON DELETE SET NULL'
                );
            }
        }
    }

    private function enhanceProductImagesTable(): void
    {
        if (!Schema::hasTable('productimages')) {
            return;
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE productimages MODIFY product_id BIGINT UNSIGNED NULL');
        }

        $hasIsFeature = Schema::hasColumn('productimages', 'is_feature');
        $hasSortOrder = Schema::hasColumn('productimages', 'sort_order');

        Schema::table('productimages', function (Blueprint $table) use ($hasIsFeature, $hasSortOrder) {
            if (!$hasIsFeature) {
                $table->boolean('is_feature')->default(false)->after('product_id');
            }
            if (!$hasSortOrder) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_feature');
            }
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'DELETE pi FROM productimages pi
                LEFT JOIN products p ON p.id = pi.product_id
                WHERE pi.product_id IS NOT NULL AND p.id IS NULL'
            );

            DB::statement(
                'UPDATE productimages pi
                INNER JOIN (
                    SELECT
                        product_id,
                        MIN(id) AS first_image_id,
                        MAX(CASE WHEN is_feature = 1 THEN 1 ELSE 0 END) AS has_feature
                    FROM productimages
                    WHERE product_id IS NOT NULL
                    GROUP BY product_id
                ) grouped ON grouped.product_id = pi.product_id
                SET pi.is_feature = CASE
                    WHEN grouped.has_feature = 1 THEN pi.is_feature
                    WHEN pi.id = grouped.first_image_id THEN 1
                    ELSE 0
                END'
            );

            if (!$this->hasIndex('productimages', 'productimages_product_id_idx')) {
                DB::statement('CREATE INDEX productimages_product_id_idx ON productimages (product_id)');
            }

            if (!$this->hasIndex('productimages', 'productimages_product_feature_idx')) {
                DB::statement('CREATE INDEX productimages_product_feature_idx ON productimages (product_id, is_feature)');
            }

            if (!$this->hasForeignKey('productimages', 'productimages_product_id_fk')) {
                DB::statement(
                    'ALTER TABLE productimages
                    ADD CONSTRAINT productimages_product_id_fk
                    FOREIGN KEY (product_id) REFERENCES products(id)
                    ON DELETE CASCADE'
                );
            }
        }
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        $result = DB::selectOne(
            'SELECT COUNT(1) AS aggregate_count
            FROM information_schema.statistics
            WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?',
            [$table, $indexName]
        );

        return (int) ($result->aggregate_count ?? 0) > 0;
    }

    private function hasForeignKey(string $table, string $constraintName): bool
    {
        if (DB::getDriverName() !== 'mysql') {
            return false;
        }

        $result = DB::selectOne(
            'SELECT COUNT(1) AS aggregate_count
            FROM information_schema.referential_constraints
            WHERE constraint_schema = DATABASE()
              AND table_name = ?
              AND constraint_name = ?',
            [$table, $constraintName]
        );

        return (int) ($result->aggregate_count ?? 0) > 0;
    }

    private function dropForeignKeyIfExists(string $table, string $constraintName): void
    {
        if (!$this->hasForeignKey($table, $constraintName)) {
            return;
        }

        DB::statement(sprintf('ALTER TABLE %s DROP FOREIGN KEY %s', $table, $constraintName));
    }
};
