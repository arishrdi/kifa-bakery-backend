<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CashRegister extends Model
{
    protected $fillable = [
        'outlet_id',
        'user_id',
        'amount',
        'status',
    ];

    public function outlet()
    {
        return $this->belongsTo(Outlet::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(CashRegisterTransaction::class);
    }

    public function addCash(float $amount, int $userId, int $shiftId, string $reason): CashRegisterTransaction
    {
        return DB::transaction(function () use ($amount, $userId, $shiftId, $reason) {
            // Update saldo
            $this->increment('balance', $amount);

            // Catat transaksi
            return $this->transactions()->create([
                'shift_id' => $shiftId,
                'user_id' => $userId,
                'type' => 'add',
                'amount' => $amount,
                'reason' => $reason
            ]);
        });
    }

    public function subtractCash(float $amount, int $userId, int $shiftId, string $reason): CashRegisterTransaction
    {
        return DB::transaction(function () use ($amount, $userId, $shiftId, $reason) {
            // Pastikan saldo cukup
            if ($this->balance < $amount) {
                throw new \Exception('Saldo kas tidak mencukupi');
            }

            // Update saldo
            $this->decrement('balance', $amount);

            // Catat transaksi
            return $this->transactions()->create([
                'shift_id' => $shiftId,
                'user_id' => $userId,
                'type' => 'remove',
                'amount' => $amount,
                'reason' => $reason
            ]);
        });
    }
}
