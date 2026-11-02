import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Form, Input, Modal, Rate } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { Supplier, SupplierStatus } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors } from '../../components/forms';

type ReviewValues = { quality_rating?: number | null; review_notes?: string | null };

/**
 * Approve, block or reopen a supplier (POST /admin/suppliers/{id}/review,
 * suppliers.approve). Kept apart from editing so the people who add suppliers
 * and the people who approve them can be different.
 */
export function SupplierReviewModal({
  supplier,
  status,
  onClose,
}: {
  supplier: Supplier;
  status: SupplierStatus | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<ReviewValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!status) return;
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/suppliers/{supplier_id}/review', {
        params: { path: { supplier_id: supplier.id } },
        body: {
          status,
          quality_rating: v.quality_rating || null,
          review_notes: v.review_notes?.trim() || null,
        },
      });
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/suppliers'] });
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/suppliers/{supplier_id}'] });
      message.success(t('common.saved'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={status !== null}
      title={status ? t(`suppliers.reviewTitle.${status}`) : undefined}
      okText={status ? t(`suppliers.reviewTitle.${status}`) : undefined}
      okButtonProps={{ danger: status === 'blocked' }}
      confirmLoading={busy}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      {status && (
        <Alert
          type={status === 'blocked' ? 'warning' : 'info'}
          showIcon
          title={t(`suppliers.reviewHint.${status}`)}
          style={{ marginBottom: 16 }}
        />
      )}
      <ApiErrorAlert error={error} />
      <Form
        form={form}
        layout="vertical"
        initialValues={{
          quality_rating: supplier.quality_rating,
          review_notes: supplier.review_notes,
        }}
      >
        <Form.Item name="quality_rating" label={t('suppliers.qualityRating')}>
          <Rate />
        </Form.Item>
        <Form.Item name="review_notes" label={t('suppliers.reviewNotes')} rules={[{ max: 5000 }]}>
          <Input.TextArea rows={4} maxLength={5000} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
