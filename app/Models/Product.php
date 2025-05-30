<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, SoftDeletes;
    protected $fillable = [
        'name',
        'sku',
        'description',
        'category_id',
        'price',
        'image',
        // 'outlet_id',
        'is_active',
        'unit'
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $appends = ['image_url']; // Tambahkan atribut image_url ke JSON

    // Accessor untuk image_url
    public function getImageUrlAttribute()
    {
        return $this->image ? asset('uploads/' . $this->image) : null;
    }

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    // public function outlets()
    // {
    //     return $this->belongsToMany(Outlet::class, 'inventories')
//         ->withPivot('quantity'); // Ambil kolom quantity dari tabel pivot
    // }

    public function outlets()
    {
        return $this->belongsToMany(Outlet::class, 'inventories')
            ->withPivot(['quantity', 'min_stock']); // Tambahkan 'min_stock'
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function inventory()
    {
        return $this->hasOne(Inventory::class);
    }

    public function inventoryHistory()
    {
        return $this->hasMany(InventoryHistory::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }
}
