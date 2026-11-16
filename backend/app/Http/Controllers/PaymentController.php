<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PaymentController extends Controller
{
    private const IMAGE_EXTENSIONS = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const PRIVATE_CACHE = 'private, no-store';

    public function index(string $id): JsonResponse
    {
        $order = $this->invoice($id);

        return $this->json($this->summary((int) $order->id, (int) $order->total));
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $today = now('Asia/Kuala_Lumpur')->toDateString();
        $data = $request->validate([
            'amount' => ['required', 'regex:/\A[0-9]{1,13}(?:\.[0-9]{1,2})?\z/'],
            'paidOn' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'reference' => ['nullable', 'string', 'max:255'],
            'receipt' => ['nullable', 'file', 'max:5120'],
        ], [
            'amount.regex' => 'Enter a positive MYR amount with up to two decimal places.',
            'paidOn.before_or_equal' => 'Payment date cannot be in the future.',
            'receipt.max' => 'The receipt image must not exceed 5 MB.',
        ]);

        [$whole, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
        $amount = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Payment must be greater than zero.']);
        }
        $order = $this->invoice($id);
        $orderId = (int) $order->id;
        $file = $data['receipt'] ?? null;
        $path = null;
        $mime = null;
        $disk = Storage::disk('local');

        try {
            if ($file instanceof UploadedFile) {
                $mime = $this->receiptMime($file);
                $dir = 'invoice-receipts/'.$orderId;
                $name = Str::uuid().'.'.self::IMAGE_EXTENSIONS[$mime];
                $path = $dir.'/'.$name;
                abort_unless($disk->putFileAs($dir, $file, $name, 'private') === $path, 500, 'Could not save the receipt.');
            }

            $summary = DB::transaction(function () use ($request, $id, $orderId, $data, $amount, $path, $mime) {
                $invoice = $this->invoice($id, lock: true);
                $paid = (int) DB::table('invoice_payments')->where('order_id', $orderId)->sum('amount');
                $balance = (int) $invoice->total - $paid;
                abort_if($amount > $balance, 409, 'The payment exceeds the outstanding balance. Refresh the invoice to see the current balance.');

                DB::table('invoice_payments')->insert([
                    'order_id' => $orderId,
                    'amount' => $amount,
                    'paid_on' => $data['paidOn'],
                    'reference' => $data['reference'] ?? null,
                    'receipt_path' => $path,
                    'receipt_mime' => $mime,
                    'recorded_by' => $request->user()->id,
                    'created_at' => now('UTC')->toDateTimeString(),
                ]);

                return $this->summary($orderId, (int) $invoice->total);
            }, 3);
        } catch (Throwable $error) {
            if ($path !== null) {
                try {
                    if (! $disk->delete($path)) {
                        report('A receipt upload could not be cleaned up.');
                    }
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            throw $error;
        }

        return $this->json($summary, 201);
    }

    public function receipt(string $id, string $payment): StreamedResponse
    {
        $row = DB::table('invoice_payments')
            ->where('order_id', $id)
            ->where('id', $payment)
            ->first(['receipt_path', 'receipt_mime']);
        abort_unless($row && $row->receipt_path && isset(self::IMAGE_EXTENSIONS[$row->receipt_mime]), 404, 'Receipt not found.');
        $disk = Storage::disk('local');
        abort_unless($disk->exists($row->receipt_path), 404, 'Receipt not found.');

        return $disk->response($row->receipt_path, 'receipt.'.self::IMAGE_EXTENSIONS[$row->receipt_mime], [
            'Content-Type' => $row->receipt_mime,
            'Cache-Control' => self::PRIVATE_CACHE,
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    private function invoice(string $id, bool $lock = false): object
    {
        $query = DB::table('orders')->where('id', $id);
        $order = ($lock ? $query->lockForUpdate() : $query)->first(['id', 'status', 'total']);
        abort_unless($order, 404, 'Invoice not found.');
        abort_unless($order->status === 'invoiced', 409, 'Payments can only be recorded for issued invoices.');

        return $order;
    }

    private function receiptMime(UploadedFile $file): string
    {
        $mime = $file->getMimeType();
        $headers = @getimagesize($file->getPathname());
        if (! $headers || ! isset(self::IMAGE_EXTENSIONS[$mime]) || $headers['mime'] !== $mime
            || $headers[0] < 1 || $headers[1] < 1 || $headers[0] * $headers[1] > 64_000_000) {
            throw ValidationException::withMessages(['receipt' => 'Upload a valid JPEG, PNG, or WebP receipt image.']);
        }

        return $mime;
    }

    private function summary(int $orderId, int $invoiceTotal): array
    {
        $records = DB::table('invoice_payments as p')
            ->join('users as u', 'u.id', '=', 'p.recorded_by')
            ->where('p.order_id', $orderId)
            ->orderByDesc('p.id')
            ->get(['p.id', 'p.amount', 'p.paid_on', 'p.reference', 'p.receipt_path', 'p.created_at', 'u.username']);
        $paid = (int) $records->sum('amount');
        $balance = $invoiceTotal - $paid;

        return [
            'payments' => $records->map(fn ($row) => [
                'id' => (int) $row->id,
                'amount' => $row->amount / 100,
                'paidOn' => $row->paid_on,
                'reference' => $row->reference,
                'receiptUrl' => $row->receipt_path ? route('invoice-payments.receipt', ['id' => $orderId, 'payment' => $row->id], false) : null,
                'recordedBy' => $row->username,
                'createdAt' => CarbonImmutable::parse($row->created_at, 'UTC')->toISOString(),
            ])->all(),
            'paidTotal' => $paid / 100,
            'balance' => $balance / 100,
            'status' => $balance === 0 ? 'paid' : ($paid === 0 ? 'unpaid' : 'partially_paid'),
        ];
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => self::PRIVATE_CACHE]);
    }
}
