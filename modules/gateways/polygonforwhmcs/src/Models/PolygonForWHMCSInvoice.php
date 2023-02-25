<?php

namespace PolygonForWHMCS\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class PolygonForWHMCSInvoice extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'mod_polygonforwhmcs_pay_invoices';

    /**
     * The attributes that are mass assignable.
     *
     * @var string[]
     */
    protected $fillable = [
        'invoice_id',
        'to_address',
        'from_address',
        'transaction_id',
        'expires_on',
        'is_released',
        "start_block",
    ];

    /**
     * The attributes that should be cast to native type.
     *
     * @var string[]
     */
    protected $casts = [
        'expires_on' => 'datetime',
    ];

    /**
     * In use condition.
     *
     * @param   Builder  $builder
     *
     * @return  Builder
     */
    public function scopeInUse(Builder $builder): Builder
    {
        return $builder->where('is_released', false);
    }

    /**
     * Assoicated with an invoice.
     *
     * @param   string  $address
     * @param   int     $invoiceId
     * @param   int     $timeout
     *
     * @return  void
     */
    public function associate(string $address, int $invoiceId, int $timeout = 30, string $start_block)
    {
        $this->newQuery()->create([
            'to_address' => $address,
            'invoice_id' => $invoiceId,
            'expires_on' => Carbon::now()->addMinutes($timeout),
            'start_block' => $start_block,
        ]);
    }

    /**
     * Determine if there is an invoice within the validity period.
     *
     * @param   int  $invoiceId
     *
     * @return  \Illuminate\Support\Collection
     */
    public function validInvoice(int $invoiceId)
    {
        return $this->newQuery()
            ->where('invoice_id', $invoiceId)
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', false)
            ->first();
    }

    /**
     * Update the expires date.
     *
     * @param   int  $timeout
     *
     * @return  bool
     */
    public function renew(int $timeout = 30): bool
    {
        return $this->forceFill(['expires_on' => Carbon::now()->addMinutes($timeout)])->save();
    }

    /**
     * Mark all expired invoices as released.
     *
     * @return  void
     */
    public function markExpiredInvoiceAsReleased()
    {
        $this->newQuery()
            ->where('expires_on', '<=', Carbon::now())
            ->where('is_released', false)
            ->update([
                'is_released' => true,
            ]);
    }

    /**
     * Get all valid invoices.
     *
     * @return  \Illuminate\Support\Collection
     */
    public function getValidInvoices()
    {
        return $this->newQuery()
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', 0)
            ->get();
    }

    /**
     * Mark inovice as paid.
     *
     * @param  string  $fromAddress
     * @param  string  $transactionId
     *
     * @return  void
     */
    public function markAsPaid(string $fromAddress, string $transactionId)
    {
        $this->forceFill([
            'from_address' => $fromAddress,
            'transaction_id' => $transactionId,
            'is_released' => true,
        ])
            ->save();
    }

    /**
     * Get polygonforwhmcs pay valid invoice by invoice id.
     *
     * @param   int  $invoiceId
     *
     * @return  \Illuminate\Database\Eloquent\Model|null
     */
    public function firstValidByInvoiceId(int $invoiceId)
    {
        return $this->newQuery()
            ->where('invoice_id', $invoiceId)
            ->where('expires_on', '>', Carbon::now())
            ->where('is_released', 0)
            ->latest('id')
            ->first();
    }
}