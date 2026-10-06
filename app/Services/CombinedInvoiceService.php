<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Order;
use App\Models\ShippingArea;
use App\Models\Trip;
use Illuminate\Support\Collection;

/**
 * Everything a customer's COMBINED invoice (all their orders in one trip) shows.
 *
 * Two callers share this so the invoice maths exists in one place:
 *  - the on-screen invoice (OrderController::combinedInvoice) uses
 *    priceBreakdown() — the promo / shipping part, which is the genuinely
 *    complicated bit;
 *  - the whole-trip PDF (TripInvoicePdfController) uses build() / forTrip().
 *
 * Before this existed the promo + shipping calculation lived only in the
 * controller, and a PDF would have needed a second hand-copied version of it —
 * exactly the "same total worked out in several places" situation that has
 * caused drift bugs before. A test pins the PDF figures to the on-screen ones.
 */
class CombinedInvoiceService
{
    public function __construct(protected PromoService $promoService) {}

    /**
     * Promo + shipping for one customer's orders: combined weight, the shipping
     * fee for it, the best promo, and the discounts that promo gives.
     *
     * @param  Collection<int, Order> $orders  must have items loaded (and each order's shippingArea)
     * @param  int|string|null $tripId  an int from the PDF (the trip's id), a string from the on-screen
     *                                 invoice (straight from the URL), or null — hence the union type
     */
    public function priceBreakdown(Customer $customer, Collection $orders, int|string|null $tripId): array
    {
        $customer->loadMissing('defaultShippingArea');

        // Every active (not cancelled / sold-out) item across all the orders.
        $allActiveItems = $orders->flatMap(fn ($o) =>
            $o->items->whereNotIn('status', ['cancelled', 'sold_out'])
        );

        // First shipping area found on any order, else the customer's default.
        $shippingArea = $orders->first(fn ($o) => $o->shippingArea)?->shippingArea
            ?? $customer->defaultShippingArea;

        // Routed through the same helpers recalcCustomerShipping() uses, so a
        // printed invoice can't drift from what was actually charged (e.g. by
        // missing the cargo weight bump).
        $totalWeightGram  = $this->promoService->calcTotalWeightGram($allActiveItems, (bool) $customer->use_cargo);
        $combinedShipping = $shippingArea ? $shippingArea->calcShippingFee($totalWeightGram) : 0;
        $chargeableKg     = ShippingArea::calcChargeableKg($totalWeightGram);

        $combinedPromo = $tripId
            ? $this->promoService->getBestPromo($customer->type, $tripId, $allActiveItems)
            : null;

        $combinedDiscount     = $combinedPromo ? $combinedPromo['discount'] : 0;
        $combinedShipSubsidy  = $combinedPromo ? $combinedPromo['max_shipping_subsidy'] : 0;
        $combinedShipDiscount = min($combinedShipping, $combinedShipSubsidy);

        return compact(
            'shippingArea', 'totalWeightGram', 'chargeableKg',
            'combinedShipping', 'combinedDiscount', 'combinedShipDiscount',
            'combinedPromo', 'allActiveItems'
        );
    }

    /**
     * One customer's invoice as plain arrays and numbers — nothing the template
     * has to compute or call methods on.
     *
     * @param  Collection<int, Order> $orders  that customer's orders in the trip, items/payments loaded
     */
    public function build(Customer $customer, Collection $orders, int|string|null $tripId): array
    {
        $p = $this->priceBreakdown($customer, $orders, $tripId);

        $grandSubtotal = (float) $orders->sum('subtotal');
        $grandPaid     = (float) $orders->sum('deposit_paid');
        $grandTotal    = max(0, $grandSubtotal - $p['combinedDiscount'] + $p['combinedShipping'] - $p['combinedShipDiscount']);

        $allItems = $orders->flatMap(fn ($o) => $o->items->map(fn ($i) => ['item' => $i, 'order' => $o]));

        // Grouped by product, ordered by product code (groupBy alone keeps
        // whatever order the rows were queried in).
        $groups = $allItems
            ->groupBy(fn ($row) => $row['item']->product_id)
            ->sortBy(fn ($rows) => $rows->first()['item']->product->product_code ?? '')
            ->map(function ($rows) {
                return [
                    'code' => $rows->first()['item']->product->product_code ?? '—',
                    'rows' => $rows->map(function ($row) {
                        $item = $row['item'];
                        $dim  = in_array($item->status, ['sold_out', 'cancelled']);
                        return [
                            'label'      => $item->variant?->label ?? '—',
                            'qty'        => (int) $item->quantity,
                            'unit_price' => $dim ? 0 : (float) $item->unit_price,
                            'line_total' => $dim ? 0 : (float) $item->line_total,
                            'dim'        => $dim, // sold out / cancelled: shown, but at Rp 0
                        ];
                    })->values()->all(),
                ];
            })->values()->all();

        // Voided payments are already excluded from deposit_paid, and the
        // internal reallocation entries (the paired refund + partial that move
        // credit between a customer's own orders) aren't something the customer
        // actually did — showing them would look like money going in and out of
        // nowhere on a document meant to explain what they paid.
        $payments = $orders->flatMap(fn ($o) => $o->payments)
            ->reject(fn ($pay) => $pay->isVoided())
            ->reject(fn ($pay) => $pay->method === 'reallocation')
            ->sortBy('paid_at')
            ->map(fn ($pay) => [
                'date'   => \Carbon\Carbon::parse($pay->paid_at)->format('d M Y'),
                'label'  => $pay->displayType(),
                'number' => $pay->documentNumber(), // CR/… or RP/… for a Credit Note / Sales Return
                'refund' => $pay->type === 'refund',
                'amount' => (float) $pay->amount,
            ])->values()->all();

        $area  = $p['shippingArea'];
        $promo = $p['combinedPromo'];

        return [
            'customer' => [
                'name'    => $customer->name,
                'phone'   => $customer->phone,
                'type'    => match ($customer->type) {
                    'reseller'          => 'Reseller',
                    'selected_customer' => 'Selected',
                    default             => 'Customer',
                },
                'cargo'   => (bool) $customer->use_cargo,
                'address' => $customer->address,
            ],
            'trip_name'     => $orders->first()->trip->name ?? '',
            'order_count'   => $orders->count(),
            'item_count'    => $allItems->count(),
            'paid_count'    => $orders->where('payment_status', 'paid')->count(),
            'partial_count' => $orders->where('payment_status', 'partial')->count(),
            'unpaid_count'  => $orders->where('payment_status', 'unpaid')->count(),
            'sold_out_count' => $allItems->filter(fn ($row) => $row['item']->status === 'sold_out')->count(),

            'delivery' => $area ? [
                'name'      => $area->name . ($area->province ? ', ' . $area->province : ''),
                'weight_g'  => (float) $p['totalWeightGram'],
                'kg'        => $p['chargeableKg'],
                'rate'      => $area->isFlatFee()
                    ? 'Flat Rp ' . number_format($area->flat_fee, 0, ',', '.')
                    : 'Rp ' . number_format($area->price_per_kg, 0, ',', '.') . '/kg',
            ] : null,

            'groups' => $groups,
            // The same groups laid out for the printed page (see paginateGroups()).
            // A long address makes the Bill To box taller and leaves the first
            // page less room, roughly a line of items per extra address line.
            'pages'  => self::paginateGroups($groups, ...$this->firstPageCaps($customer->address)),
            'promo'  => $promo ? [
                'name'          => $promo['rule']->name,
                'free_shipping' => $p['combinedShipDiscount'] >= $p['combinedShipping'] && $p['combinedShipping'] > 0,
            ] : null,

            'totals' => [
                'total_qty'     => (int) $p['allActiveItems']->sum('quantity'),
                'subtotal'      => $grandSubtotal,
                'discount'      => (float) $p['combinedDiscount'],
                'shipping'      => (float) $p['combinedShipping'],
                'kg'            => $p['chargeableKg'],
                'ship_discount' => (float) $p['combinedShipDiscount'],
                'grand_total'   => (float) $grandTotal,
                'paid'          => $grandPaid,
                'balance'       => $grandTotal - $grandPaid,
            ],
            'payments' => $payments,
        ];
    }

    /**
     * How many LINES (a product header counts as one, each variant as one) fit in
     * one column of the item list. Measured from the rendered A5 PDF: a row is
     * 12.6pt, the Grand Total block about 170pt, and the first page also carries
     * the header and the Bill To / Delivery / Summary row.
     *
     *  - WITH_TOTALS: the whole list must leave room for the Grand Total block on
     *    page 1 (so a short invoice stays on one page).
     *  - FULL: once the list is going to run onto more pages anyway, page 1 can be
     *    filled right down to the margin — the totals follow on the last page.
     *  - LATER: a continuation page, which also has to fit the totals under its
     *    last chunk.
     */
    public const FIRST_PAGE_LINES_WITH_TOTALS = 16;
    public const FIRST_PAGE_LINES_FULL        = 29;
    public const LATER_PAGE_LINES             = 28;

    /**
     * Lay product groups out as page-sized chunks of two columns.
     *
     * dompdf cannot split a two-column table across pages — a row taller than the
     * page breaks the left column onto one page and the right onto another (any
     * list past about a dozen lines would hit that). So each chunk returned here
     * becomes its OWN two-column row, always shorter than a page, and dompdf just
     * moves whole chunks onto the next page when needed.
     *
     *  - A product group is kept together where it fits; one too tall for a column
     *    is split, repeating its header marked "(cont.)".
     *  - Reading order is left column top to bottom, then right.
     *  - Each chunk's columns are balanced, so a short list isn't all in the left.
     *
     * @param  array<int, array{code: string, rows: array}> $groups
     * @param  int $withTotalsCap  first-page column capacity if the totals must share the page
     * @param  int $fullPageCap    first-page column capacity if the list runs on to more pages
     * @return array<int, array{left: array, right: array}>
     */
    public static function paginateGroups(
        array $groups,
        int $withTotalsCap = self::FIRST_PAGE_LINES_WITH_TOTALS,
        int $fullPageCap   = self::FIRST_PAGE_LINES_FULL,
        int $laterCap      = self::LATER_PAGE_LINES,
    ): array {
        $laterCap      = max(4, $laterCap);
        $withTotalsCap = max(3, min($withTotalsCap, $laterCap));
        $fullPageCap   = max($withTotalsCap, min($fullPageCap, $laterCap + 4));

        // Break up any group too tall for even a continuation column.
        $blocks = [];
        foreach ($groups as $g) {
            foreach (array_chunk($g['rows'], $laterCap - 1) as $part => $rows) {
                $blocks[] = ['code' => $g['code'] . ($part > 0 ? ' (cont.)' : ''), 'rows' => $rows];
            }
        }

        // If everything fits in ONE chunk with room left for the totals on page 1,
        // that's the layout. Otherwise fill page 1 completely and carry on.
        $chunks = self::fillChunks($blocks, $withTotalsCap, $laterCap);
        if (count($chunks) > 1) {
            $chunks = self::fillChunks($blocks, $fullPageCap, $laterCap);
        }

        return array_map([self::class, 'balanceChunk'], $chunks);
    }

    private static function blockLines(array $block): int
    {
        return count($block['rows']) + 1; // its rows + its product header line
    }

    /** Greedy fill: left column, then right, then a new chunk. */
    private static function fillChunks(array $blocks, int $firstCap, int $laterCap): array
    {
        $chunks = [];
        $cur    = ['cap' => $firstCap, 'blocks' => [], 'left' => 0, 'right' => 0, 'rightStarted' => false];

        foreach ($blocks as $b) {
            $n = self::blockLines($b);
            if (! $cur['rightStarted'] && $cur['left'] + $n <= $cur['cap']) {
                $cur['left'] += $n;
            } elseif ($cur['right'] + $n <= $cur['cap']) {
                $cur['rightStarted'] = true;
                $cur['right'] += $n;
            } else {
                if ($cur['blocks']) $chunks[] = $cur;
                $cur = ['cap' => $laterCap, 'blocks' => [], 'left' => $n, 'right' => 0, 'rightStarted' => false];
            }
            $cur['blocks'][] = $b;
        }
        if ($cur['blocks']) $chunks[] = $cur;

        return $chunks;
    }

    /** Pick the split between a chunk's blocks that gives the shortest taller column. */
    private static function balanceChunk(array $chunk): array
    {
        $blocks  = $chunk['blocks'];
        $total   = array_sum(array_map([self::class, 'blockLines'], $blocks));
        $bestK   = count($blocks);
        $bestH   = PHP_INT_MAX;
        $leftSum = 0;

        for ($k = 0; $k <= count($blocks); $k++) {
            $rightSum = $total - $leftSum;
            // <= (not <): on a tie keep the LATER split, so a lone block stays in the
            // left column instead of landing in the right one.
            if ($leftSum <= $chunk['cap'] && $rightSum <= $chunk['cap'] && max($leftSum, $rightSum) <= $bestH) {
                $bestH = max($leftSum, $rightSum);
                $bestK = $k;
            }
            if ($k < count($blocks)) $leftSum += self::blockLines($blocks[$k]);
        }

        return ['left' => array_slice($blocks, 0, $bestK), 'right' => array_slice($blocks, $bestK)];
    }

    /**
     * First-page capacities, reduced for a long address: each extra line of it makes
     * the Bill To box taller (about 9pt), which costs roughly three-quarters of an
     * item line.
     *
     * @return array{0: int, 1: int}
     */
    private function firstPageCaps(?string $address): array
    {
        $extraLines = max(0, (int) ceil(mb_strlen((string) $address) / 32) - 1);
        $penalty    = (int) ceil($extraLines * 0.75);

        return [self::FIRST_PAGE_LINES_WITH_TOTALS - $penalty, self::FIRST_PAGE_LINES_FULL - $penalty];
    }

    /**
     * An invoice for every customer who has orders in the trip, alphabetical by
     * customer name — the order they'd be sorted into for handing out or packing.
     *
     * @param  int|null $onlyCreatedBy  restrict to orders one staff member created
     *                                  (staff who can only see their own data)
     * @return Collection<int, array>
     */
    public function forTrip(Trip $trip, ?int $onlyCreatedBy = null): Collection
    {
        $query = Order::with(['customer.defaultShippingArea', 'items.product', 'items.variant', 'payments.salesAdjustment', 'trip', 'shippingArea'])
            ->where('trip_id', $trip->id)
            ->orderByRaw('COALESCE(ordered_at, created_at) ASC')
            ->orderBy('id');

        if ($onlyCreatedBy !== null) {
            $query->where('created_by', $onlyCreatedBy);
        }

        return $query->get()
            ->groupBy('customer_id')
            ->map(fn ($group) => $this->build($group->first()->customer, $group->values(), $trip->id))
            ->sortBy(fn ($invoice) => mb_strtolower($invoice['customer']['name']))
            ->values();
    }
}