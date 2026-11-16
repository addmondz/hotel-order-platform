<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HotelPortalTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_and_order_submission_are_hotel_scoped_and_never_accept_client_prices(): void
    {
        $staff = $this->user('staff');
        $first = $this->hotel('First Hotel');
        $second = $this->hotel('Other Hotel');
        $owner = $this->user('hotel-owner', $first);
        $this->actingAs($staff);
        $product = $this->postJson('/api/products', ['name' => 'Rice', 'sku' => 'RICE', 'units' => [
            ['unit' => 'kg', 'price' => 10, 'stock' => 12.5],
        ]])->assertCreated()->json('id');
        $this->postJson('/api/products', ['name' => 'Unavailable', 'active' => false, 'units' => [
            ['unit' => 'kg', 'price' => 8, 'stock' => 100],
        ]])->assertCreated();
        $this->putJson('/api/prices', ['customerId' => $first, 'productId' => $product, 'unit' => 'kg', 'price' => 9])->assertOk();
        $this->putJson('/api/prices', ['customerId' => $second, 'productId' => $product, 'unit' => 'kg', 'price' => 3])->assertOk();
        $otherOrder = $this->postJson('/api/orders', ['customerId' => $second, 'items' => [
            ['productId' => $product, 'unit' => 'kg', 'quantity' => 1],
        ]])->assertCreated()->json('id');
        $this->getJson('/api/portal/catalog')->assertForbidden();

        $this->actingAs($owner);
        $this->getJson('/api/products')->assertForbidden();
        $this->getJson('/api/orders')->assertForbidden();
        $this->getJson("/api/orders/{$otherOrder}/payments")->assertForbidden();
        $this->getJson("/api/portal/orders/{$otherOrder}")->assertNotFound();
        $this->getJson('/api/portal/orders')->assertOk()->assertExactJson([]);
        $this->getJson("/api/portal/catalog?customerId={$second}")
            ->assertOk()
            ->assertExactJson([
                'hotel' => ['id' => $first, 'name' => 'First Hotel'],
                'products' => [['id' => $product, 'name' => 'Rice', 'sku' => 'RICE', 'units' => [['unit' => 'kg', 'price' => 9]]]],
            ]);
        $result = $this->postJson('/api/portal/orders', [
            'customerId' => $second, 'status' => 'invoiced', 'source' => 'staff', 'notes' => 'Deliver to service entrance',
            'items' => [['productId' => $product, 'unit' => 'kg', 'quantity' => 1.5, 'unitPrice' => 0.01]],
        ])->assertCreated()
            ->assertJsonPath('customerId', $first)->assertJsonPath('items.0.unitPrice', 9)
            ->assertJsonPath('total', 13.5)->assertJsonPath('status', 'open')->assertJsonPath('source', 'portal')
            ->assertJsonPath('notes', 'Deliver to service entrance')->assertJsonMissingPath('createdBy')
            ->assertJsonMissingPath('updatedBy')->assertJsonMissingPath('issuedBy')->json();
        $id = $result['id'];
        $this->assertDatabaseHas('orders', ['id' => $id, 'customer_id' => $first, 'created_by' => $owner->id, 'source' => 'portal', 'total' => 1350]);
        $this->assertDatabaseHas('order_events', ['order_id' => $id, 'user_id' => $owner->id, 'action' => 'created']);
        $this->getJson('/api/portal/orders')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);

        $this->actingAs($staff);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('0.source', 'portal');
        $this->putJson("/api/orders/{$id}", [
            'customerId' => $second, 'items' => [['productId' => $product, 'unit' => 'kg', 'quantity' => 2]],
        ])->assertConflict();
        $this->putJson("/api/orders/{$id}", [
            'customerId' => $first, 'notes' => 'Internal picking note', 'items' => [['productId' => $product, 'unit' => 'kg', 'quantity' => 2]],
        ])->assertOk();
        $this->post("/api/orders/{$id}/invoice", [], ['Accept' => 'application/json'])->assertOk();
        $staffOrder = $this->postJson('/api/orders', [
            'customerId' => $first, 'notes' => 'Internal-only staff order', 'items' => [['productId' => $product, 'unit' => 'kg', 'quantity' => 1]],
        ])->assertCreated()->json('id');

        $this->actingAs($owner);
        $this->getJson("/api/portal/orders/{$id}")->assertOk()
            ->assertJsonPath('notes', 'Deliver to service entrance')
            ->assertJsonPath('items.0.quantity', 2)->assertJsonPath('status', 'invoiced')->assertJsonMissingPath('issuedBy');
        $this->getJson("/api/portal/orders/{$staffOrder}")->assertOk()->assertJsonPath('notes', null);
    }

    public function test_monthly_transaction_history_uses_kuala_lumpur_boundaries_and_omits_staff_payment_fields(): void
    {
        $staff = $this->user('staff');
        $first = $this->hotel('First Hotel');
        $other = $this->hotel('Other Hotel');
        $owner = $this->user('hotel-owner', $first);

        $oldInvoice = $this->invoice($staff, $first, 'OLD', '2026-10-31 15:59:59', 10020);
        $novInvoice = $this->invoice($staff, $first, 'NOV', '2026-10-31 16:00:00', 8010);
        $this->invoice($staff, $first, 'DEC', '2026-11-30 16:00:00', 10000);
        $otherInvoice = $this->invoice($staff, $other, 'OTHER', '2026-11-01 00:00:00', 9000);
        $this->payment($staff, $oldInvoice, '2026-10-31', 1005);
        $novPayment = $this->payment($staff, $novInvoice, '2026-11-01', 4011, 'Private transfer');
        $this->payment($staff, $otherInvoice, '2026-11-01', 5001);

        $link = "/api/orders/{$novInvoice}/payments/{$novPayment}/receipt";
        $this->actingAs($staff)->getJson("/api/customers/{$first}/transactions?month=2026-11")
            ->assertOk()->assertJsonPath('month', '2026-11')
            ->assertJsonPath('openingBalance', 90.15)->assertJsonPath('invoicedTotal', 80.1)
            ->assertJsonPath('paidTotal', 40.11)->assertJsonPath('closingBalance', 130.14)
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.0.type', 'invoice')->assertJsonPath('entries.0.date', '2026-11-01')
            ->assertJsonPath('entries.0.invoiceNumber', 'NOV')->assertJsonPath('entries.0.balance', 170.25)
            ->assertJsonPath('entries.1.type', 'payment')->assertJsonPath('entries.1.amount', 40.11)
            ->assertJsonPath('entries.1.balance', 130.14)->assertJsonPath('entries.1.reference', 'Private transfer')
            ->assertJsonPath('entries.1.receiptUrl', $link);
        $this->getJson("/api/customers/{$first}/transactions?month=2026-12")
            ->assertOk()->assertJsonPath('openingBalance', 130.14)->assertJsonPath('entries.0.invoiceNumber', 'DEC');

        $this->actingAs($owner)->getJson("/api/customers/{$first}/transactions?month=2026-11")->assertForbidden();
        $this->getJson($link)->assertForbidden();
        $this->getJson("/api/portal/transactions?month=2026-11&customerId={$other}")
            ->assertOk()->assertJsonPath('openingBalance', 90.15)->assertJsonPath('closingBalance', 130.14)
            ->assertJsonCount(2, 'entries')
            ->assertJsonPath('entries.1.invoiceNumber', 'NOV')
            ->assertJsonMissingPath('entries.1.reference')->assertJsonMissingPath('entries.1.receiptUrl');
        $this->getJson('/api/portal/transactions?month=2026-13')->assertUnprocessable();
    }

    private function hotel(string $name): int
    {
        return DB::table('customers')->insertGetId(['name' => $name, 'phone' => '', 'notes' => 'Internal contact information.']);
    }

    private function user(string $name, ?int $hotel = null): User
    {
        $user = new User(['username' => $name, 'email' => $name.'@hotel.test', 'password' => 'local-test-only-account']);
        $user->customer_id = $hotel;
        $user->save();

        return $user;
    }

    private function invoice(User $staff, int $hotel, string $number, string $utc, int $total): int
    {
        return DB::table('orders')->insertGetId([
            'customer_id' => $hotel, 'customer_name' => 'Hotel', 'customer_phone' => '', 'status' => 'invoiced', 'total' => $total,
            'notes' => '', 'invoice_number' => $number, 'issued_at' => $utc, 'created_at' => $utc, 'updated_at' => $utc,
            'created_by' => $staff->id, 'updated_by' => $staff->id, 'issued_by' => $staff->id,
        ]);
    }

    private function payment(User $staff, int $order, string $date, int $amount, ?string $reference = null): int
    {
        return DB::table('invoice_payments')->insertGetId([
            'order_id' => $order, 'amount' => $amount, 'paid_on' => $date, 'reference' => $reference,
            'receipt_path' => $reference ? 'test-only-private-path.png' : null, 'receipt_mime' => $reference ? 'image/png' : null,
            'recorded_by' => $staff->id, 'created_at' => '2026-11-01 00:00:00',
        ]);
    }
}
