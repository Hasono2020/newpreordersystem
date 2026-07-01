<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ShippingArea;
use App\Models\Trip;
use Illuminate\Support\Facades\DB;

class OrderImportService
{
    use \App\Traits\HandlesXlsx;

    /**
     * Read the xlsx file and run the full validation pass (no DB writes).
     * Returns ['rows' => array, 'errors' => array]. If errors is non-empty,
     * the rows must NOT be imported.
     */
    public function readAndValidate(string $filePath, Trip $trip): array
    {
        $rows = $this->readXlsx($filePath);
        if (empty($rows)) {
            return ['rows' => [], 'errors' => ['Could not read the file. Make sure it is a valid .xlsx file.']];
        }

        array_shift($rows); // remove header

        // Remove completely blank rows
        $rows = array_values(array_filter($rows, fn($r) =>
            !empty(trim((string) ($r[2] ?? ''))) || !empty(trim((string) ($r[6] ?? '')))
        ));

        if (empty($rows)) {
            return ['rows' => [], 'errors' => ['The file has no data rows.']];
        }

        $products = Product::where('trip_id', $trip->id)
            ->with('variants')
            ->get()
            ->keyBy(fn($p) => strtoupper($p->product_code ?? ''));

        $csAgents = \App\Models\CsAgent::all()
            ->keyBy(fn($a) => strtolower(trim($a->name)));

        $errors = [];
        foreach ($rows as $i => $row) {
            $lineNum = $i + 2;
            $name    = trim((string) ($row[2] ?? ''));
            $csName  = trim((string) ($row[3] ?? ''));
            $code    = strtoupper(trim((string) ($row[6] ?? '')));
            $color   = trim((string) ($row[7] ?? ''));
            $size    = trim((string) ($row[8] ?? ''));

            if (empty($name) && empty($code)) continue;

            if (empty($name)) {
                $errors[] = "Row {$lineNum}: Name is required.";
                continue;
            }
            if (empty($csName)) {
                $errors[] = "Row {$lineNum} ({$name}): IG/WA (Customer Service) is required.";
                continue;
            }
            if (!isset($csAgents[strtolower($csName)])) {
                $errors[] = "Row {$lineNum} ({$name}): CS agent '{$csName}' not found. Add them in CS Agents first.";
                continue;
            }
            if (empty($code)) {
                $errors[] = "Row {$lineNum} ({$name}): Product Code is required.";
                continue;
            }

            $product = $products->get($code);
            if (!$product) {
                $errors[] = "Row {$lineNum} ({$name}): Code '{$code}' not found in trip '{$trip->name}'.";
                continue;
            }

            if ($color || $size) {
                $variantFound = $product->variants->first(function ($v) use ($color, $size) {
                    $colorMatch = !$color || strtolower($v->color ?? '') === strtolower($color);
                    $sizeMatch  = !$size  || strtolower($v->size  ?? '') === strtolower($size);
                    return $colorMatch && $sizeMatch;
                });
                if (!$variantFound) {
                    $errors[] = "Row {$lineNum} ({$name}): Variant '{$color}/{$size}' not found for code '{$code}'.";
                }
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Import already-validated rows. Returns ['imported' => int, 'skipped' => int].
     */
    public function importRows(array $rows, Trip $trip, int $createdBy): array
    {
        $products = Product::where('trip_id', $trip->id)
            ->with('variants')
            ->get()
            ->keyBy(fn($p) => strtoupper($p->product_code ?? ''));

        $shippingAreas     = ShippingArea::all()->keyBy(fn($a) => strtolower(trim($a->name)));
        $shippingAreasById = $shippingAreas->keyBy('id');

        $csAgents = \App\Models\CsAgent::all()->keyBy(fn($a) => strtolower(trim($a->name)));

        $existingPhones = DB::table('customers')->whereNotNull('phone')
            ->pluck('id', 'phone')
            ->mapWithKeys(fn($id, $p) => [strtolower(trim($p)) => $id])->toArray();

        $existingNames = DB::table('customers')->pluck('id', 'name')
            ->mapWithKeys(fn($id, $n) => [strtolower(trim($n)) => $id])->toArray();

        $customerTypes = DB::table('customers')->pluck('type', 'id')->toArray();

        $promoRules = \App\Models\PromoRule::where('is_active', true)
            ->where(fn($q) => $q->where('trip_id', $trip->id)->orWhereNull('trip_id'))
            ->orderByDesc('min_items')
            ->get();

        $imported = 0;
        $skipped  = 0;
        $baseTime = now();
        $now      = $baseTime->toDateTimeString();

        $customerAreas = []; // customerId => resolved shipping_area_id, so blank-KOTA rows inherit it

        $ordersBatch   = [];
        $itemsBatch    = [];
        $paymentsBatch = [];

        foreach ($rows as $rowIdx => $row) {
            $name    = trim((string) ($row[2] ?? ''));
            $csName  = trim((string) ($row[3] ?? ''));
            $contact = trim((string) ($row[4] ?? ''));
            $area    = trim((string) ($row[5] ?? ''));
            $code    = strtoupper(trim((string) ($row[6] ?? '')));
            $color   = trim((string) ($row[7] ?? ''));
            $size    = trim((string) ($row[8] ?? ''));
            $price   = (float) ($row[9] ?? 0);
            $dp      = (float) ($row[10] ?? 0);
            $dpRaw   = $row[11] ?? '';
            $an      = trim((string) ($row[12] ?? ''));
            $ketPost = trim((string) ($row[13] ?? ''));

            $csAgentId = $csName !== '' ? ($csAgents[strtolower($csName)]->id ?? null) : null;

            if (empty($name) || empty($code)) continue;

            $normalizedPhone = Customer::normalizePhone($contact);
            $customerId = null;
            if ($normalizedPhone && isset($existingPhones[strtolower($normalizedPhone)])) {
                $customerId = $existingPhones[strtolower($normalizedPhone)];
            } elseif (isset($existingNames[strtolower($name)])) {
                $customerId = $existingNames[strtolower($name)];
            } else {
                $areaKey = strtolower($area);
                $areaId  = $shippingAreas[$areaKey]?->id ?? null;
                if (!$areaId && $area) {
                    foreach ($shippingAreas as $k => $a) {
                        if (str_contains($k, $areaKey) || str_contains($areaKey, $k)) {
                            $areaId = $a->id; break;
                        }
                    }
                }
                $customerId = DB::table('customers')->insertGetId([
                    'name'                     => $name,
                    'phone'                    => $normalizedPhone ?: null,
                    'type'                     => 'customer',
                    'default_shipping_area_id' => $areaId,
                    'created_at'               => $now,
                    'updated_at'               => $now,
                ]);
                if ($normalizedPhone) $existingPhones[strtolower($normalizedPhone)] = $customerId;
                $existingNames[strtolower($name)] = $customerId;
            }

            // Resolve the shipping area for THIS order from the row's KOTA.
            $areaKey = strtolower($area);
            $areaId  = $area ? ($shippingAreas[$areaKey]?->id ?? null) : null;
            if (!$areaId && $area) {
                foreach ($shippingAreas as $k => $a) {
                    if (str_contains($k, $areaKey) || str_contains($areaKey, $k)) {
                        $areaId = $a->id; break;
                    }
                }
            }
            // If this row's KOTA is blank (common when the same customer spans several
            // rows and the city is only written on the first row), fall back to the
            // customer's default shipping area so every order for that customer gets one.
            if (!$areaId) {
                $areaId = $customerAreas[$customerId]
                    ?? DB::table('customers')->where('id', $customerId)->value('default_shipping_area_id');
                $customerAreas[$customerId] = $areaId; // cache for subsequent rows
            } else {
                // Remember this resolved area as the customer's area for later blank rows
                $customerAreas[$customerId] = $areaId;
            }

            $product = $products[$code] ?? null;
            if (!$product) { $skipped++; continue; }

            $variant   = null;
            $unitPrice = $price > 0 ? $price : $product->price;
            if ($color || $size) {
                foreach ($product->variants as $v) {
                    $colorMatch = !$color || strtolower($v->color ?? '') === strtolower($color);
                    $sizeMatch  = !$size  || strtolower($v->size ?? '')  === strtolower($size);
                    if ($colorMatch && $sizeMatch) { $variant = $v; break; }
                }
                if ($variant && !$price) {
                    $unitPrice = $variant->final_price ?? $product->price;
                }
            }

            $customerType = $customerTypes[$customerId] ?? 'customer';
            $weightGram   = $product->weight_gram ?? 0;

            $shippingArea = $areaId ? $shippingAreasById->get($areaId) : null;
            $shippingFee  = $shippingArea ? $shippingArea->calcShippingFee($weightGram) : 0;
            $chargeableKg = ShippingArea::calcChargeableKg($weightGram);

            $isExcluded   = $product->excluded_from_promo ?? false;
            $eligibleQty  = $isExcluded ? 0 : 1;
            $bestDiscount = 0;
            $bestSubsidy  = 0;

            foreach ($promoRules as $rule) {
                if (!$rule->appliesTo($customerType, $eligibleQty)) continue;
                $calc    = $rule->calculateDiscount($eligibleQty);
                $benefit = $calc['discount'] + $calc['max_shipping_subsidy'];
                if ($benefit > $bestDiscount + $bestSubsidy) {
                    $bestDiscount = $calc['discount'];
                    $bestSubsidy  = $calc['max_shipping_subsidy'];
                }
            }
            $shippingDiscount = min($shippingFee, $bestSubsidy);
            $totalAmount      = max(0, $unitPrice - $bestDiscount + $shippingFee - $shippingDiscount);

            $orderedAt = $baseTime->copy()->addSeconds($rowIdx)->toDateTimeString();
            $notes     = implode(' | ', array_filter([$an, $ketPost])) ?: null;

            $ordersBatch[] = [
                'trip_id'              => $trip->id,
                'customer_id'          => $customerId,
                'shipping_area_id'     => $areaId,
                'cs_agent_id'          => $csAgentId,
                'notes'                => $notes,
                'ordered_at'           => $orderedAt,
                'created_by'           => $createdBy,
                'subtotal'             => $unitPrice,
                'discount_amount'      => $bestDiscount,
                'shipping_fee'         => $shippingFee,
                'shipping_discount'    => $shippingDiscount,
                'shipping_weight_gram' => $weightGram,
                'shipping_kg_charged'  => $chargeableKg,
                'total_amount'         => $totalAmount,
                'deposit_paid'         => $dp > 0 ? $dp : 0,
                'payment_status'       => $dp > 0 ? 'partial' : 'unpaid',
                'created_at'           => $now,
                'updated_at'           => $now,
            ];

            $itemsBatch[] = ['_rowIdx' => count($ordersBatch) - 1,
                'product_id'         => $product->id,
                'product_variant_id' => $variant?->id,
                'quantity'           => 1,
                'unit_price'         => $unitPrice,
                'line_total'         => $unitPrice,
                'status'             => 'pending',
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            if ($dp > 0) {
                $dpDate = substr($now, 0, 10);
                if ($dpRaw !== '' && $dpRaw !== null) {
                    $dpDate = $this->parseDateValue($dpRaw, $dpDate);
                }
                $paymentsBatch[] = ['_rowIdx' => count($ordersBatch) - 1,
                    'amount' => $dp, 'type' => 'deposit', 'method' => 'transfer',
                    'paid_at' => $dpDate, 'recorded_by' => $createdBy,
                    'created_at' => $now, 'updated_at' => $now,
                ];
            }

            $imported++;

            if (count($ordersBatch) >= 500) {
                $this->flushOrderBatch($ordersBatch, $itemsBatch, $paymentsBatch);
                $ordersBatch = []; $itemsBatch = []; $paymentsBatch = [];
            }
        }

        if (!empty($ordersBatch)) {
            $this->flushOrderBatch($ordersBatch, $itemsBatch, $paymentsBatch);
        }

        return ['imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Parse any date value from Excel — handles Excel serials, DD/MM/YYYY,
     * DD-MM-YY, YYYY-MM-DD, and text dates. Returns YYYY-MM-DD or $fallback.
     */
    private function parseDateValue(mixed $raw, string $fallback): string
    {
        $s = trim((string) $raw);
        if ($s === '') return $fallback;

        if (is_numeric($s) && (int) $s > 40000) {
            try {
                $r = \Carbon\Carbon::createFromTimestamp(((int) $s - 25569) * 86400)->utc()->format('Y-m-d');
                if ($this->isValidYmd($r)) return $r;
            } catch (\Exception $e) {}
        }

        if (is_numeric($s)) return $fallback;

        if (str_contains($s, '/')) {
            $p = explode('/', $s);
            if (count($p) === 3) {
                [$d, $m, $y] = [(int) $p[0], (int) $p[1], (int) $p[2]];
                if ($y < 100) $y += 2000;
                if (checkdate($m, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $m, $d);
            }
        }

        if (str_contains($s, '-')) {
            $p = explode('-', $s);
            if (count($p) === 3) {
                $a = (int) $p[0]; $b = (int) $p[1]; $c = (int) $p[2];

                if ($a > 1900 && checkdate($b, $c, $a))
                    return sprintf('%04d-%02d-%02d', $a, $b, $c);

                $y = $c < 100 ? $c + 2000 : $c;
                if ($y >= 1900 && checkdate($b, $a, $y))
                    return sprintf('%04d-%02d-%02d', $y, $b, $a);

                if ($y >= 1900 && checkdate($a, $b, $y))
                    return sprintf('%04d-%02d-%02d', $y, $a, $b);
            }
        }

        try {
            $r = \Carbon\Carbon::parse($s)->format('Y-m-d');
            if ($this->isValidYmd($r)) return $r;
        } catch (\Exception $e) {}

        return $fallback;
    }

    private function isValidYmd(string $d): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return false;
        return (int) $m[1] >= 1900 && (int) $m[1] <= 2100 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private function flushOrderBatch(array &$orders, array &$items, array &$payments): void
    {
        if (empty($orders)) return;
        $count = count($orders);

        foreach ($orders as $i => &$order) {
            $order['order_number'] = 'ORD-' . strtoupper(bin2hex(random_bytes(5)));
        }
        unset($order);

        DB::table('orders')->insert($orders);

        $firstId = (int) DB::getPdo()->lastInsertId();
        $insertedIds = range($firstId, $firstId + $count - 1);

        $itemsToInsert = [];
        foreach ($items as $item) {
            $idx = $item['_rowIdx'];
            if (isset($insertedIds[$idx])) {
                unset($item['_rowIdx']);
                $item['order_id'] = $insertedIds[$idx];
                $itemsToInsert[]  = $item;
            }
        }
        if ($itemsToInsert) DB::table('order_items')->insert($itemsToInsert);

        $paymentsToInsert = [];
        foreach ($payments as $pay) {
            $idx = $pay['_rowIdx'];
            if (isset($insertedIds[$idx])) {
                unset($pay['_rowIdx']);
                $pay['order_id']    = $insertedIds[$idx];
                $paymentsToInsert[] = $pay;
            }
        }
        if ($paymentsToInsert) DB::table('payments')->insert($paymentsToInsert);
    }
}