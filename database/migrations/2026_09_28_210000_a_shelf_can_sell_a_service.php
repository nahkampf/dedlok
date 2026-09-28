<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A shelf row may be a SERVICE rather than an item — and Lockjaw's first one.
 *
 * `item_id` becomes nullable and a row names either an item or an
 * `App\Standing\Service`, never both and never neither. That rule is NOT a
 * CHECK: MySQL refuses a CHECK on a column whose foreign key has a
 * referential action (error 3823), and `item_id` cascades. So
 * `EntityOfferRequest` holds it, and the only CHECK here is the one that
 * reaches no foreign key — a service and its amount are set together.
 *
 * Lockjaw's offer arrives with the schema, the way Rewire and Egress
 * Protocols did: 1000 loyalty for ten points of heat, at every desk they keep.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entity_offers', function (Blueprint $table): void {
            $table->foreignId('item_id')
                ->nullable()
                ->comment('The catalogue row handed over, or NULL when the row is a service. An ordinary item, usually with on_market false - this table is a second door onto the catalogue rather than a second catalogue.')
                ->change();

            $table->string('service', 32)
                ->nullable()
                ->after('item_id')
                ->comment('What the entity DOES instead of handing an item over, as App\Standing\Service. NULL on an item row; exactly one of this and item_id is set, which the admin request enforces.');

            $table->decimal('service_amount', 6, 1)
                ->unsigned()
                ->nullable()
                ->after('service')
                ->comment('How much one purchase of the service does - for shed_heat, the points of heat taken off. NULL exactly when service is.');

            // One row per service per entity, for the reason uq_entity_offers_item
            // gives: two would be two prices at one desk.
            $table->unique(['entity_id', 'service'], 'uq_entity_offers_service');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `entity_offers` ADD CONSTRAINT `chk_entity_offers_service` CHECK ('
                .'(`service` IS NULL AND `service_amount` IS NULL) '
                .'OR (`service` IS NOT NULL AND `service_amount` > 0))'
            );
        }

        $lockjaw = DB::table('entities')->where('name', 'Lockjaw')->value('id');

        if ($lockjaw !== null) {
            DB::table('entity_offers')->insertOrIgnore([
                'entity_id' => $lockjaw,
                'min_grade' => 1,
                'max_grade' => 5,
                'item_id' => null,
                'service' => 'shed_heat',
                'service_amount' => 10,
                'loyalty_price' => 1000,
                'min_standing' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('entity_offers')->whereNotNull('service')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE `entity_offers` DROP CHECK `chk_entity_offers_service`');
        }

        Schema::table('entity_offers', function (Blueprint $table): void {
            $table->dropUnique('uq_entity_offers_service');
            $table->dropColumn(['service', 'service_amount']);
            $table->foreignId('item_id')
                ->nullable(false)
                ->comment('The catalogue row handed over. An ordinary item, usually with on_market false - this table is a second door onto the catalogue rather than a second catalogue.')
                ->change();
        });
    }
};
