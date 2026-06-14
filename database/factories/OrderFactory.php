<?php
namespace Database\Factories;
use App\Models\Customer;
use App\Models\Trip;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    public function definition(): array
    {
        $subtotal = fake()->randomElement([200000, 350000, 500000, 750000, 1000000]);
        return [
            'order_number'         => 'ORD-' . strtoupper(Str::random(8)),
            'trip_id'              => Trip::factory(),
            'customer_id'          => Customer::factory(),
            'shipping_area_id'     => null,
            'created_by'           => User::factory(),
            'subtotal'             => $subtotal,
            'discount_amount'      => 0,
            'shipping_fee'         => 0,
            'shipping_discount'    => 0,
            'shipping_weight_gram' => 0,
            'shipping_kg_charged'  => 0,
            'total_amount'         => $subtotal,
            'deposit_paid'         => 0,
            'payment_status'       => 'unpaid',
            'notes'                => null,
            'ordered_at'           => now(),
        ];
    }

    public function unpaid(): static  { return $this->state(['payment_status' => 'unpaid',  'deposit_paid' => 0]); }
    public function partial(): static {
        return $this->state(fn($a) => ['payment_status' => 'partial', 'deposit_paid' => $a['total_amount'] / 2]);
    }
    public function paid(): static    {
        return $this->state(fn($a) => ['payment_status' => 'paid', 'deposit_paid' => $a['total_amount']]);
    }
}
