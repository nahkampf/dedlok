<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Forty pieces of deck hardware: storage, cheap and mid CPUs, a QDRAM stub, a second tier-1 NPU, and the Horizon, XGate and PEI
 * cards, each on the shelf at Chrome Palace at list price, five at a time.
 *
 * Flavour text is a placeholder, to be edited in the admin. Every step skips what already exists by slug, so a copy someone
 * has authored by hand is left alone.
 */
return new class extends Migration
{
    private const FLAVOR = 'This is placeholder text, to be edited.';

    public function up(): void
    {
        $palace = DB::table('locations')->where('slug', 'chrome_palace')->value('id');
        $now = now();

        foreach ($this->catalogue() as [$slug, $name, $hardware, $tier, $rarity, $cost, $variant, $draw, $heat, $measure, $tolerance, $modifiers]) {
            $itemId = DB::table('items')->where('slug', $slug)->value('id') ?? DB::table('items')->insertGetId([
                'slug' => $slug,
                'name' => $name,
                'flavor' => self::FLAVOR,
                'container' => 'physical',
                'tier' => $tier,
                'rarity' => $rarity,
                'stackable' => false,
                'weight' => 0.5,
                'size' => 1.0,
                'hardware' => $hardware,
                'base_cost' => $cost,
                'on_market' => true,
                'seed_quantity' => ['common' => 100, 'uncommon' => 40, 'rare' => 10][$rarity],
                'modifiers' => $modifiers === null ? null : json_encode($modifiers),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('deck_modules')->insertOrIgnore([
                'item_id' => $itemId,
                'variant' => $variant,
                'power_draw' => $draw,
                'thermal_load' => $heat,
                'qflops' => $measure['qflops'] ?? null,
                'quantabits' => $measure['quantabits'] ?? null,
                'cooling' => $measure['cooling'] ?? null,
                'storage' => $measure['storage'] ?? null,
                'thermal_warning' => $tolerance[0] ?? null,
                'thermal_limit' => $tolerance[1] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // A NULL price is the item's base_cost. Five on the shelf, one back every two hours, like the shop's other five-shelf listings.
            if ($palace !== null) {
                DB::table('shop_items')->insertOrIgnore([
                    'location_id' => $palace,
                    'item_id' => $itemId,
                    'price' => null,
                    'max_stock' => 5,
                    'stock' => 5,
                    'restock_amount' => 1,
                    'restock_hours' => 2,
                    'restocked_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** Removes the items (and with them their module rows and shelf listings, by cascade). Refuses nothing: a copy a player owns would block the delete. */
    public function down(): void
    {
        DB::table('items')->whereIn('slug', array_column($this->catalogue(), 0))->delete();
    }

    /** @return list<array{string, string, string, int, string, int, ?string, int, int, array<string, int|float>, list<int>, ?list<array<string, mixed>>}> */
    private function catalogue(): array
    {
        $pct = fn (string $stat, int $amount): array => [['op' => 'percentage_of_base', 'amount' => $amount, 'target' => ['id' => $stat]]];
        $add = fn (string $stat, int $amount): array => [['op' => 'add', 'amount' => $amount, 'target' => ['id' => $stat]]];
        $crafting = [['op' => 'percentage_of_base', 'amount' => -25, 'target' => ['all_of' => ['crafting_time']]]];
        $si = 'damage.synaptic_integrity.ice';
        $hp = 'damage.hitpoints.ice';

        return [
            // Storage. The first of each pair at 64, 256 and 896 is dense and hot; the second cool and dearer.
            ['yaan_kaifa_microdrive', 'Yaan-Kaifa Microdrive', 'storage', 1, 'common', 150, null, 2, 1, ['storage' => 16], [], null],
            ['pearl_md32', 'Pearl MD32', 'storage', 1, 'common', 250, null, 3, 2, ['storage' => 32], [], null],
            ['ryujin_hyperstore_64', 'Ryujin Hyperstore 64', 'storage', 1, 'common', 360, null, 5, 4, ['storage' => 64], [], null],
            ['shin_etsu_dsd64', 'Shin-Etsu DSD-64', 'storage', 1, 'common', 540, null, 3, 2, ['storage' => 64], [], null],
            ['pearl_md128', 'Pearl MD128', 'storage', 1, 'common', 750, null, 5, 3, ['storage' => 128], [], null],
            ['rigol_ms256', 'RIGOL MS/256', 'storage', 1, 'common', 960, null, 7, 5, ['storage' => 256], [], null],
            ['jxd_ultradrive_256', 'JXD UltraDrive 256', 'storage', 1, 'common', 1440, null, 5, 3, ['storage' => 256], [], null],
            ['hengdian_s_vault', 'Hengdian S-Vault', 'storage', 1, 'uncommon', 2200, null, 7, 5, ['storage' => 512], [300, 420], null],
            ['rigol_ms624', 'RIGOL MS/624', 'storage', 2, 'rare', 6000, null, 7, 5, ['storage' => 624], [600, 840], null],
            ['pearl_umd896', 'Pearl UMD896', 'storage', 2, 'rare', 7200, null, 9, 6, ['storage' => 896], [700, 980], null],
            ['jxd_ultradrive_896', 'JXD UltraDrive 896', 'storage', 2, 'rare', 10800, null, 7, 4, ['storage' => 896], [700, 980], null],
            ['changhong_xiaoma_qd1k', 'Changhong Xiaoma QD1K', 'storage', 2, 'rare', 12000, null, 8, 5, ['storage' => 1024], [800, 1120], null],
            ['hyperion_randstad_md1600', 'Hyperion Randstad MD1600', 'storage', 2, 'rare', 22000, null, 10, 7, ['storage' => 1640], [1000, 1400], null],

            // CPU, RAM, NPU
            ['syntronix_cayla', 'Syntronix Cayla', 'cpu', 1, 'common', 6500, 'socket_k', 300, 165, ['qflops' => 18], [380, 540], null],
            ['jxd_k9820_b', 'JXD K9820/B', 'cpu', 1, 'uncommon', 10000, 'socket_k', 330, 182, ['qflops' => 22], [400, 570], null],
            ['syntronix_lumina', 'Syntronix Lumina', 'cpu', 2, 'uncommon', 48000, 'socket_z', 560, 308, ['qflops' => 38], [1100, 1550], null],
            ['vandertek_cristal_iii', 'VanderTek Cristal-III', 'cpu', 2, 'rare', 70000, 'socket_z', 640, 352, ['qflops' => 48], [1250, 1750], null],
            ['yaan_kaifa_qd4b2', 'Yaan-Kaifa QD4B2', 'ram', 2, 'uncommon', 8500, 'qdram', 24, 8, ['quantabits' => 4], [300, 430], null],
            ['nexura_oracle_66', 'Nexura Oracle 66', 'npu', 1, 'uncommon', 9000, null, 80, 48, ['qflops' => 9], [270, 380], null],

            // Horizon
            ['shin_etsu_fpu9', 'Shin-Etsu FPU-9', 'expansion', 2, 'rare', 30000, 'horizon', 55, 30, ['qflops' => 6], [], null],
            ['pearl_xr6', 'Pearl XR6', 'expansion', 2, 'rare', 12000, 'horizon', 25, 9, ['quantabits' => 6], [], null],
            ['jxd_ramstick_64', 'JXD RAMStick 64', 'expansion', 2, 'rare', 80000, 'horizon', 75, 26, ['quantabits' => 64], [], null],
            ['voltaique_sp980', 'Voltaïque SP-980', 'expansion', 2, 'rare', 28000, 'horizon', 8, 4, [], [], $pct('stealth', 20)],
            ['kurogane_bbx_7a', 'Kurogane BBX-7A', 'expansion', 2, 'rare', 18000, 'horizon', 6, 3, [], [], $pct('deck_bandwidth', 8)],
            ['silicon_alpin_rad33', 'Silicon-Alpin RAD33', 'expansion', 2, 'rare', 5500, 'horizon', 14, 0, ['cooling' => 33], [], null],
            ['syntronix_atlas_ii', 'Syntronix Atlas II', 'expansion', 2, 'rare', 22000, 'horizon', 7, 3, [], [], $pct('decryption_cycle_seconds', -15)],
            ['vandertek_aanval', 'VanderTek Aanval', 'expansion', 2, 'rare', 26000, 'horizon', 9, 5, [], [], $add('icebreaker_damage', 6)],
            ['irqx_n82_katchu', 'IRQX N82 Katchū', 'expansion', 2, 'rare', 24000, 'horizon', 8, 4, [], [], $add($si, -2)],
            ['bni_scanmate', 'BNI ScanMate', 'expansion', 2, 'rare', 20000, 'horizon', 7, 3, [], [], $pct('scan_duration', -25)],
            ['syntronix_meiko', 'Syntronix Meikō', 'expansion', 2, 'rare', 32000, 'horizon', 8, 4, [], [], $crafting],

            // XGate
            ['shin_etsu_xr5', 'Shin-Etsu XR5', 'expansion', 1, 'uncommon', 4000, 'xgate', 12, 4, ['quantabits' => 5], [], null],
            ['kurogane_hk_4p', 'Kurogane HK-4P', 'expansion', 1, 'uncommon', 9000, 'xgate', 5, 2, [], [], $pct('scan_duration', -20)],
            ['voltaique_sx550', 'Voltaïque SX-550', 'expansion', 1, 'uncommon', 14000, 'xgate', 6, 3, [], [], $pct('stealth', 15)],
            ['kurogane_tr_69', 'Kurogane TR-69', 'expansion', 1, 'uncommon', 2800, 'xgate', 8, 0, ['cooling' => 20], [], null],

            // PEI
            ['jxd_sidenote_i', 'JXD SideNote I', 'expansion', 2, 'rare', 5000, 'pei', 14, 5, ['quantabits' => 5], [], null],
            ['vandertek_peiling', 'VanderTek Peiling', 'expansion', 2, 'rare', 12000, 'pei', 6, 3, [], [], $pct('scan_duration', -20)],
            ['aegis_moskva_skrytnost', 'Aegis-Moskva SKRYTNOST', 'expansion', 2, 'rare', 18000, 'pei', 8, 4, [], [], $pct('stealth', 15)],
            ['cooltech_p200', 'Cooltech P200', 'expansion', 2, 'rare', 4000, 'pei', 12, 0, ['cooling' => 27], [], null],
            ['nexura_coda', 'Nexura CODA', 'expansion', 2, 'rare', 7000, 'pei', 7, 3, [], [], $add($si, -2)],
            ['hrx_paloma_4', 'HRX Paloma-4', 'expansion', 2, 'rare', 9000, 'pei', 9, 4, [], [], $add($hp, -3)],
        ];
    }
};
