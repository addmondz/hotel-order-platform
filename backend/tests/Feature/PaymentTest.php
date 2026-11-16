<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/P14AAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00', 'Asia/Kuala_Lumpur'));
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_only_staff_can_view_or_record_payments_and_open_orders_cannot_be_paid(): void
    {
        $staff = $this->staff();
        $open = $this->order($staff, 'open');
        $invoice = $this->order($staff);
        $this->getJson("/api/orders/{$invoice}/payments")->assertUnauthorized();
        $this->post("/api/orders/{$invoice}/payments", $this->input(), ['Accept' => 'application/json'])->assertUnauthorized();
        $this->get("/api/orders/{$invoice}/payments/1/receipt")->assertUnauthorized();

        $this->actingAs($staff);
        $this->post("/api/orders/{$open}/payments", $this->input(), ['Accept' => 'application/json'])->assertConflict();
        $this->getJson('/api/orders/9999/payments')->assertNotFound();
        $this->assertDatabaseCount('invoice_payments', 0);
    }

    public function test_multiple_payments_apply_exact_cents_without_mutating_invoice(): void
    {
        $staff = $this->staff();
        $invoice = $this->order($staff, total: 10030);
        $url = "/api/orders/{$invoice}/payments";

        $this->actingAs($staff)->getJson($url)
            ->assertOk()
            ->assertExactJson(['payments' => [], 'paidTotal' => 0, 'balance' => 100.30, 'status' => 'unpaid']);

        $this->post($url, $this->input(['amount' => '35.15', 'reference' => 'Transfer A']), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'partially_paid')->assertJsonPath('paidTotal', 35.15)->assertJsonPath('balance', 65.15)
            ->assertJsonPath('payments.0.amount', 35.15)->assertJsonPath('payments.0.reference', 'Transfer A')
            ->assertJsonPath('payments.0.paidOn', '2026-11-16')->assertJsonPath('payments.0.recordedBy', $staff->username)
            ->assertJsonPath('payments.0.receiptUrl', null);
        $second = $this->staff('cashier');
        $this->actingAs($second)->post($url, $this->input(['amount' => '65.15']), ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('status', 'paid')->assertJsonPath('paidTotal', 100.30)->assertJsonPath('balance', 0)
            ->assertJsonCount(2, 'payments')->assertJsonPath('payments.0.recordedBy', 'cashier');

        $this->assertDatabaseHas('invoice_payments', ['order_id' => $invoice, 'recorded_by' => $staff->id, 'amount' => 3515]);
        $this->assertDatabaseHas('invoice_payments', ['order_id' => $invoice, 'recorded_by' => $second->id, 'amount' => 6515]);
        $this->assertDatabaseHas('orders', ['id' => $invoice, 'total' => 10030, 'status' => 'invoiced', 'revision' => 3]);
        $this->assertSame('paid', $this->getJson($url)->json('status'));
    }

    public function test_overpayment_rejects_second_write_and_cleans_up_a_staged_receipt(): void
    {
        $staff = $this->staff();
        $invoice = $this->order($staff, total: 10000);
        $url = "/api/orders/{$invoice}/payments";
        $this->actingAs($staff)->post($url, $this->input(['amount' => '80']), ['Accept' => 'application/json'])->assertCreated();
        $this->post($url, $this->input(['amount' => '20.01', 'receipt' => $this->png()]), ['Accept' => 'application/json'])
            ->assertConflict();

        $this->assertDatabaseCount('invoice_payments', 1);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->getJson($url)->assertJsonPath('balance', 20);
    }

    public function test_receipt_can_only_be_retrieved_from_its_authenticated_invoice_path(): void
    {
        $staff = $this->staff();
        $invoice = $this->order($staff);
        $other = $this->order($staff);
        $url = "/api/orders/{$invoice}/payments";
        $created = $this->actingAs($staff)
            ->post($url, $this->input(['receipt' => $this->png()]), ['Accept' => 'application/json'])->assertCreated();
        $receiptId = $created->json('payments.0.id');
        $privateUrl = "{$url}/{$receiptId}/receipt";
        $created->assertJsonPath('payments.0.receiptUrl', $privateUrl);
        $this->assertStringContainsString('no-store', $created->headers->get('Cache-Control'));
        $this->assertCount(1, Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $savedPath = DB::table('invoice_payments')->where('id', $receiptId)->value('receipt_path');
        $this->assertStringStartsWith("invoice-receipts/{$invoice}/", $savedPath);
        $this->assertNotNull($savedPath);
        $this->assertStringNotContainsString($savedPath, $created->getContent());

        $sameInvoice = $this->get($privateUrl)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
        $this->assertStringContainsString('private', $sameInvoice->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $sameInvoice->headers->get('Cache-Control'));
        $this->assertSame(base64_decode(self::PNG), $sameInvoice->streamedContent());
        $this->get("/api/orders/{$other}/payments/{$receiptId}/receipt")->assertNotFound();
        $this->assertContains($this->get("/storage/{$savedPath}")->getStatusCode(), [403, 404]);
        $this->app['auth']->forgetGuards();
        $this->get($privateUrl)->assertUnauthorized();
    }

    public function test_amount_date_reference_and_receipt_content_are_validated(): void
    {
        $staff = $this->staff();
        $invoice = $this->order($staff);
        $url = "/api/orders/{$invoice}/payments";
        $this->actingAs($staff);
        foreach (['0', '-1', '1.505', '1e2', '1,20'] as $amount) {
            $this->post($url, $this->input(['amount' => $amount]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('amount');
        }
        $this->post($url, $this->input(['paidOn' => '2026-02-29', 'reference' => str_repeat('X', 256)]), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['paidOn', 'reference']);
        $this->post($url, $this->input(['paidOn' => '2026-11-18']), ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('paidOn');
        foreach ([
            UploadedFile::fake()->createWithContent('not-a-picture.png', '<html>Not an image</html>'),
            UploadedFile::fake()->createWithContent('vector.svg', '<svg xmlns="http://www.w3.org/2000/svg" />'),
            UploadedFile::fake()->create('too-large.jpg', 5121, 'image/jpeg'),
        ] as $receipt) {
            $this->post($url, $this->input(['receipt' => $receipt]), ['Accept' => 'application/json'])
                ->assertUnprocessable()->assertJsonValidationErrors('receipt');
        }
        $this->assertDatabaseCount('invoice_payments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.png', base64_decode(self::PNG));
    }

    private function input(array $changes = []): array
    {
        return $changes + ['amount' => '20.05', 'paidOn' => '2026-11-16'];
    }

    private function staff(string $username = 'manager'): User
    {
        return User::create(['username' => $username, 'password' => 'local-test-only-account']);
    }

    private function order(User $staff, string $status = 'invoiced', int $total = 10000): int
    {
        $customer = DB::table('customers')->insertGetId(['name' => 'Hotel', 'phone' => '', 'notes' => '']);
        $now = now('UTC')->toDateTimeString();

        return DB::table('orders')->insertGetId([
            'customer_id' => $customer, 'customer_name' => 'Hotel', 'customer_phone' => '',
            'status' => $status, 'notes' => '', 'total' => $total, 'revision' => 3,
            'invoice_number' => $status === 'invoiced' ? 'INV-'.str_pad((string) $customer, 6, '0', STR_PAD_LEFT) : null,
            'issued_at' => $status === 'invoiced' ? $now : null,
            'issued_by' => $status === 'invoiced' ? $staff->id : null,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $staff->id, 'updated_by' => $staff->id,
        ]);
    }
}
