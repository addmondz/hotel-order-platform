<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $this->call(OwnerSeeder::class);
            $owner = User::where('username', OwnerSeeder::EMAIL)->lockForUpdate()->firstOrFail();
            if ($owner->isHotel()) {
                throw new RuntimeException('The demo orders require a staff admin account.');
            }

            // The audit marker survives edits to sample names, SKUs, notes and statuses.
            if (DB::table('order_events')->where('action', 'demo_seeded')->exists()) {
                return;
            }

            $products = [];
            foreach ([
                ['DEMO-TOWEL', 'Demo Bath Towel', 'piece', 1200],
                ['DEMO-ROLLS', 'Demo Toilet Rolls', 'pack', 1800],
                ['DEMO-SHAMPOO', 'Demo Shampoo', 'bottle', 850],
                ['DEMO-WATER', 'Demo Drinking Water', 'carton', 1200],
                ['DEMO-DETERGENT', 'Demo Laundry Detergent', 'bottle', 2500],
            ] as [$sku, $name, $unit, $price]) {
                $id = DB::table('products')->insertGetId(['sku' => $sku, 'name' => $name, 'active' => true]);
                DB::table('product_units')->insert(['product_id' => $id, 'unit' => $unit, 'price' => $price]);
                $products[$sku] = ['id' => $id, 'name' => $name, 'unit' => $unit, 'price' => $price];
            }

            $customers = [];
            foreach (['Demo Hotel', 'Demo Hostel'] as $name) {
                $customer = ['name' => $name, 'phone' => '', 'notes' => 'Sample customer for trying OrderDesk.'];
                $customer['id'] = DB::table('customers')->insertGetId($customer);
                $customers[] = $customer;
            }

            $at = now('UTC')->toDateTimeString();
            $firstOrder = $this->createOrder($owner->id, $customers[0], $products, 'open', [
                ['DEMO-TOWEL', 10], ['DEMO-WATER', 5],
            ], $at);
            $this->createOrder($owner->id, $customers[1], $products, 'invoiced', [
                ['DEMO-ROLLS', 3], ['DEMO-SHAMPOO', 6], ['DEMO-DETERGENT', 2],
            ], $at);
            $this->createOrder($owner->id, $customers[0], $products, 'cancelled', [
                ['DEMO-WATER', 2], ['DEMO-ROLLS', 1],
            ], $at);

            DB::table('order_events')->insert([
                'order_id' => $firstOrder, 'user_id' => $owner->id, 'action' => 'demo_seeded', 'happened_at' => $at,
            ]);
        });
    }

    private function createOrder(int $ownerId, array $customer, array $products, string $status, array $items, string $at): int
    {
        $lines = [];
        foreach ($items as $position => [$sku, $quantity]) {
            $product = $products[$sku];
            $lines[] = [
                'position' => $position, 'product_id' => $product['id'], 'product_name' => $product['name'],
                'unit' => $product['unit'], 'quantity' => $quantity * 1000,
                'unit_price' => $product['price'], 'line_total' => $quantity * $product['price'],
            ];
        }

        $id = DB::table('orders')->insertGetId([
            'customer_id' => $customer['id'], 'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'],
            'status' => $status, 'source' => 'staff', 'notes' => 'Sample order for trying OrderDesk.',
            'total' => array_sum(array_column($lines, 'line_total')), 'created_at' => $at, 'updated_at' => $at,
            'created_by' => $ownerId, 'updated_by' => $ownerId, 'revision' => $status === 'open' ? 1 : 2,
        ]);
        DB::table('order_items')->insert(array_map(fn ($line) => $line + ['order_id' => $id], $lines));
        DB::table('order_events')->insert([
            'order_id' => $id, 'user_id' => $ownerId, 'action' => 'created', 'happened_at' => $at,
        ]);

        if ($status === 'invoiced') {
            DB::table('orders')->where('id', $id)->update([
                'invoice_number' => sprintf('INV-%06d', $id), 'issued_at' => $at, 'issued_by' => $ownerId,
            ]);
        }
        if ($status !== 'open') {
            DB::table('order_events')->insert([
                'order_id' => $id, 'user_id' => $ownerId, 'action' => $status, 'happened_at' => $at,
            ]);
        }

        return $id;
    }
}
