<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class HotelPortalController extends Controller
{
    private function hotelId(Request $request): int
    {
        $id = $request->user()?->customer_id;
        abort_unless($id, 403, 'A hotel account is required.');

        return (int) $id;
    }

    public function catalog(Request $request): JsonResponse
    {
        $hotelId = $this->hotelId($request);
        $hotel = DB::table('customers')->where('id', $hotelId)->first(['id', 'name']);
        abort_unless($hotel, 404, 'Hotel not found.');
        $rows = DB::table('products')
            ->join('product_units as units', 'units.product_id', '=', 'products.id')
            ->leftJoin('customer_prices as prices', function ($join) use ($hotelId) {
                $join->on('units.id', '=', 'prices.product_unit_id')->where('prices.customer_id', $hotelId);
            })
            ->where('products.active', true)
            ->orderBy('products.name')->orderBy('products.id')->orderBy('units.id')
            ->get(['products.id', 'products.name', 'products.sku', 'units.unit', DB::raw('COALESCE(prices.price, units.price) as effective_price')]);

        $products = $rows->groupBy('id')->map(function (Collection $units) {
            $product = $units->first();

            return [
                'id' => (int) $product->id, 'name' => $product->name, 'sku' => $product->sku ?? '',
                'units' => $units->map(fn ($unit) => ['unit' => $unit->unit, 'price' => $unit->effective_price / 100])->all(),
            ];
        })->values()->all();

        return $this->json(['hotel' => ['id' => $hotelId, 'name' => $hotel->name], 'products' => $products]);
    }

    public function index(Request $request): JsonResponse
    {
        $hotelId = $this->hotelId($request);

        return $this->json(DB::transaction(function () use ($hotelId) {
            $orders = $this->query($hotelId)->orderByDesc('id')->get();
            $items = DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->orderBy('position')
                ->get(['order_id', 'product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'line_total'])
                ->groupBy('order_id');

            return $orders->map(fn ($order) => $this->payload($order, $items->get($order->id, collect())))->all();
        }));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return $this->json($this->find($this->hotelId($request), $id));
    }

    public function store(Request $request, OrderDeskController $writer): JsonResponse
    {
        $hotelId = $this->hotelId($request);
        $orderId = $writer->createHotelOrder($request);

        return $this->json($this->find($hotelId, $orderId), 201);
    }

    private function query(int $hotelId): Builder
    {
        return DB::table('orders')->where('customer_id', $hotelId)
            ->select(['id', 'customer_id', 'customer_name', 'status', 'source', 'portal_notes', 'total', 'invoice_number', 'issued_at', 'created_at', 'updated_at']);
    }

    private function find(int $hotelId, string|int $id): array
    {
        return DB::transaction(function () use ($hotelId, $id) {
            $order = $this->query($hotelId)->where('id', $id)->first();
            abort_unless($order, 404, 'Order not found.');
            $items = DB::table('order_items')->where('order_id', $order->id)->orderBy('position')
                ->get(['product_id', 'product_name', 'unit', 'quantity', 'unit_price', 'line_total']);

            return $this->payload($order, $items);
        });
    }

    private function isoDate(?string $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value, 'UTC')->toISOString();
    }

    private function payload(object $order, Collection $items): array
    {
        return [
            'id' => (int) $order->id,
            'customerId' => (int) $order->customer_id,
            'customerName' => $order->customer_name,
            'status' => $order->status,
            'source' => $order->source,
            'notes' => $order->portal_notes,
            'total' => $order->total / 100,
            'invoiceNumber' => $order->invoice_number,
            'issuedAt' => $this->isoDate($order->issued_at),
            'createdAt' => $this->isoDate($order->created_at),
            'updatedAt' => $this->isoDate($order->updated_at),
            'items' => $items->map(fn ($line) => [
                'productId' => (int) $line->product_id,
                'productName' => $line->product_name,
                'unit' => $line->unit,
                'quantity' => $line->quantity / 1000,
                'unitPrice' => $line->unit_price / 100,
                'lineTotal' => $line->line_total / 100,
            ])->all(),
        ];
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status, ['Cache-Control' => 'private, no-store']);
    }
}
