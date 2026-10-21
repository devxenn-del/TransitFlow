<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Thermal printers become a platform-wide inventory the Super Admin issues
 * to companies and then to individual buses (Thermal Printer Management);
 * a company only views what it was issued.
 *
 * In place, non-destructively:
 * - adds the generated `thermal_id` (TFTP-DDMMYY-XXXXXX) and turns
 *   `device_id` into the generated TFD-DDMMYY-XXXXXX — both unique across
 *   every company. Each existing printer gets a pair dated by its own
 *   `created_at`; its old free-text device ID moves to `serial_number`;
 * - `company_id` becomes nullable (an Available printer belongs to no one)
 *   and no longer cascades — deleting a company must not erase inventory;
 * - adds the `bus_id` assignment, `date_issued` and `remarks`;
 * - maps statuses: Active → Assigned, Inactive → Under Maintenance;
 * - normalizes MAC addresses (AA:BB:CC:DD:EE:FF) and makes them unique; an
 *   unparseable or duplicate one is moved into `remarks` instead of lost;
 * - retires the conductor link: each `users.thermal_printer_id` is written
 *   to the printer's history and cleared. The column itself is kept.
 */
return new class extends Migration
{
    private const SEQUENCE = 'thermal_printers';

    public function up(): void
    {
        // Read the conductor links first: altering the table can null the
        // `users.thermal_printer_id` FK on some drivers (SQLite rebuilds it).
        $holders = DB::table('users')->whereNotNull('thermal_printer_id')->get(['id', 'name', 'thermal_printer_id'])->keyBy('thermal_printer_id');

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->string('thermal_id', 18)->nullable()->after('id');
            $table->string('serial_number', 40)->nullable()->after('device_id');
            $table->foreignId('bus_id')->nullable()->after('company_id')->constrained('buses')->nullOnDelete();
            $table->date('date_issued')->nullable()->after('status');
            $table->text('remarks')->nullable()->after('date_issued');
            $table->string('status', 20)->default('Available')->change();
        });

        $history = $this->backfill($holders);

        // The FK on company_id leans on the (company_id, device_id) unique
        // index in MySQL, so it is dropped first and recreated afterwards.
        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
        });

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'device_id']);
            $table->string('thermal_id', 18)->nullable(false)->change();
            $table->unsignedBigInteger('company_id')->nullable()->change();
        });

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->nullOnDelete();
            $table->unique('thermal_id');
            $table->unique('device_id');
            $table->unique('mac_address');
            $table->index('status');
        });

        DB::table('thermal_printer_assignments')->insert($history);
    }

    public function down(): void
    {
        if (DB::table('thermal_printers')->whereNull('company_id')->exists()) {
            throw new RuntimeException('Cannot roll back: some thermal printers belong to no company, which the previous schema cannot hold. Assign or remove them first.');
        }

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropUnique(['thermal_id']);
            $table->dropUnique(['device_id']);
            $table->dropUnique(['mac_address']);
            $table->dropIndex(['status']);
        });

        DB::table('thermal_printers')->whereNotNull('serial_number')->update(['device_id' => DB::raw('serial_number')]);
        DB::table('thermal_printers')->update([
            'status' => DB::raw("CASE WHEN status = 'Assigned' THEN 'Active' ELSE 'Inactive' END"),
        ]);

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bus_id');
            $table->dropColumn(['thermal_id', 'serial_number', 'date_issued', 'remarks']);
            $table->unsignedBigInteger('company_id')->nullable(false)->change();
            $table->string('status', 10)->default('Active')->change();
        });

        Schema::table('thermal_printers', function (Blueprint $table) {
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->unique(['company_id', 'device_id']);
        });

        DB::table('id_sequences')->where('name', self::SEQUENCE)->delete();
    }

    /**
     * Give every existing printer its generated IDs, new status, normalized
     * MAC, and clear the conductor links being retired. Returns their
     * history rows, which up() writes once the table is in its final shape.
     *
     * @param  Collection<int|string, object>  $holders  conductor rows keyed by `thermal_printer_id`
     * @return list<array<string, mixed>>
     */
    private function backfill(Collection $holders): array
    {
        $now = now();
        $sequence = 0;
        $seenMacs = [];
        $history = [];

        DB::table('thermal_printers')->orderBy('id')->get()->each(function (object $printer) use (&$sequence, &$seenMacs, &$history, $now, $holders): void {
            $sequence++;
            $created = $printer->created_at ? Carbon::parse($printer->created_at) : $now;
            $suffix = $created->format('dmy').'-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

            $remarks = [];
            $mac = $this->normalizeMac($printer->mac_address);
            if ($printer->mac_address !== null && $printer->mac_address !== '' && ($mac === null || isset($seenMacs[$mac]))) {
                $remarks[] = "Legacy MAC address (invalid or duplicate): {$printer->mac_address}";
                $mac = null;
            }
            if ($mac !== null) {
                $seenMacs[$mac] = true;
            }

            DB::table('thermal_printers')->where('id', $printer->id)->update([
                'thermal_id' => "TFTP-{$suffix}",
                'device_id' => "TFD-{$suffix}",
                'serial_number' => $printer->device_id,
                'mac_address' => $mac,
                'status' => $printer->status === 'Active' ? 'Assigned' : 'Under Maintenance',
                'remarks' => $remarks ? implode("\n", $remarks) : null,
            ]);

            $holder = $holders->get($printer->id);
            if ($holder !== null) {
                $history[] = [
                    'thermal_printer_id' => $printer->id,
                    'action' => 'conductor_unlinked',
                    'from_company_id' => $printer->company_id,
                    'to_company_id' => $printer->company_id,
                    'remarks' => "Printers are now assigned to buses. Previously held by conductor {$holder->name} (user #{$holder->id}).",
                    'user_name' => 'System migration',
                    'created_at' => $now,
                ];
            }
        });

        DB::table('users')->whereNotNull('thermal_printer_id')->update(['thermal_printer_id' => null]);

        DB::table('id_sequences')->insert([
            'name' => self::SEQUENCE,
            'last_value' => $sequence,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $history;
    }

    private function normalizeMac(?string $mac): ?string
    {
        $hex = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', (string) $mac));

        if (strlen($hex) !== 12 || ! preg_match('/^[0-9A-F:\-.\s]+$/i', (string) $mac)) {
            return null;
        }

        return implode(':', str_split($hex, 2));
    }
};
