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
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
        $disk = (string) config('groupbuy.media.documents_disk');
        $path = $file->storeAs("receipts/{$receipt->uuid}", Str::uuid7().'.'.$file->extension(), $disk);

        if ($path === false) {
            throw new RuntimeException("Could not store a photo for receipt {$receipt->uuid}.");
        }

        try {
            return DB::transaction(function () use ($receiptUuid, $by, $path): InboundReceipt {
                $receipt = InboundReceipt::query()->where('uuid', $receiptUuid)->lockForUpdate()->firstOrFail();
                $photos = $receipt->photo_s3_keys;

                if (count($photos) >= (int) config('groupbuy.media.photos_per_receipt')) {
                    throw new ApiException(409, 'photo_limit_reached');
                }

                // The disk is the documents disk at upload; keys carry no disk,
                // so a receipt's photos are always read from the configured one.
                $photos[] = $path;
                $receipt->photo_s3_keys = $photos;
                $receipt->save();
                $this->audit->record(Actor::admin($by->id), 'inbound_receipt.photo_add', $receipt, null, [
                    'photo' => $path,
                    'count' => count($photos),
                ]);

                return $receipt;
            });
        } catch (Throwable $e) {
            StoredFiles::deleteQuietly($disk, $path);

            throw $e;
        }
    }
}
