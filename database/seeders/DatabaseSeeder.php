<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\PromoRule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Default admin user
        User::firstOrCreate(
            ['email' => 'admin@preorder.test'],
            [
                'name' => 'Admin',
                'role' => 'admin',
                'password' => Hash::make('password'),
            ]
        );

        // Default promo rules (global, for all trips)
        $promos = [
            [
                'name' => 'Free Shipping (3+ items)',
                'description' => 'Free shipping up to Rp 30.000 for orders >= 3 items',
                'min_items' => 3,
                'discount_flat' => 0,
                'discount_per_item' => 0,
                'max_shipping_subsidy' => 30000,
                'eligible_customer_types' => ['customer', 'selected_customer'],
                'is_active' => true,
            ],
            [
                'name' => 'Discount + Free Shipping (5+ items)',
                'description' => 'Rp 30.000 discount + free shipping up to Rp 30.000 for >= 5 items',
                'min_items' => 5,
                'discount_flat' => 30000,
                'discount_per_item' => 0,
                'max_shipping_subsidy' => 30000,
                'eligible_customer_types' => ['customer', 'selected_customer'],
                'is_active' => true,
            ],
            [
                'name' => 'Big Discount (10+ items)',
                'description' => 'Rp 100.000 discount + free shipping up to Rp 30.000 for >= 10 items',
                'min_items' => 10,
                'discount_flat' => 100000,
                'discount_per_item' => 0,
                'max_shipping_subsidy' => 30000,
                'eligible_customer_types' => ['customer', 'selected_customer'],
                'is_active' => true,
            ],
            [
                'name' => 'Reseller / Selected Customer (30+ items)',
                'description' => 'Rp 20.000 discount per item + free shipping up to Rp 30.000 for >= 30 items',
                'min_items' => 30,
                'discount_flat' => 0,
                'discount_per_item' => 20000,
                'max_shipping_subsidy' => 30000,
                'eligible_customer_types' => ['reseller', 'selected_customer'],
                'is_active' => true,
            ],
        ];

        foreach ($promos as $promo) {
            PromoRule::firstOrCreate(['name' => $promo['name']], $promo);
        }
    }
}
