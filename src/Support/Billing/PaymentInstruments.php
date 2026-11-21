<?php

namespace Ssuite\Core\Support\Billing;

use App\Models\PaymentInstrument;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payment\PaymentGatewayFactory;
use App\Services\Payment\SupportsRecurringPayments;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Ssuite\Core\Notifications\OwnershipPaymentMethodsRemoved;

/** Shared consent removal for owner transfers and the ordinary remove-card flow. */
class PaymentInstruments
{
    public static function belongsToOwner(PaymentInstrument $instrument, Tenant $tenant): bool
    {
        return $instrument->product === config('app.product_key')
            && (int) $instrument->tenant_id === (int) $tenant->id
            && (int) $instrument->user_id === (int) $tenant->user_id;
    }

    /** Called under the workspace lock before the new ownership is published. */
    public function ownershipChanged(Tenant $tenant, int $outgoingId, int $incomingId): void
    {
        if ($outgoingId === $incomingId) {
            return;
        }

        $count = $this->revoke(PaymentInstrument::forProduct()
            ->where('tenant_id', $tenant->id)->where('user_id', $outgoingId));

        if ($count > 0) {
            $tenant->getConnection()->afterCommit(function () use ($tenant, $outgoingId, $incomingId): void {
                foreach ([$outgoingId, $incomingId] as $id) {
                    rescue(fn () => User::find($id)?->notify(new OwnershipPaymentMethodsRemoved($tenant->name)), null, false);
                }
            });
        }
    }

    /** Local consent is revoked before any external best-effort gateway cleanup. */
    public function revoke(Builder $query): int
    {
        $instruments = DB::connection('central')->transaction(function () use ($query) {
            $rows = $query->whereIn('status', ['active', 'pending'])->lockForUpdate()->get();
            foreach ($rows as $instrument) {
                $instrument->update(['status' => 'revoked', 'revoked_at' => now()]);
                Subscription::query()->where('product', $instrument->product)
                    ->where('payment_instrument_id', $instrument->id)
                    ->update(['auto_renew' => false, 'payment_instrument_id' => null]);
            }

            return $rows;
        });

        // Ownership and renewal share the workspace lock. Do not call a gateway
        // or notify an owner before that app transaction actually commits.
        DB::connection()->afterCommit(function () use ($instruments): void {
            foreach ($instruments as $instrument) {
                try {
                    $gateway = PaymentGatewayFactory::make($instrument->gateway);
                    if ($gateway instanceof SupportsRecurringPayments && filled($instrument->token)
                        && ! $gateway->revokeInstrument((string) $instrument->token)) {
                        Log::warning('Payment instrument gateway revocation requires review', ['payment_instrument_id' => $instrument->id]);
                    }
                } catch (\Throwable $exception) {
                    Log::warning('Payment instrument gateway revocation requires review', [
                        'payment_instrument_id' => $instrument->id, 'exception_class' => $exception::class,
                    ]);
                }
            }
        });

        return $instruments->count();
    }
}
