<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Inventory;
use App\Models\InventoryHistory;
use App\Models\Order;
use App\Traits\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OrderController extends Controller
{
    use ApiResponse;
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function storeOld(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'shift_id' => 'required|exists:shifts,id',
            'items' => 'required|array', // Array of items
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,card,transfer,other',
            'notes' => 'nullable|string',
            'total_paid' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
        ]);


        try {
            DB::beginTransaction();
            $subtotal = collect($request->items)->sum(function ($item) {
                return $item['quantity'] * $item['price'];
            });

            // Hitung total (subtotal + tax - discount)
            $tax = $request->tax ?? 0;
            $discount = $request->discount ?? 0;
            $total = $subtotal + $tax - $discount;
            $change = $request->total_paid - $total;
            // Buat order
            $order = Order::create([
                'order_number' => 'INV-' . time() . '-' . Str::random(6),
                'outlet_id' => $request->outlet_id,
                'user_id' => $request->user()->id,
                'shift_id' => $request->shift_id,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'discount' => $discount,
                'total' => $total,
                'total_paid' => $request->total_paid ?? $total,
                'change' => $change,
                'payment_method' => $request->payment_method,
                'status' => 'pending',
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ]);

                $inventory = Inventory::where('outlet_id', $request->outlet_id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if ($inventory) {
                    $quantityBefore = $inventory->quantity;
                    $inventory->quantity -= $item['quantity']; // Kurangi stok
                    $inventory->save();

                    InventoryHistory::create([
                        'outlet_id' => $request->outlet_id,
                        'product_id' => $item['product_id'],
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $inventory->quantity,
                        'quantity_change' => -$item['quantity'], // Nilai minus karena pengurangan
                        'type' => 'sale',
                        'notes' => 'Penjualan melalui POS, Invoice #' . $order->order_number,
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            $order->update(['status' => 'completed']);

            DB::commit();

            return $this->successResponse($order, 'Order berhasil dibuat');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'outlet_id' => 'required|exists:outlets,id',
            'shift_id' => 'required|exists:shifts,id',
            'items' => 'required|array',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|numeric|min:0',
            'payment_method' => 'required|in:cash,qris',
            'notes' => 'nullable|string',
            'total_paid' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'discount' => 'nullable|numeric|min:0',
        ]);

        try {
            DB::beginTransaction();

            // Hitung subtotal dari semua items
            $subtotal = collect($request->items)->sum(function ($item) {
                return $item['quantity'] * $item['price'];
            });

            // Hitung total (subtotal + tax - discount)
            $tax = $request->tax ?? 0;
            $discount = $request->discount ?? 0;
            $total = $subtotal + $tax - $discount;
            $change = ($request->total_paid ?? 0) - $total;

            $totalPaid = $request->total_paid;

            if ($request->payment_method === 'qris') {
                $totalPaid = $total;
                $change = 0;
            }

            // Buat order dengan subtotal
            $order = Order::create([
                'order_number' => 'INV-' . time() . '-' . strtoupper(Str::random(6)),
                'outlet_id' => $request->outlet_id,
                'user_id' => $request->user()->id,
                'shift_id' => $request->shift_id,
                'subtotal' => $subtotal,  // Subtotal disimpan di sini
                'tax' => $tax,
                'discount' => $discount,
                'total' => $total,
                'total_paid' => $totalPaid,
                'change' => $change,
                'payment_method' => $request->payment_method,
                'status' => 'pending',
                'notes' => $request->notes,
            ]);

            // Buat order items TANPA subtotal
            foreach ($request->items as $item) {
                $subtotal = $item['quantity'] * $item['price'];
                $order->items()->create([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                    'subtotal' => $subtotal,
                ]);

                // Update inventory
                $inventory = Inventory::where('outlet_id', $request->outlet_id)
                    ->where('product_id', $item['product_id'])
                    ->first();

                if ($inventory) {
                    $quantityBefore = $inventory->quantity;
                    $inventory->decrement('quantity', $item['quantity']);

                    InventoryHistory::create([
                        'outlet_id' => $request->outlet_id,
                        'product_id' => $item['product_id'],
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $inventory->quantity,
                        'quantity_change' => -$item['quantity'],
                        'type' => 'sale',
                        'notes' => 'Penjualan POS, Invoice #' . $order->order_number,
                        'user_id' => $request->user()->id,
                    ]);
                }
            }

            $cashRegister = CashRegister::where('outlet_id', $request->outlet_id)->first();
            $cashRegister->addCash($total, $request->user()->id, $request->shift_id, 'Penjualan POS, Invoice #' . $order->order_number, 'pos');

            $order->update(['status' => 'completed']);

            DB::commit();

            return $this->successResponse($order->load('items'), 'Order berhasil dibuat');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Order $order)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Order $order)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Order $order)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Order $order)
    {
        //
    }

    public function cancelOrder($orderId)
    {
        // Mulai transaksi
        DB::beginTransaction();

        try {
            $order = Order::find($orderId);

            if (!$order) {
                return $this->errorResponse('Order tidak ditemukan', 404);
            }

            // Kembalikan stok produk
            foreach ($order->items as $item) {
                $inventory = Inventory::where('outlet_id', $order->outlet_id)
                    ->where('product_id', $item->product_id)
                    ->first();

                if ($inventory) {
                    $quantityBefore = $inventory->quantity;
                    $inventory->quantity += $item->quantity; // Tambahkan stok kembali
                    $inventory->save();

                    // Catat riwayat perubahan stok
                    InventoryHistory::create([
                        'outlet_id' => $order->outlet_id,
                        'product_id' => $item->product_id,
                        'quantity_before' => $quantityBefore,
                        'quantity_after' => $inventory->quantity,
                        'quantity_change' => $item->quantity, // Nilai positif karena penambahan
                        'type' => 'sale',
                        'notes' => 'Pembatalan Order #' . $order->order_number,
                        'user_id' => $order->user_id,
                    ]);
                }
            }

            // Update status order menjadi cancelled
            $order->update(['status' => 'cancelled']);

            $cashRegister = CashRegister::where('outlet_id', $order->outlet_id)->first();
            $cashRegister->subtractCash($order->total, $order->user_id, $order->shift_id, 'Pembatalan Order #' . $order->order_number, 'pos');

            // Commit transaksi jika semua operasi berhasil
            DB::commit();

            return $this->successResponse($order, 'Order berhasil dibatalkan');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->errorResponse($e->getMessage());
        }
    }

    public function orderHistory(Request $request)
    {
        try {
            // Validasi parameter query
            $validator = Validator::make($request->query(), [
                'outlet_id' => 'nullable|exists:outlets,id',
                // 'status' => 'nullable|in:pending,completed,canceled',
                'date_from' => 'nullable|date',
                'date_to' => 'nullable|date|after_or_equal:date_from',
                // 'per_page' => 'nullable|integer|min:1|max:100',
                // 'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            // $user = $request->user();

            // Query dasar
            $query = Order::query();

            // Terapkan filter tambahan berdasarkan permintaan
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }
            if ($request->filled('date_from') && $request->filled('date_to')) {
                $query->whereBetween('created_at', [
                    $request->date_from,
                    $request->date_to . ' 23:59:59'
                ]);
            }
            // if ($request->filled('search')) {
            //     $searchTerm = '%' . $request->search . '%';
            //     $query->where('order_number', 'like', $searchTerm);
            // }

            // Hitung total jumlah pesanan dan total pendapatan
            $totalOrders = $query->count();
            // $totalRevenue = $query->sum('total');
            $totalRevenue = (clone $query)->where('status', 'completed')->sum('total');

            // Paginasi hasil
            // $perPage = $request->per_page ?? 10;
            // $orders = $query->with([
            //     'items.product:id,name,sku',
            //     'outlet:id,name',
            //     'shift:id',
            //     'user:id,name'
            // ])->latest()->get();

            $orders = $query->with([
                'items.product:id,name,sku',
                'outlet:id,name',
                'shift:id',
                'user:id,name'
            ])->has('outlet')->has('user')->latest()->get();

            // Transformasi respons
            $orders->transform(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'outlet' => $order->outlet->name,
                    'user' => $order->user->name,
                    'total' => $order->total,
                    'status' => $order->status,

                    'subtotal' => $order->subtotal,
                    'tax' => $order->tax,
                    'discount' => $order->discount,
                    'total_paid' => $order->total_paid,
                    'change' => $order->change,

                    'payment_method' => $order->payment_method,
                    'created_at' => $order->created_at->format('d/m/Y H:i'),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product' => $item->product->name,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'total' => $item->quantity * $item->price
                        ];
                    })
                ];
            });

            // Tambahkan informasi total ke dalam respons
            $response = [
                'date_from' => date('d-m-Y', strtotime($request->date_from)),
                'date_to' => date('d-m-Y', strtotime($request->date_to)),
                'total_orders' => $totalOrders,
                'total_revenue' => $totalRevenue,
                'orders' => $orders
            ];

            return $this->successResponse($response, 'Riwayat order berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse('Terjadi kesalahan: ' . $e->getMessage());
        }
    }

    public function orderHistoryAdmin(Request $request)
    {
        try {
            // Validasi parameter query (hapus validasi date dan per_page)
            $validator = Validator::make($request->query(), [
                'outlet_id' => 'nullable|exists:outlets,id',
                'status' => 'nullable|in:pending,completed,canceled',
                'search' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return $this->errorResponse($validator->errors(), 422);
            }

            $user = $request->user();

            // Query dasar
            $query = Order::query();

            // Filter tambahan
            if ($request->filled('outlet_id')) {
                $query->where('outlet_id', $request->outlet_id);
            }

            if ($request->filled('status')) {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $searchTerm = '%' . $request->search . '%';
                $query->where('order_number', 'like', $searchTerm);
            }

            // Hitung total jumlah pesanan dan total pendapatan
            $totalOrders = $query->count();
            $totalRevenue = $query->sum('total');

            // Ambil semua hasil (tanpa pagination)
            $orders = $query->with([
                'items.product:id,name,sku',
                'outlet:id,name',
                'shift:id',
                'user:id,name'
            ])->latest()->get();

            // Transformasi data
            $orders = $orders->map(function ($order) {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'outlet' => $order->outlet->name,
                    'user' => $order->user->name,
                    'total' => $order->total,
                    'status' => $order->status,

                    'subtotal' => $order->subtotal,
                    'tax' => $order->tax,
                    'discount' => $order->discount,
                    'total_paid' => $order->total_paid,
                    'change' => $order->change,

                    'payment_method' => $order->payment_method,
                    'created_at' => $order->created_at->format('d/m/Y H:i'),
                    'items' => $order->items->map(function ($item) {
                        return [
                            'product' => $item->product->name,
                            'quantity' => $item->quantity,
                            'price' => $item->price,
                            'total' => $item->quantity * $item->price
                        ];
                    })
                ];
            });

            $response = [
                'total_orders' => $totalOrders,
                'total_revenue' => $totalRevenue,
                'orders' => $orders
            ];

            return $this->successResponse($response, 'Riwayat order berhasil diambil');
        } catch (\Exception $e) {
            return $this->errorResponse('Terjadi kesalahan: ' . $e->getMessage());
        }
    }
}
