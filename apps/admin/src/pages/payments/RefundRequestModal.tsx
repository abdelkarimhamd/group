import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Form, Input, Modal, Select, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { Money, RefundReason } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import { applyFieldErrors, minorOf, MoneyInput, moneyRules } from '../../components/forms';
import { formatMinor, minorToMajorText, parseMajorToMinor } from '../../lib/money';

const REASONS: RefundReason[] = [
  'customer_cancellation',
  'delivery_delay',
  'defective',
  'shortage',
  'campaign_cancelled_after_capture',
  'other',
];

/** Refundable amount: captured minus already refunded, in minor units. */
export function refundableMinor(captured: Money, refunded: Money): number {
  return Math.max(0, captured.amount_minor - refunded.amount_minor);
}

/** Starts a refund. It waits as pending_approval until a second admin with finance approves it. */
export function RefundRequestModal({
  paymentId,
  orderId,
  captured,
  refunded,
  onClose,
}: {
  paymentId: string;
  orderId?: string | null;
  captured: Money;
  refunded: Money;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<{ amount_halalas: string; reason: RefundReason; note?: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const max = refundableMinor(captured, refunded);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/refunds', {
        body: {
          payment_id: paymentId,
          customer_order_id: orderId ?? null,
          amount_halalas: minorOf(v.amount_halalas),
          reason: v.reason,
          note: v.note?.trim() || null,
        },
      });
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: ['get', '/admin/refunds'] }),
        queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] }),
        queryClient.invalidateQueries({ queryKey: ['get', '/admin/payments/{payment_id}'] }),
        queryClient.invalidateQueries({ queryKey: ['get', '/admin/orders/{order_id}'] }),
      ]);
      message.success(t('refunds.requested'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      title={t('refunds.request')}
      okText={t('refunds.submit')}
      okButtonProps={{ loading: busy, disabled: max === 0 }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>
        {t('refunds.refundable')}: <Ltr>{formatMinor(max, captured.currency)}</Ltr>
      </Typography.Paragraph>
      <Alert type="info" showIcon title={t('refunds.needsApproval')} style={{ marginBottom: 12 }} />
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{ amount_halalas: minorToMajorText(max) }}
      >
        <Form.Item
          name="amount_halalas"
          label={t('refunds.amount')}
          rules={[
            ...moneyRules(t, { min: 1 }),
            {
              validator: async (_, value: string | undefined) => {
                const minor = value ? parseMajorToMinor(value) : null;
                if (minor !== null && minor > max) throw new Error(t('refunds.aboveRefundable'));
              },
            },
          ]}
        >
          <MoneyInput currency={captured.currency} />
        </Form.Item>
        <Form.Item
          name="reason"
          label={t('common.reason')}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <Select options={REASONS.map((r) => ({ value: r, label: t(`refunds.reasons.${r}`) }))} />
        </Form.Item>
        <Form.Item name="note" label={t('common.note')} rules={[{ max: 2000 }]}>
          <Input.TextArea rows={3} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
