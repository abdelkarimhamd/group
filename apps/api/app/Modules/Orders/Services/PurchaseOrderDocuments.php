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
use RuntimeException;
use Throwable;

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
        $disk = (string) config('groupbuy.media.documents_disk');
        $path = $file->storeAs("purchase-orders/{$parent->uuid}", Str::uuid7().'.'.$file->extension(), $disk);

        if ($path === false) {
            throw new RuntimeException("Could not store a document for purchase order {$parent->po_number}.");
        }

        try {
            return DB::transaction(function () use ($purchaseOrderUuid, $file, $kind, $by, $disk, $path): PurchaseOrderDocument {
                $order = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->lockForUpdate()->firstOrFail();

                if ($order->documents()->count() >= (int) config('groupbuy.media.documents_per_purchase_order')) {
                    throw new ApiException(409, 'document_limit_reached');
                }

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
            });
        } catch (Throwable $e) {
            StoredFiles::deleteQuietly($disk, $path);

            throw $e;
        }
    }

    public function remove(string $purchaseOrderUuid, string $documentUuid, AdminUser $by): void
    {
        $document = DB::transaction(function () use ($purchaseOrderUuid, $documentUuid, $by): PurchaseOrderDocument {
            $order = SupplierPurchaseOrder::query()->where('uuid', $purchaseOrderUuid)->lockForUpdate()->firstOrFail();
            $document = PurchaseOrderDocument::query()
                ->where('supplier_purchase_order_id', $order->id)
                ->where('uuid', $documentUuid)
                ->firstOrFail();

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
