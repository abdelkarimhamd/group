import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Form, Input, Modal, Select, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { OrderReturn, ReturnReason } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import { applyFieldErrors, minorOf, MoneyInput, moneyRules } from '../../components/forms';
import { ReasonModal } from '../../components/ReasonModal';
import { minorToMajorText, parseMajorToMinor } from '../../lib/money';

export const RETURN_REASONS: ReturnReason[] = [
  'defective',
  'damaged_in_transit',
  'wrong_item',
  'not_as_described',
  'changed_mind',
  'other',
];

function useRefreshReturns() {
  const queryClient = useQueryClient();
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/returns'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/orders'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/orders/{order_id}'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/refunds'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] }),
    ]);
}

/** Records that the customer wants to send the order back, within its return window. */
export function RecordReturnModal({ orderId, onClose }: { orderId: string; onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshReturns();
  const [form] = Form.useForm<{ reason: ReturnReason; note?: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/orders/{order_id}/return', {
        params: { path: { order_id: orderId } },
        body: { reason: v.reason, note: v.note?.trim() || null },
      });
      await refresh();
      message.success(t('returns.recorded'));
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
      title={t('returns.record')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>{t('returns.recordHelp')}</Typography.Paragraph>
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form form={form} layout="vertical" requiredMark="optional">
        <Form.Item
          name="reason"
          label={t('common.reason')}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <Select
            options={RETURN_REASONS.map((r) => ({ value: r, label: t(`returns.reasons.${r}`) }))}
          />
        </Form.Item>
        <Form.Item name="note" label={t('common.note')} rules={[{ max: 2000 }]}>
          <Input.TextArea rows={3} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Accepts the return: asks finance for a refund of up to what is still refundable. */
export function ApproveReturnModal({ ret, onClose }: { ret: OrderReturn; onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshReturns();
  const [form] = Form.useForm<{ amount_halalas: string; note?: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const max = ret.refundable.amount_minor;

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/returns/{return_id}/approve', {
        params: { path: { return_id: ret.id } },
        body: { amount_halalas: minorOf(v.amount_halalas), note: v.note?.trim() || null },
      });
      await refresh();
      message.success(t('returns.approved'));
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
      title={t('returns.approve')}
      okText={t('refunds.submit')}
      okButtonProps={{ loading: busy, disabled: max === 0 }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Typography.Paragraph>
        {t('refunds.refundable')}: <Ltr>{ret.refundable.formatted}</Ltr>
      </Typography.Paragraph>
      <Alert type="info" showIcon title={t('returns.approveHelp')} style={{ marginBottom: 12 }} />
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
          extra={t('returns.amountHelp')}
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
          <MoneyInput currency={ret.refundable.currency} />
        </Form.Item>
        <Form.Item name="note" label={t('common.note')} rules={[{ max: 1000 }]}>
          <Input.TextArea rows={3} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** Declines the return; the order goes back to delivered. */
export function RejectReturnModal({ ret, onClose }: { ret: OrderReturn; onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshReturns();
  return (
    <ReasonModal
      open
      danger
      title={t('returns.reject')}
      okText={t('returns.reject')}
      description={t('returns.rejectHelp')}
      onCancel={onClose}
      onSubmit={async (note) => {
        await api.POST('/admin/returns/{return_id}/reject', {
          params: { path: { return_id: ret.id } },
          body: { note },
        });
        await refresh();
        message.success(t('returns.rejected'));
        onClose();
      }}
    />
  );
}
