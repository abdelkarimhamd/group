import { DeleteOutlined, PaperClipOutlined, UploadOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Popconfirm, Select, Space, Table, Typography, Upload } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api, formDataSerializer } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { PurchaseOrder, PurchaseOrderDocumentKind } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { apiErrorMessage } from '../../components/ApiErrorAlert';
import { ActorText, DateTimeText, Ltr } from '../../components/format';
import { MAX_ATTACHMENT_BYTES } from '../../lib/uploads';

const DOCUMENT_TYPES = ['application/pdf', 'image/jpeg', 'image/png'];
export const DOCUMENT_KINDS: PurchaseOrderDocumentKind[] = [
  'alibaba_invoice',
  'payment_receipt',
  'shipping_document',
  'customs_document',
  'other',
];
const PO_QUERY = ['get', '/admin/purchase-orders/{purchase_order_id}'];

/** "1.2 MB", "340 KB": approximate, for people. */
export function fileSize(bytes: number): string {
  if (bytes >= 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
  return `${Math.max(1, (bytes + 512) >> 10)} KB`;
}

/** Papers kept with a purchase order: the Alibaba invoice, payment receipts, shipping papers. */
export function PurchaseOrderDocuments({ po }: { po: PurchaseOrder }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [kind, setKind] = useState<PurchaseOrderDocumentKind>('alibaba_invoice');
  const [uploading, setUploading] = useState(false);
  const [removing, setRemoving] = useState<string | null>(null);

  const refresh = () => queryClient.invalidateQueries({ queryKey: PO_QUERY });

  const upload = async (file: File) => {
    if (!DOCUMENT_TYPES.includes(file.type))
      return void message.error(t('purchasing.documentType'));
    if (file.size > MAX_ATTACHMENT_BYTES)
      return void message.error(t('purchasing.documentTooLarge'));
    setUploading(true);
    try {
      await api.POST('/admin/purchase-orders/{purchase_order_id}/documents', {
        params: { path: { purchase_order_id: po.id } },
        body: { file: file as unknown as string, kind },
        bodySerializer: (body) => formDataSerializer(body as Record<string, unknown>),
      });
      await refresh();
      message.success(t('purchasing.documentUploaded'));
    } catch (e) {
      message.error(
        toApiError(e).code === 'document_limit_reached'
          ? t('purchasing.documentLimit')
          : apiErrorMessage(t, e),
      );
    } finally {
      setUploading(false);
    }
  };

  const remove = async (documentId: string) => {
    setRemoving(documentId);
    try {
      await api.DELETE('/admin/purchase-orders/{purchase_order_id}/documents/{document_id}', {
        params: { path: { purchase_order_id: po.id, document_id: documentId } },
      });
      await refresh();
      message.success(t('purchasing.documentRemoved'));
    } catch (e) {
      message.error(apiErrorMessage(t, e));
    } finally {
      setRemoving(null);
    }
  };

  return (
    <Card
      title={t('purchasing.documents')}
      extra={
        <Can permission="purchasing.edit">
          <Space.Compact>
            <Select
              aria-label={t('purchasing.documentKind')}
              size="small"
              style={{ minWidth: 170 }}
              value={kind}
              onChange={setKind}
              options={DOCUMENT_KINDS.map((k) => ({
                value: k,
                label: t(`purchasing.documentKinds.${k}`),
              }))}
            />
            <Upload
              accept={DOCUMENT_TYPES.join(',')}
              showUploadList={false}
              beforeUpload={(file) => {
                void upload(file);
                return false;
              }}
            >
              <Button size="small" icon={<UploadOutlined />} loading={uploading}>
                {t('purchasing.uploadDocument')}
              </Button>
            </Upload>
          </Space.Compact>
        </Can>
      }
    >
      {po.documents.length === 0 ? (
        <Typography.Text type="secondary">{t('purchasing.noDocuments')}</Typography.Text>
      ) : (
        <Table
          rowKey="id"
          size="small"
          pagination={false}
          dataSource={po.documents}
          columns={[
            {
              title: t('purchasing.documentKind'),
              key: 'kind',
              render: (_, d) => t(`purchasing.documentKinds.${d.kind}`),
            },
            {
              title: t('purchasing.file'),
              key: 'file',
              render: (_, d) => (
                <Typography.Link href={d.url} target="_blank" rel="noreferrer noopener">
                  <PaperClipOutlined /> <Ltr>{d.file_name}</Ltr>
                </Typography.Link>
              ),
            },
            { title: t('purchasing.size'), key: 'size', render: (_, d) => fileSize(d.size_bytes) },
            {
              title: t('purchasing.uploaded'),
              key: 'uploaded',
              render: (_, d) => (
                <>
                  <DateTimeText value={d.uploaded_at} /> · <ActorText actor={d.uploaded_by} />
                </>
              ),
            },
            {
              key: 'remove',
              align: 'end',
              // After a day a paper is the record of the order and stays.
              render: (_, d) =>
                d.removable && (
                  <Can permission="purchasing.edit">
                    <Popconfirm
                      title={t('purchasing.removeDocumentConfirm')}
                      okText={t('purchasing.removeDocument')}
                      okButtonProps={{ danger: true }}
                      onConfirm={() => remove(d.id)}
                    >
                      <Button
                        size="small"
                        type="text"
                        danger
                        icon={<DeleteOutlined />}
                        loading={removing === d.id}
                        aria-label={t('purchasing.removeDocument')}
                      />
                    </Popconfirm>
                  </Can>
                ),
            },
          ]}
        />
      )}
    </Card>
  );
}
