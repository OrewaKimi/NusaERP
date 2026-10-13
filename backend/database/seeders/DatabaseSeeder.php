<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'admin@nusaerp.test'],
            [
                'name' => 'Admin NusaERP',
                'password' => Hash::make('password'),
                'role' => 'admin',
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'sales@nusaerp.test'],
            [
                'name' => 'Sales NusaERP',
                'password' => Hash::make('password'),
                'role' => 'sales',
            ]
        );

        User::query()->updateOrCreate(
            ['email' => 'gudang@nusaerp.test'],
            [
                'name' => 'Gudang NusaERP',
                'password' => Hash::make('password'),
                'role' => 'gudang',
            ]
        );

        Customer::query()->updateOrCreate(
            ['email' => 'customer1@example.com'],
            [
                'name' => 'Budi Mart',
                'phone' => '081234567890',
                'address' => 'Jakarta Selatan',
            ]
        );

        Customer::query()->updateOrCreate(
            ['email' => 'customer2@example.com'],
            [
                'name' => 'Toko Makmur',
                'phone' => '081298765432',
                'address' => 'Bandung',
            ]
        );

        Product::query()->updateOrCreate(
            ['sku' => 'SKU-001'],
            [
                'name' => 'Laptop Pro 14',
                'price' => 18000000,
                'stock' => 10,
                'min_stock' => 5,
                'description' => 'Laptop bisnis ringan.',
            ]
        );

        Product::query()->updateOrCreate(
            ['sku' => 'SKU-002'],
            [
                'name' => 'Mouse Wireless',
                'price' => 250000,
                'stock' => 4,
                'min_stock' => 5,
                'description' => 'Mouse ergonomis untuk workspace.',
            ]
        );

        Product::query()->updateOrCreate(
            ['sku' => 'SKU-003'],
            [
                'name' => 'Monitor 24 Inch',
                'price' => 2200000,
                'stock' => 8,
                'min_stock' => 4,
                'description' => 'Monitor untuk kantor.',
            ]
        );

        Supplier::query()->updateOrCreate(
            ['email' => 'supplier1@example.com'],
            [
                'name' => 'CV Sumber Jaya',
                'phone' => '081111223344',
                'address' => 'Surabaya',
            ]
        );

        Supplier::query()->updateOrCreate(
            ['email' => 'supplier2@example.com'],
            [
                'name' => 'PT Global Supply',
                'phone' => '081155667788',
                'address' => 'Bogor',
            ]
        );
    }
}
