<?php

namespace App\Modules\Orders\Services;

use App\Exceptions\ApiException;
use App\Modules\Identity\Models\AdminUser;
use App\Modules\Orders\Enums\PurchaseOrderDocumentKind;
use App\Modules\Orders\Models\PurchaseOrderDocument;
use App\Modules\Orders\Models\SupplierPurchaseOrder;
use App\Support\Actor;
use App\Support\Audit\AuditLogger;
use App\Support\Files\StoredFiles;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Papers kept with a supplier purchase order (spec, E12). The file is
 * written before its row and removed if the transaction fails; a removed
 * document loses its file after the commit. Every change is audited.
 */
final class PurchaseOrderDocuments
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function attach(string $purchaseOrderUuid, UploadedFile $file, PurchaseOrderDocumentKind $kind, AdminUser $by): PurchaseOrderDocument
    {
        $parent = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->firstOrFail();
        // A cheap look first, so a full order costs no upload; the count
        // that decides is taken again under the lock.
        self::assertRoom($parent);
        $disk = (string) config('groupbuy.media.documents_disk');

        return StoredFiles::storeThen($file, "purchase-orders/{$parent->uuid}", $disk,
            fn (string $path): PurchaseOrderDocument => DB::transaction(function () use ($purchaseOrderUuid, $file, $kind, $by, $disk, $path): PurchaseOrderDocument {
                $order = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->lockForUpdate()->firstOrFail();
                self::assertRoom($order);

                $document = PurchaseOrderDocument::create([
                    'supplier_purchase_order_id' => $order->id,
                    'kind' => $kind,
                    'disk' => $disk,
                    'path' => $path,
                    'file_name' => self::fileName($file),
                    'size_bytes' => (int) $file->getSize(),
                    'uploaded_by_admin_id' => $by->id,
                ]);
                $this->audit->record(Actor::admin($by->id), 'purchase_order.document_attach', $order, null, [
                    'document' => $document->uuid,
                    'kind' => $kind->value,
                    'file_name' => $document->file_name,
                ]);

                return $document;
            }),
        );
    }

    /**
     * Only a paper uploaded by mistake is removed: once
     * `media.document_removal_hours` have passed it is evidence of what was
     * ordered and paid, and stays (409 `document_locked`).
     */
    public function remove(string $purchaseOrderUuid, string $documentUuid, AdminUser $by): void
    {
        $document = DB::transaction(function () use ($purchaseOrderUuid, $documentUuid, $by): PurchaseOrderDocument {
            $order = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->lockForUpdate()->firstOrFail();
            $document = PurchaseOrderDocument::query()
                ->where('supplier_purchase_order_id', $order->id)
                ->where('uuid', $documentUuid)
                ->firstOrFail();

            if (! $document->isRemovable()) {
                throw new ApiException(409, 'document_locked');
            }

            $document->delete();
            $this->audit->record(Actor::admin($by->id), 'purchase_order.document_remove', $order, [
                'document' => $document->uuid,
                'kind' => $document->kind->value,
                'file_name' => $document->file_name,
            ], null);

            return $document;
        });

        StoredFiles::deleteQuietly($document->disk, $document->path);
    }

    private static function assertRoom(SupplierPurchaseOrder $order): void
    {
        if ($order->documents()->count() >= (int) config('groupbuy.media.documents_per_purchase_order')) {
            throw new ApiException(409, 'document_limit_reached');
        }
    }

    /**
     * The name the file had on the admin's computer, kept to show it again;
     * never used as a path.
     */
    private static function fileName(UploadedFile $file): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $file->getClientOriginalName()) ?? '');

        return Str::limit($name === '' ? 'document.'.$file->extension() : $name, 255, '');
    }
}
