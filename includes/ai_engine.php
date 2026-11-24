<?php
declare(strict_types=1);

/**
 * BrewStock AI Prediction & Zero-Waste Engine
 * Implements the 3-stage pipeline:
 * 1. Input Data (POS, Stock & Resep, Prakiraan Cuaca)
 * 2. Proses AI (Analisis Pola Cuaca/Weekend, Konversi Resep, Deteksi Risiko)
 * 3. Output Sistem (Takaran Belanja, Status Merah/Kuning/Hijau, Zero Waste Alert)
 */

function get_weather_settings(PDO $pdo): array
{
    $row = $pdo->query('SELECT * FROM weather_settings WHERE id = 1')->fetch();
    if (!$row) {
        return [
            'id' => 1,
            'tomorrow_weather' => 'panas',
            'is_weekend' => 0,
            'event_note' => 'Operasional Normal',
            'temperature_c' => 33,
            'updated_by' => 'System',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
    }
    return $row;
}

function calculate_weather_multiplier(string $sensitivity, string $weather, bool $isWeekend): array
{
    $weatherPct = 0;
    if ($sensitivity === 'panas_boost') {
        $weatherPct = match ($weather) {
            'panas' => 40,
            'hujan' => -25,
            default => 0,
        };
    } elseif ($sensitivity === 'hujan_boost') {
        $weatherPct = match ($weather) {
            'hujan' => 25,
            'panas' => -15,
            default => 0,
        };
    }

    $weekendPct = $isWeekend ? 25 : 0;
    $weatherFactor = 1 + ($weatherPct / 100);
    $weekendFactor = 1 + ($weekendPct / 100);
    $combinedFactor = $weatherFactor * $weekendFactor;
    $totalPct = (int)round(($combinedFactor - 1) * 100);

    return [
        'weather_pct' => $weatherPct,
        'weekend_pct' => $weekendPct,
        'factor' => $combinedFactor,
        'total_pct' => $totalPct,
    ];
}

function run_brewstock_predictions(
    PDO $pdo,
    ?string $overrideWeather = null,
    ?bool $overrideWeekend = null
): array {
    $weatherRow = get_weather_settings($pdo);
    $weather = $overrideWeather ?? (string)$weatherRow['tomorrow_weather'];
    $isWeekend = $overrideWeekend ?? ((int)$weatherRow['is_weekend'] === 1);

    // Aggregate today's POS consumption per ingredient via menu_recipes
    $today = date('Y-m-d');
    $posUsageStmt = $pdo->prepare("
        SELECT mr.ingredient_id, SUM(ps.qty * mr.qty_per_portion) AS used_today
        FROM pos_sales ps
        JOIN menu_recipes mr ON mr.menu_id = ps.menu_id
        WHERE DATE(ps.sold_at) = ?
        GROUP BY mr.ingredient_id
    ");
    $posUsageStmt->execute([$today]);
    $posUsageMap = [];
    foreach ($posUsageStmt->fetchAll() as $u) {
        $posUsageMap[(int)$u['ingredient_id']] = (float)$u['used_today'];
    }

    $rows = $pdo->query("
        SELECT i.*, s.name AS supplier_name, s.phone AS supplier_phone, s.lead_time_days
        FROM ingredients i
        LEFT JOIN suppliers s ON s.id = i.supplier_id
        ORDER BY i.id ASC
    ")->fetchAll();

    $predictions = [];
    $counts = ['merah' => 0, 'kuning' => 0, 'hijau' => 0, 'waste_alert' => 0];
    $totalEstimatedCost = 0;
    $totalInventoryValue = 0;

    foreach ($rows as $ing) {
        $id = (int)$ing['id'];
        $currentStock = (float)$ing['current_stock'];
        $baseline = (float)$ing['daily_baseline'];
        $minThreshold = (float)$ing['min_threshold'];
        $safeThreshold = (float)$ing['safe_threshold'];
        $shelfLife = (int)$ing['shelf_life_days'];
        $isPerishable = (int)$ing['is_perishable'] === 1;
        $unitPrice = (int)$ing['unit_price'];
        $usedToday = $posUsageMap[$id] ?? 0.0;

        $mult = calculate_weather_multiplier((string)$ing['weather_sensitivity'], $weather, $isWeekend);
        $predictedNeed = round($baseline * $mult['factor'], 2);

        // Zero-waste buffer rule: perishable items (<= 3 days) get exact net need without excess buffer
        $bufferMultiplier = ($isPerishable && $shelfLife <= 3) ? 1.00 : 1.10;
        $targetNeed = round($predictedNeed * $bufferMultiplier, 2);

        // If exact perishable item matches baseline example (e.g., Susu 3.5 - 2.0 = 1.5), keep clean net recommendation
        $rawDeficit = $targetNeed - $currentStock;
        $recommendedBuy = $rawDeficit > 0 ? round($rawDeficit, 2) : 0.0;

        // Status Classification: Merah (Kritis), Kuning (Hampir Habis), Hijau (Aman)
        if ($currentStock < ($predictedNeed * 0.72) || ($currentStock <= $minThreshold && $recommendedBuy > 0)) {
            $status = 'merah';
            $statusLabel = 'Kritis';
        } elseif ($recommendedBuy > 0 || $currentStock < ($predictedNeed * 1.20) || $currentStock <= $minThreshold) {
            $status = 'kuning';
            $statusLabel = 'Hampir Habis';
        } else {
            $status = 'hijau';
            $statusLabel = 'Aman';
        }

        // Overstock / Zero Waste Risk Detection
        $wasteRisk = 'rendah';
        $wasteNote = 'Stok seimbang dengan proyeksi konsumsi.';
        if ($isPerishable && $predictedNeed > 0) {
            $maxHealthy = $predictedNeed * ($shelfLife <= 2 ? 1.35 : 1.80);
            if ($currentStock > $maxHealthy) {
                $wasteRisk = 'tinggi';
                $excess = round($currentStock - $predictedNeed, 2);
                $wasteNote = "Potensi overstock +{$excess} {$ing['unit']}! Buat promo menu agar tidak basi (umur simpan {$shelfLife} hari).";
                $counts['waste_alert']++;
            } elseif ($currentStock > $predictedNeed * 1.25 && $shelfLife <= 3) {
                $wasteRisk = 'sedang';
                $wasteNote = "Stok cukup untuk hari ini & besok. Tahan order baru demi zero waste.";
            }
        }

        $estimatedCost = (int)round($recommendedBuy * $unitPrice);
        $totalEstimatedCost += $estimatedCost;
        $totalInventoryValue += (int)round($currentStock * $unitPrice);
        $counts[$status]++;

        // Human-readable AI insight string
        $pctSign = $mult['total_pct'] >= 0 ? '+' : '';
        $insight = "Permintaan diprediksi {$pctSign}{$mult['total_pct']}% ({$predictedNeed} {$ing['unit']}). ";
        if ($recommendedBuy > 0) {
            $insight .= "Rekomendasi beli {$recommendedBuy} {$ing['unit']} segera.";
        } else {
            $insight .= "Stok mencukupi, tidak perlu belanja.";
        }

        $predictions[] = [
            'id' => $id,
            'name' => $ing['name'],
            'category' => $ing['category'],
            'unit' => $ing['unit'],
            'current_stock' => $currentStock,
            'daily_baseline' => $baseline,
            'used_today_pos' => round($usedToday, 2),
            'min_threshold' => $minThreshold,
            'safe_threshold' => $safeThreshold,
            'shelf_life_days' => $shelfLife,
            'is_perishable' => $isPerishable,
            'weather_sensitivity' => $ing['weather_sensitivity'],
            'unit_price' => $unitPrice,
            'supplier_id' => $ing['supplier_id'] ? (int)$ing['supplier_id'] : null,
            'supplier_name' => $ing['supplier_name'] ?? 'Belum ditautkan',
            'supplier_phone' => $ing['supplier_phone'] ?? '-',
            'weather_pct' => $mult['weather_pct'],
            'weekend_pct' => $mult['weekend_pct'],
            'total_pct' => $mult['total_pct'],
            'predicted_need' => $predictedNeed,
            'recommended_buy' => $recommendedBuy,
            'estimated_cost' => $estimatedCost,
            'status' => $status,
            'status_label' => $statusLabel,
            'waste_risk' => $wasteRisk,
            'waste_note' => $wasteNote,
            'ai_insight' => $insight,
        ];
    }

    // Sort: Merah first, then Kuning, then Hijau
    $orderMap = ['merah' => 0, 'kuning' => 1, 'hijau' => 2];
    usort($predictions, static function (array $a, array $b) use ($orderMap): int {
        $cmp = $orderMap[$a['status']] <=> $orderMap[$b['status']];
        if ($cmp !== 0) {
            return $cmp;
        }
        return $b['recommended_buy'] <=> $a['recommended_buy'];
    });

    return [
        'weather' => [
            'condition' => $weather,
            'is_weekend' => $isWeekend,
            'temperature_c' => (int)$weatherRow['temperature_c'],
            'event_note' => $weatherRow['event_note'],
            'updated_at' => $weatherRow['updated_at'],
        ],
        'summary' => [
            'counts' => $counts,
            'total_items' => count($predictions),
            'total_estimated_cost' => $totalEstimatedCost,
            'total_inventory_value' => $totalInventoryValue,
        ],
        'items' => $predictions,
    ];
}
