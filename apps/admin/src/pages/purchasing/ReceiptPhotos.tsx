import { CameraOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Image, Space, Upload } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api, formDataSerializer } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { InboundReceipt } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { apiErrorMessage } from '../../components/ApiErrorAlert';

const PHOTO_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_PHOTO_BYTES = 10 * 1024 * 1024;

/** Photos of the goods as they arrived: evidence, so they are added and never removed. */
export function ReceiptPhotos({ receipt }: { receipt: InboundReceipt }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [uploading, setUploading] = useState(false);

  const upload = async (file: File) => {
    if (!PHOTO_TYPES.includes(file.type)) return void message.error(t('purchasing.photoType'));
    if (file.size > MAX_PHOTO_BYTES) return void message.error(t('purchasing.photoTooLarge'));
    setUploading(true);
    try {
      await api.POST('/admin/inbound-receipts/{receipt_id}/photos', {
        params: { path: { receipt_id: receipt.id } },
        body: { file: file as unknown as string },
        bodySerializer: (body) => formDataSerializer(body as Record<string, unknown>),
      });
      await queryClient.invalidateQueries({
        queryKey: ['get', '/admin/purchase-orders/{purchase_order_id}'],
      });
      message.success(t('purchasing.photoAdded'));
    } catch (e) {
      message.error(
        toApiError(e).code === 'photo_limit_reached'
          ? t('purchasing.photoLimit')
          : apiErrorMessage(t, e),
      );
    } finally {
      setUploading(false);
    }
  };

  return (
    <Space wrap style={{ marginTop: 8 }}>
      <Image.PreviewGroup>
        {receipt.photo_urls.map((url, i) => (
          <Image
            key={url}
            src={url}
            width={72}
            height={72}
            style={{ objectFit: 'cover', borderRadius: 4 }}
            alt={`${t('purchasing.photo')} ${i + 1}`}
          />
        ))}
      </Image.PreviewGroup>
      <Can permission="fulfilment.edit">
        <Upload
          accept={PHOTO_TYPES.join(',')}
          showUploadList={false}
          beforeUpload={(file) => {
            void upload(file);
            return false;
          }}
        >
          <Button size="small" icon={<CameraOutlined />} loading={uploading}>
            {t('purchasing.addPhoto')}
          </Button>
        </Upload>
      </Can>
    </Space>
  );
}
