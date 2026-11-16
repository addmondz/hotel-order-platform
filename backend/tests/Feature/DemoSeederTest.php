<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\OwnerSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['initial_admin.password' => 'demo-seeder-test-password']);
    }

    public function test_demo_data_has_usable_customers_products_and_complete_order_snapshots(): void
    {
        $this->seed(DemoSeeder::class);

        $owner = User::where('username', OwnerSeeder::EMAIL)->sole();
        $this->assertFalse($owner->isHotel());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('products', 5);
        $this->assertDatabaseCount('product_units', 5);
        $this->assertDatabaseCount('customers', 2);
        $this->assertDatabaseCount('orders', 3);
        $this->assertDatabaseCount('invoice_payments', 0);

        $this->actingAs($owner);
        $this->getJson('/api/customers')->assertOk()->assertJsonCount(2);
        $this->getJson('/api/products')->assertOk()->assertJsonCount(5);
        $orders = $this->getJson('/api/orders')->assertOk()->assertJsonCount(3)->json();
        $this->assertEqualsCanonicalizing(['open', 'invoiced', 'cancelled'], array_column($orders, 'status'));

        foreach ($orders as $order) {
            $saved = $this->getJson('/api/orders/'.$order['id'])->assertOk()->json();
            $customer = DB::table('customers')->find($saved['customerId']);
            $this->assertSame($customer->name, $saved['customerName']);
            $this->assertSame($customer->phone, $saved['customerPhone']);
            $this->assertNotEmpty($saved['items']);
            $total = 0;
            foreach (DB::table('order_items')->where('order_id', $order['id'])->get() as $item) {
                $product = DB::table('products')->find($item->product_id);
                $unit = DB::table('product_units')->where('product_id', $product->id)->where('unit', $item->unit)->sole();
                $this->assertSame($product->name, $item->product_name);
                $this->assertSame((int) $unit->price, (int) $item->unit_price);
                $this->assertGreaterThan(0, $item->quantity);
                $this->assertSame(intdiv($item->quantity * $item->unit_price + 500, 1000), (int) $item->line_total);
                $total += $item->line_total;
            }
            $this->assertEquals($total / 100, $saved['total']);
            $this->assertSame(OwnerSeeder::EMAIL, $saved['createdBy']);
            $this->assertDatabaseHas('order_events', ['order_id' => $order['id'], 'action' => 'created']);

            if ($saved['status'] === 'invoiced') {
                $this->assertMatchesRegularExpression('/^INV-\d{6,}$/', $saved['invoiceNumber']);
                $this->assertNotNull($saved['issuedAt']);
                $this->assertSame(OwnerSeeder::EMAIL, $saved['issuedBy']);
                $this->getJson('/api/orders/'.$order['id'].'/payments')->assertOk();
                $this->putJson('/api/orders/'.$order['id'], [
                    'customerId' => $saved['customerId'], 'items' => $saved['items'],
                ])->assertConflict();
            } else {
                $this->assertNull($saved['invoiceNumber']);
                $this->assertNull($saved['issuedAt']);
                $this->assertNull($saved['issuedBy']);
            }
        }

        $this->assertEqualsCanonicalizing([18000, 15500, 4200], DB::table('orders')->pluck('total')->all());
        $cancelled = collect($orders)->firstWhere('status', 'cancelled');
        $this->postJson('/api/orders/'.$cancelled['id'].'/invoice', ['revision' => $cancelled['revision']])->assertConflict();

        $open = collect($orders)->firstWhere('status', 'open');
        $this->postJson('/api/orders/'.$open['id'].'/invoice', ['revision' => $open['revision']])->assertOk()->assertJsonPath('status', 'invoiced');
    }

    public function test_rerunning_preserves_existing_records_and_edited_demo_records_without_duplicates(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', OwnerSeeder::EMAIL)->sole());
        $this->postJson('/api/customers', ['name' => 'Existing customer', 'notes' => 'Keep this.'])->assertCreated();
        $this->postJson('/api/products', [
            'name' => 'Existing product', 'sku' => 'EXISTING-01',
            'units' => [['unit' => 'piece', 'price' => 7.25]],
        ])->assertCreated();

        $this->seed(DemoSeeder::class);

        $customer = DB::table('customers')->where('name', 'Demo Hotel')->sole();
        $this->putJson('/api/customers/'.$customer->id, ['name' => 'Renamed sample customer', 'notes' => 'Edited.'])->assertOk();
        $product = DB::table('products')->where('sku', 'DEMO-TOWEL')->sole();
        $this->putJson('/api/products/'.$product->id, [
            'name' => 'Renamed sample product', 'sku' => 'EDITED-SAMPLE',
            'units' => [['unit' => 'piece', 'price' => 14.25, 'stock' => 25]],
        ])->assertOk();
        $order = DB::table('orders')->where('status', 'open')->sole();
        $this->putJson('/api/orders/'.$order->id, [
            'customerId' => $customer->id, 'notes' => 'Edited order notes.',
            'items' => [['productId' => $product->id, 'unit' => 'piece', 'quantity' => 3]],
        ])->assertOk();
        $this->postJson('/api/orders/'.$order->id.'/invoice', ['revision' => 2])->assertOk();

        $before = $this->snapshot();
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('products', 6);
        $this->assertDatabaseCount('customers', 3);
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_a_sku_conflict_rolls_back_the_entire_sample_set_without_changing_existing_data(): void
    {
        $this->seed();
        $this->actingAs(User::where('username', OwnerSeeder::EMAIL)->sole());
        $this->postJson('/api/products', [
            'name' => 'Existing SKU owner', 'sku' => 'DEMO-SHAMPOO',
            'units' => [['unit' => 'bottle', 'price' => 99]],
        ])->assertCreated();
        $before = $this->snapshot();

        try {
            $this->seed(DemoSeeder::class);
            $this->fail('A conflicting demo SKU must prevent a partial seed.');
        } catch (QueryException) {
            $this->assertSame($before, $this->snapshot());
        }
    }

    private function snapshot(): array
    {
        $tables = ['users', 'products', 'product_units', 'customers', 'orders', 'order_items', 'order_events'];
        $result = [];
        foreach ($tables as $table) {
            $query = DB::table($table);
            if ($table === 'order_items') {
                $query->orderBy('order_id')->orderBy('position');
            } else {
                $query->orderBy('id');
            }
            $result[$table] = json_encode($query->get(), JSON_THROW_ON_ERROR);
        }

        return $result;
    }
}
