<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderDeskTest extends TestCase
{
    use RefreshDatabase;

    public function test_hotel_price_tiers_and_fractional_rounding_follow_the_order_snapshot(): void
    {
        $manager = $this->staff('manager');
        $otherStaff = $this->staff('clerk');
        $this->actingAs($manager);
        $hotel = $this->newHotel();
        $product = $this->newProduct();
        $priceKey = ['customerId' => $hotel, 'productId' => $product, 'unit' => 'kg'];

        $this->putJson('/api/prices', $priceKey + ['price' => 8.75])->assertOk();
        $this->getJson("/api/prices?customerId={$hotel}")->assertOk()->assertJson([$priceKey + ['price' => 8.75]]);

        $items = [
            ['productId' => $product, 'unit' => 'kg', 'quantity' => 1.5],
            ['productId' => $product, 'unit' => 'carton', 'quantity' => 1],
            ['productId' => $product, 'unit' => 'carton', 'quantity' => 2, 'unitPrice' => 60.25],
        ];
        $draft = $this->postJson('/api/orders', ['customerId' => $hotel, 'items' => $items])
            ->assertCreated()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('total', 213.63)
            ->assertJsonPath('createdBy', 'manager')
            ->json();
        $this->assertEquals([8.75, 80, 60.25], array_column($draft['items'], 'unitPrice'));
        $this->assertEquals([13.13, 80, 120.50], array_column($draft['items'], 'lineTotal'));

        $order = $draft['id'];
        $this->assertDatabaseHas('orders', ['id' => $order, 'created_by' => $manager->id, 'total' => 21363]);
        $this->assertDatabaseHas('order_items', ['order_id' => $order, 'unit' => 'kg', 'quantity' => 1500, 'unit_price' => 875, 'line_total' => 1313]);
        $this->getJson('/api/products')->assertOk()
            ->assertJsonPath('0.units.0.stock', 20.125)
            ->assertJsonPath('0.units.1.stock', null);
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $order);

        $this->actingAs($otherStaff);
        $edited = $this->putJson("/api/orders/{$order}", [
            'customerId' => $hotel, 'revision' => $draft['revision'], 'items' => [$items[0], $items[2]],
        ])->assertOk()->assertJsonPath('total', 133.63)->assertJsonPath('updatedBy', 'clerk')->json();

        $this->postJson("/api/orders/{$order}/invoice", ['revision' => $draft['revision']])->assertConflict();
        $issued = $this->postJson("/api/orders/{$order}/invoice", ['revision' => $edited['revision']])
            ->assertOk()->assertJsonPath('status', 'invoiced')->assertJsonPath('issuedBy', 'clerk')->json();
        $this->assertMatchesRegularExpression('/^INV-\d{6,}$/', $issued['invoiceNumber']);
        $this->assertNotFalse(strtotime($issued['issuedAt']));
        $this->assertDatabaseHas('orders', ['id' => $order, 'issued_by' => $otherStaff->id, 'invoice_number' => $issued['invoiceNumber'], 'status' => 'invoiced']);

        $this->postJson("/api/orders/{$order}/invoice", ['revision' => $edited['revision']])->assertOk()->assertExactJson($issued);
        $this->putJson("/api/orders/{$order}", ['customerId' => $hotel, 'items' => $items])->assertConflict();
        $this->postJson("/api/orders/{$order}/cancel", ['revision' => $issued['revision']])->assertConflict();

        $this->deleteJson('/api/prices', $priceKey)->assertSuccessful();
        $this->getJson("/api/prices?customerId={$hotel}")->assertOk()->assertJsonCount(0);
        $this->postJson('/api/orders', ['customerId' => $hotel, 'items' => [$items[0]]])
            ->assertCreated()->assertJsonPath('items.0.unitPrice', 9.99);
        $this->putJson("/api/customers/{$hotel}", ['name' => 'Renamed hotel'])->assertOk();
        $this->putJson("/api/products/{$product}", [
            'name' => 'Renamed rice', 'sku' => 'RICE-01', 'active' => false, 'units' => [['unit' => 'kg', 'price' => 15]],
        ])->assertOk();
        $this->getJson("/api/orders/{$order}")->assertOk()->assertExactJson($issued);

        $history = $this->getJson("/api/orders/{$order}/history")->assertOk()->json();
        $this->assertSame([['invoiced', 'clerk'], ['edited', 'clerk'], ['created', 'manager']], array_map(
            fn (array $event) => [$event['action'], $event['username']], $history,
        ));
        $this->assertDatabaseHas('order_events', ['order_id' => $order, 'action' => 'invoiced', 'user_id' => $otherStaff->id]);
    }

    public function test_validation_leaves_existing_records_unchanged_and_cancelled_orders_cannot_be_invoiced(): void
    {
        $this->actingAs($this->staff('manager'));
        $hotel = $this->newHotel();
        $product = $this->newProduct();
        $item = ['productId' => $product, 'unit' => 'kg', 'quantity' => 2];

        $this->postJson('/api/products', [
            'name' => 'Duplicate SKU', 'sku' => 'RICE-01', 'units' => [['unit' => 'kg', 'price' => 15]],
        ])->assertConflict();
        $this->postJson('/api/orders', [
            'customerId' => $hotel, 'items' => [array_replace($item, ['quantity' => 1.0001])],
        ])->assertBadRequest();
        $this->assertDatabaseCount('orders', 0);

        $draft = $this->postJson('/api/orders', ['customerId' => $hotel, 'items' => [$item]])->assertCreated()->json();
        $order = $draft['id'];
        $this->putJson("/api/products/{$product}", [
            'name' => 'Should not save', 'sku' => 'RICE-01', 'units' => [['unit' => 'carton', 'price' => 90]],
        ])->assertConflict();
        $this->assertDatabaseHas('products', ['id' => $product, 'name' => 'Rice']);

        $this->putJson("/api/orders/{$order}", [
            'customerId' => $hotel, 'revision' => $draft['revision'], 'items' => [array_replace($item, ['unitPrice' => 2.345])],
        ])->assertBadRequest();
        $this->getJson("/api/orders/{$order}")->assertOk()->assertExactJson($draft);

        $cancelled = $this->postJson("/api/orders/{$order}/cancel", ['revision' => $draft['revision']])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('invoiceNumber', null)->json();
        $this->postJson("/api/orders/{$order}/invoice", ['revision' => $cancelled['revision']])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $order, 'status' => 'cancelled', 'invoice_number' => null]);
    }

    public function test_hotel_price_overrides_do_not_leak_to_other_hotel_accounts(): void
    {
        $this->actingAs($this->staff('manager'));
        $hotel = $this->newHotel();
        $other = $this->postJson('/api/customers', ['name' => 'Park Hotel'])->assertCreated()->json('id');
        $product = $this->newProduct();
        $this->putJson('/api/prices', ['customerId' => $hotel, 'productId' => $product, 'unit' => 'kg', 'price' => 0])
            ->assertOk();
        $this->getJson("/api/prices?customerId={$other}")->assertOk()->assertJsonCount(0);
        $items = [['productId' => $product, 'unit' => 'kg', 'quantity' => 2]];
        $this->postJson('/api/orders', ['customerId' => $hotel, 'items' => $items])->assertCreated()
            ->assertJsonPath('items.0.unitPrice', 0);
        $this->postJson('/api/orders', ['customerId' => $other, 'items' => $items])->assertCreated()
            ->assertJsonPath('items.0.unitPrice', 9.99)->assertJsonPath('total', 19.98);
    }

    private function staff(string $username): User
    {
        $staff = new User;
        $staff->username = $username;
        $staff->password = Hash::make('test-only-password');
        $staff->save();

        return $staff;
    }

    private function newHotel(): int
    {
        return $this->postJson('/api/customers', ['name' => 'Harbour Hotel', 'phone' => '5550100'])
            ->assertCreated()->json('id');
    }

    private function newProduct(): int
    {
        return $this->postJson('/api/products', [
            'name' => 'Rice', 'sku' => 'RICE-01', 'units' => [
                ['unit' => 'kg', 'price' => 9.99, 'stock' => 20.125], ['unit' => 'carton', 'price' => 80],
            ],
        ])->assertCreated()->json('id');
    }
}
