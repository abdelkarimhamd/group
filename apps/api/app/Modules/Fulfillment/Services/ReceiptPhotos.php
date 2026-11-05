<?php

namespace App\Modules\Fulfillment\Services;

use App\Exceptions\ApiException;
use App\Modules\Fulfillment\Models\InboundReceipt;
use App\Modules\Identity\Models\AdminUser;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Files\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Photos of goods as they reached the warehouse (spec, E13), kept as
 * evidence of damage and shortfalls: added, never removed. Private files,
 * read through signed links. The file is written before the receipt row is
 * changed and removed if the transaction fails.
 */
final class ReceiptPhotos
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function add(string $receiptUuid, UploadedFile $file, AdminUser $by): InboundReceipt
    {
        $receipt = InboundReceipt::query()->where('uuid', $receiptUuid)->firstOrFail();
        // A cheap look first, so a full receipt costs no upload; the count
        // that decides is taken again under the lock.
        self::assertRoom($receipt);

        // Keys carry no disk: a receipt's photos are read from the configured
        // documents disk, which therefore stays the same once files are kept
        // (docs/architecture.md, Files).
        return StoredFiles::storeThen($file, "receipts/{$receipt->uuid}", (string) config('groupbuy.media.documents_disk'),
            fn (string $path): InboundReceipt => DB::transaction(function () use ($receiptUuid, $by, $path): InboundReceipt {
                $receipt = InboundReceipt::query()->where('uuid', $receiptUuid)->lockForUpdate()->firstOrFail();
                self::assertRoom($receipt);

                $photos = $receipt->photo_s3_keys;
                $photos[] = $path;
                $receipt->photo_s3_keys = $photos;
                $receipt->save();
                $this->audit->record(Actor::admin($by->id), 'inbound_receipt.photo_add', $receipt, null, [
                    'photo' => $path,
                    'count' => count($photos),
                ]);

                return $receipt;
            }),
        );
    }

    private static function assertRoom(InboundReceipt $receipt): void
    {
        if (count($receipt->photo_s3_keys) >= (int) config('groupbuy.media.photos_per_receipt')) {
            throw new ApiException(409, 'photo_limit_reached');
        }
    }
}
