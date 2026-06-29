<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $handle
 * @property bool $is_active
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\CsAgentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereHandle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|CsAgent whereUpdatedAt($value)
 */
	class CsAgent extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $phone
 * @property string|null $address
 * @property int|null $default_shipping_area_id
 * @property string $type
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $created_by
 * @property-read \App\Models\User|null $createdBy
 * @property-read \App\Models\ShippingArea|null $defaultShippingArea
 * @property-read string $type_label
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\CustomerFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereDefaultShippingAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Customer whereUpdatedAt($value)
 */
	class Customer extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $trip_id
 * @property int $created_by
 * @property string $original_filename
 * @property string $stored_path
 * @property string $status
 * @property int|null $total_rows
 * @property int|null $imported_count
 * @property int|null $skipped_count
 * @property string|null $error_message
 * @property array<array-key, mixed>|null $row_errors
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $createdBy
 * @property-read \App\Models\Trip $trip
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereErrorMessage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereFinishedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereImportedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereOriginalFilename($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereRowErrors($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereSkippedCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereStartedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereStoredPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereTotalRows($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ImportJob whereUpdatedAt($value)
 */
	class ImportJob extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $order_number
 * @property int $trip_id
 * @property int $customer_id
 * @property int $created_by
 * @property int|null $cs_agent_id
 * @property \Illuminate\Support\Carbon|null $ordered_at
 * @property numeric $subtotal
 * @property numeric $discount_amount
 * @property numeric $shipping_fee
 * @property numeric $shipping_weight_gram
 * @property numeric $shipping_kg_charged
 * @property numeric $shipping_discount
 * @property numeric $total_amount
 * @property numeric $deposit_paid
 * @property string $payment_status
 * @property \Illuminate\Support\Carbon|null $invoice_printed_at
 * @property int|null $invoice_printed_by
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $shipping_area_id
 * @property-read \App\Models\User $createdBy
 * @property-read \App\Models\CsAgent|null $csAgent
 * @property-read \App\Models\Customer $customer
 * @property-read int $active_items_count
 * @property-read string $payment_status_badge
 * @property-read float $remaining_balance
 * @property-read \App\Models\User|null $invoicePrintedBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $items
 * @property-read int|null $items_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Payment> $payments
 * @property-read int|null $payments_count
 * @property-read \App\Models\ShippingArea|null $shippingArea
 * @property-read \App\Models\Trip $trip
 * @method static \Database\Factories\OrderFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCsAgentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereCustomerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDepositPaid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereDiscountAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereInvoicePrintedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereInvoicePrintedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereOrderNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereOrderedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order wherePaymentStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippingAreaId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippingDiscount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippingFee($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippingKgCharged($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereShippingWeightGram($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereSubtotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Order whereUpdatedAt($value)
 */
	class Order extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity
 * @property numeric $unit_price
 * @property numeric $line_total
 * @property string $status
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string $status_badge
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\ProductVariant|null $variant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereLineTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereQuantity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUnitPrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OrderItem whereUpdatedAt($value)
 */
	class OrderItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $order_id
 * @property string|null $batch_id
 * @property numeric $amount
 * @property string $type
 * @property string|null $method
 * @property string|null $reference
 * @property \Illuminate\Support\Carbon $paid_at
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $voided_at
 * @property int $recorded_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @property string $verification_status
 * @property int|null $verified_by
 * @property \Illuminate\Support\Carbon|null $verified_at
 * @property string|null $dispute_note
 * @property-read float $effective_amount
 * @property-read \App\Models\Order $order
 * @property-read \App\Models\User $recordedBy
 * @property-read \App\Models\User|null $verifiedBy
 * @property-read \App\Models\User|null $voidedBy
 * @method static \Database\Factories\PaymentFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereBatchId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereDisputeNote($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereMethod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment wherePaidAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereRecordedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereReference($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVerificationStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVerifiedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVoidReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVoidedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Payment whereVoidedBy($value)
 */
	class Payment extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $trip_id
 * @property string|null $sku
 * @property string|null $product_code
 * @property string|null $brand
 * @property int|null $supplier_id
 * @property string|null $notes
 * @property string|null $image
 * @property numeric $price
 * @property numeric|null $shipping_weight
 * @property int $weight_gram
 * @property bool $excluded_from_promo
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read string|null $code_prefix
 * @property-read int $total_ordered_quantity
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $orderItems
 * @property-read int|null $order_items_count
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \App\Models\Trip $trip
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\ProductVariant> $variants
 * @property-read int|null $variants_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereBrand($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereExcludedFromPromo($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereImage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product wherePrice($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereProductCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereShippingWeight($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSku($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Product whereWeightGram($value)
 */
	class Product extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $product_id
 * @property string|null $color
 * @property string|null $size
 * @property numeric $price_adjustment
 * @property int $supplier_stock
 * @property int $allocated_qty
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read float $final_price
 * @property-read string $label
 * @property-read int $remaining_stock
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\OrderItem> $orderItems
 * @property-read int|null $order_items_count
 * @property-read \App\Models\Product $product
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereAllocatedQty($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereColor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant wherePriceAdjustment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereSize($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereSupplierStock($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ProductVariant whereUpdatedAt($value)
 */
	class ProductVariant extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $min_items
 * @property numeric $discount_per_item
 * @property numeric $discount_flat
 * @property numeric $max_shipping_subsidy
 * @property array<array-key, mixed>|null $eligible_customer_types
 * @property array<array-key, mixed>|null $excluded_product_codes
 * @property int|null $trip_id
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Trip|null $trip
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereDiscountFlat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereDiscountPerItem($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereEligibleCustomerTypes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereExcludedProductCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereMaxShippingSubsidy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereMinItems($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PromoRule whereUpdatedAt($value)
 */
	class PromoRule extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $po_number
 * @property int $trip_id
 * @property int|null $supplier_id
 * @property numeric $total_amount
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $purchased_at
 * @property string|null $notes
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $createdBy
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseOrderItem> $items
 * @property-read int|null $items_count
 * @property-read \App\Models\Supplier|null $supplier
 * @property-read \App\Models\Trip $trip
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePoNumber($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder wherePurchasedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereSupplierId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereTotalAmount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrder whereUpdatedAt($value)
 */
	class PurchaseOrder extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int|null $product_variant_id
 * @property int $quantity_ordered
 * @property int $quantity_received
 * @property numeric $unit_cost
 * @property numeric $line_total
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Product $product
 * @property-read \App\Models\PurchaseOrder $purchaseOrder
 * @property-read \App\Models\ProductVariant|null $variant
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereLineTotal($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereProductId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereProductVariantId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem wherePurchaseOrderId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereQuantityOrdered($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereQuantityReceived($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereUnitCost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PurchaseOrderItem whereUpdatedAt($value)
 */
	class PurchaseOrderItem extends \Eloquent {}
}

namespace App\Models{
/**
 * @property string $key
 * @property string|null $value
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Setting whereValue($value)
 */
	class Setting extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $province
 * @property numeric $price_per_kg
 * @property bool $is_active
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\ShippingAreaFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea wherePricePerKg($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereProvince($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ShippingArea whereUpdatedAt($value)
 */
	class ShippingArea extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $contact_person
 * @property string|null $phone
 * @property string|null $country
 * @property string|null $notes
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseOrder> $purchaseOrders
 * @property-read int|null $purchase_orders_count
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereContactPerson($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereCountry($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Supplier whereUpdatedAt($value)
 */
	class Supplier extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string|null $destination
 * @property \Illuminate\Support\Carbon|null $trip_date
 * @property \Illuminate\Support\Carbon|null $order_deadline
 * @property string $status
 * @property string|null $notes
 * @property int $created_by
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\User $createdBy
 * @property-read string $status_badge
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Product> $products
 * @property-read int|null $products_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PromoRule> $promoRules
 * @property-read int|null $promo_rules_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\PurchaseOrder> $purchaseOrders
 * @property-read int|null $purchase_orders_count
 * @method static \Database\Factories\TripFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereCreatedBy($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereDestination($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereOrderDeadline($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereTripDate($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Trip whereUpdatedAt($value)
 */
	class Trip extends \Eloquent {}
}

namespace App\Models{
/**
 * @property int $id
 * @property string $name
 * @property string $role
 * @property array<array-key, mixed>|null $permissions
 * @property string|null $phone
 * @property bool $is_active
 * @property string|null $notes
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Order> $orders
 * @property-read int|null $orders_count
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereIsActive($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereNotes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePermissions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 */
	class User extends \Eloquent {}
}

