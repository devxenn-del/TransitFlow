<?php

namespace App\Models;

use App\Enums\ThermalPrinterStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Support\IdSequence;
use Database\Factories\ThermalPrinterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

/**
 * A platform-issued thermal printer. The Super Admin owns the inventory
 * (App\Http\Controllers\Api\SuperAdmin\ThermalPrinterController) and issues
 * each unit to a company and then to one of that company's buses; the
 * company only views what it was issued. `company_id` is null while the
 * printer is unissued, so CompanyScope hides it from every company.
 *
 * `thermal_id` / `device_id` are generated once on registration
 * (generateIdentifiers()) and never change.
 */
class ThermalPrinter extends Model
{
    /** @use HasFactory<ThermalPrinterFactory> */
    use BelongsToCompany, HasFactory;

    /** One shared counter numbers both IDs, so a printer's pair always matches. */
    public const SEQUENCE = 'thermal_printers';

    /** The six-digit `XXXXXX` part of an ID tops out here. */
    public const MAX_SEQUENCE = 999999;

    public const THERMAL_ID_PATTERN = '/^TFTP-\d{6}-\d{6}$/';

    public const DEVICE_ID_PATTERN = '/^TFD-\d{6}-\d{6}$/';

    public const MAC_ADDRESS_PATTERN = '/^([0-9A-F]{2}:){5}[0-9A-F]{2}$/';

    /**
     * "aa-bb-cc-dd-ee-ff", "AABB.CCDD.EEFF", "aabbccddeeff" → "AA:BB:CC:DD:EE:FF".
     * Anything that isn't 12 hex digits in a recognised layout comes back
     * trimmed but otherwise untouched, so validation can reject it.
     */
    public static function normalizeMac(?string $mac): ?string
    {
        $mac = trim((string) $mac);

        if ($mac === '') {
            return null;
        }

        if (! preg_match('/^[0-9A-Fa-f]{2}([:\-.]?[0-9A-Fa-f]{2}){5}$/', $mac) && ! preg_match('/^([0-9A-Fa-f]{4}\.){2}[0-9A-Fa-f]{4}$/', $mac)) {
            return $mac;
        }

        return implode(':', str_split(strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $mac)), 2));
    }

    protected $fillable = [
        'model',
        'mac_address',
        'serial_number',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'status' => ThermalPrinterStatus::class,
            'date_issued' => 'date',
        ];
    }

    /**
     * Fill in a fresh Thermal ID / Device ID pair: `TFTP-DDMMYY-XXXXXX` and
     * `TFD-DDMMYY-XXXXXX`, dated today in the app timezone and numbered from
     * the transactional `thermal_printers` sequence. Call inside the
     * transaction that inserts the printer.
     */
    public function generateIdentifiers(): void
    {
        $suffix = now()->format('dmy').'-'.str_pad((string) IdSequence::next(self::SEQUENCE, self::MAX_SEQUENCE), 6, '0', STR_PAD_LEFT);

        $this->thermal_id = "TFTP-{$suffix}";
        $this->device_id = "TFD-{$suffix}";
    }

    /**
     * The statuses this printer can be moved to right now — its status's
     * transitions, narrowed by whether it is issued to a company (see
     * ChangeThermalPrinterStatus). Lets the UI offer only valid choices.
     *
     * @return list<ThermalPrinterStatus>
     */
    public function statusOptions(): array
    {
        $hasCompany = $this->company_id !== null;

        return array_values(array_filter(
            $this->status->allowedTransitions(),
            fn (ThermalPrinterStatus $target) => match ($target) {
                ThermalPrinterStatus::Available, ThermalPrinterStatus::Retired => ! $hasCompany,
                ThermalPrinterStatus::Assigned => $hasCompany,
                default => true,
            },
        ));
    }

    /**
     * Append a row to this printer's assignment history. `$from` / `$to`
     * carry the company, bus and status on either side of the change.
     *
     * @param  array{company_id?: ?int, bus?: ?Bus, status?: ?ThermalPrinterStatus}  $from
     * @param  array{company_id?: ?int, bus?: ?Bus, status?: ?ThermalPrinterStatus}  $to
     */
    public function recordHistory(string $action, array $from, array $to, ?string $remarks = null): ThermalPrinterAssignment
    {
        $actor = Auth::user();

        return $this->assignments()->create([
            'action' => $action,
            'from_company_id' => $from['company_id'] ?? null,
            'to_company_id' => $to['company_id'] ?? null,
            'from_bus_id' => ($from['bus'] ?? null)?->id,
            'to_bus_id' => ($to['bus'] ?? null)?->id,
            'from_bus_label' => ($from['bus'] ?? null)?->bus_number,
            'to_bus_label' => ($to['bus'] ?? null)?->bus_number,
            'from_status' => ($from['status'] ?? null)?->value,
            'to_status' => ($to['status'] ?? null)?->value,
            'remarks' => $remarks,
            'user_id' => $actor?->id,
            'user_name' => $actor?->name,
            'created_at' => now(),
        ]);
    }

    /**
     * The bus this printer is assigned to. Buses are company-owned, so the
     * Super Admin side reads it with CompanyScope lifted.
     *
     * @return BelongsTo<Bus, $this>
     */
    public function bus(): BelongsTo
    {
        return $this->belongsTo(Bus::class);
    }

    /**
     * @return HasMany<ThermalPrinterAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ThermalPrinterAssignment::class);
    }

    /**
     * Free-text search across both IDs, serial, MAC, model, and the
     * assigned company / bus.
     *
     * @param  Builder<ThermalPrinter>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $q) => $q
            ->where('thermal_printers.thermal_id', 'like', $like)
            ->orWhere('thermal_printers.device_id', 'like', $like)
            ->orWhere('thermal_printers.serial_number', 'like', $like)
            ->orWhere('thermal_printers.mac_address', 'like', $like)
            ->orWhere('thermal_printers.model', 'like', $like)
            ->orWhereHas('company', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('code', 'like', $like))
            ->orWhereHas('bus', fn (Builder $b) => $b->withoutCompanyScope()
                ->where(fn (Builder $bq) => $bq->where('bus_number', 'like', $like)->orWhere('plate_number', 'like', $like))));
    }
}
