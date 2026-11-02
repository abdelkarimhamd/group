import { useQueryClient } from '@tanstack/react-query';
import {
  Alert,
  App,
  Col,
  DatePicker,
  Form,
  Input,
  InputNumber,
  Modal,
  Row,
  Select,
  Table,
  Typography,
} from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { PurchaseOrder, PurchaseOrderInput, PurchaseOrderStatus } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr } from '../../components/format';
import {
  applyFieldErrors,
  minorOf,
  MoneyInput,
  moneyRules,
  rateRules,
  requiredRule,
  RiyadhDateTimePicker,
} from '../../components/forms';
import { useBilingual } from '../../i18n/LocaleProvider';
import { minorToMajorText } from '../../lib/money';
import {
  dayjs,
  nowRiyadhPickerValue,
  pickerValueToDate,
  riyadhPickerValueToUtc,
} from '../../lib/time';

function useRefreshPurchaseOrder() {
  const queryClient = useQueryClient();
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/purchase-orders'] }),
      queryClient.invalidateQueries({
        queryKey: ['get', '/admin/purchase-orders/{purchase_order_id}'],
      }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns/{campaign_id}'] }),
    ]);
}

/** Records the Alibaba order number, amount paid, exchange rate and freight details. */
export function EditPurchaseOrderModal({
  po,
  onClose,
}: {
  po: PurchaseOrder;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshPurchaseOrder();
  type Values = Omit<PurchaseOrderInput, 'total_minor' | 'eta'> & {
    total: string;
    eta?: Dayjs | null;
  };
  const [form] = Form.useForm<Values>();
  const currency = Form.useWatch('currency', form) ?? po.total.currency;
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.PATCH('/admin/purchase-orders/{purchase_order_id}', {
        params: { path: { purchase_order_id: po.id } },
        body: {
          alibaba_order_ref: v.alibaba_order_ref?.trim() || null,
          total_minor: minorOf(v.total),
          currency: v.currency,
          fx_rate_to_sar: v.fx_rate_to_sar?.trim(),
          freight_forwarder: v.freight_forwarder?.trim() || null,
          freight_tracking_ref: v.freight_tracking_ref?.trim() || null,
          eta: v.eta ? pickerValueToDate(v.eta) : null,
        },
      });
      await refresh();
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
      open
      width={680}
      title={t('purchasing.editDetails')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form<Values>
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{
          alibaba_order_ref: po.alibaba_order_ref,
          total: minorToMajorText(po.total.amount_minor),
          currency: po.total.currency as PurchaseOrderInput['currency'],
          fx_rate_to_sar: po.fx_rate_to_sar,
          freight_forwarder: po.freight_forwarder,
          freight_tracking_ref: po.freight_tracking_ref,
          eta: po.eta ? dayjs(po.eta) : null,
        }}
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item
              name="alibaba_order_ref"
              label={t('purchasing.alibabaOrderRef')}
              rules={[{ max: 100 }]}
            >
              <Input dir="ltr" />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="currency" label={t('suppliers.currency')} rules={[requiredRule(t)]}>
              <Select options={['USD', 'CNY', 'SAR'].map((c) => ({ value: c, label: c }))} />
            </Form.Item>
          </Col>
          <Col xs={12} md={6}>
            <Form.Item name="fx_rate_to_sar" label={t('suppliers.fxRate')} rules={rateRules(t)}>
              <Input dir="ltr" inputMode="decimal" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="total" label={t('purchasing.amountPaid')} rules={moneyRules(t)}>
              <MoneyInput currency={currency} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="eta" label={t('purchasing.eta')}>
              <DatePicker style={{ width: '100%' }} />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="freight_forwarder"
              label={t('purchasing.forwarder')}
              rules={[{ max: 100 }]}
            >
              <Input />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="freight_tracking_ref"
              label={t('purchasing.freightTracking')}
              rules={[{ max: 100 }]}
            >
              <Input dir="ltr" />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}

export const NEXT_PO_STATUS: Partial<
  Record<PurchaseOrderStatus, 'placed' | 'supplier_confirmed' | 'paid' | 'shipped'>
> = {
  draft: 'placed',
  placed: 'supplier_confirmed',
  supplier_confirmed: 'paid',
  paid: 'shipped',
};

/** Moves the purchase order one step forward. Marking it shipped moves the campaign to fulfilling. */
export function AdvancePurchaseOrderModal({
  po,
  onClose,
}: {
  po: PurchaseOrder;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshPurchaseOrder();
  const next = NEXT_PO_STATUS[po.status];
  const [form] = Form.useForm<{ occurred_at: Dayjs; note?: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!next) return;
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/purchase-orders/{purchase_order_id}/status', {
        params: { path: { purchase_order_id: po.id } },
        body: {
          status: next,
          occurred_at: riyadhPickerValueToUtc(v.occurred_at),
          note: v.note?.trim() || null,
        },
      });
      await refresh();
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
      open
      title={next ? t(`purchasing.advance.${next}`) : ''}
      okText={t('common.confirm')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      {next === 'shipped' && (
        <Alert
          type="info"
          showIcon
          style={{ marginBottom: 12 }}
          title={t('purchasing.shippedMovesCampaign')}
        />
      )}
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form form={form} layout="vertical" initialValues={{ occurred_at: nowRiyadhPickerValue() }}>
        <Form.Item
          name="occurred_at"
          label={t('orders.occurredAt')}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <RiyadhDateTimePicker />
        </Form.Item>
        <Form.Item name="note" label={t('common.note')} rules={[{ max: 1000 }]}>
          <Input.TextArea rows={2} />
        </Form.Item>
      </Form>
    </Modal>
  );
}

type ReceiptLine = { product_variant_id: string; qty_received: number; qty_damaged: number };

/** Goods counted at the warehouse. Marks the purchase order received. */
export function InboundReceiptModal({ po, onClose }: { po: PurchaseOrder; onClose: () => void }) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { message } = App.useApp();
  const refresh = useRefreshPurchaseOrder();
  const [form] = Form.useForm<{ warehouse_name: string; received_at: Dayjs; notes?: string }>();
  const [lines, setLines] = useState<ReceiptLine[]>(() =>
    po.lines.map((l) => ({
      product_variant_id: l.product_variant.id,
      qty_received: l.qty,
      qty_damaged: 0,
    })),
  );
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const linesValid = lines.every(
    (l) => l.qty_received >= 0 && l.qty_damaged >= 0 && l.qty_damaged <= l.qty_received,
  );

  const submit = async () => {
    const v = await form.validateFields();
    if (!linesValid) return;
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.POST('/admin/purchase-orders/{purchase_order_id}/receipts', {
        params: { path: { purchase_order_id: po.id } },
        body: {
          warehouse_name: v.warehouse_name.trim(),
          received_at: riyadhPickerValueToUtc(v.received_at),
          notes: v.notes?.trim() || null,
          lines,
        },
      });
      await refresh();
      const short = data?.data.lines.some((l) => l.qty_missing > 0 || l.qty_damaged > 0);
      if (short) message.warning(t('purchasing.receiptShort'));
      else message.success(t('purchasing.receiptRecorded'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  const update = (index: number, patch: Partial<ReceiptLine>) =>
    setLines((prev) => prev.map((l, i) => (i === index ? { ...l, ...patch } : l)));

  return (
    <Modal
      open
      width={760}
      title={t('purchasing.recordReceipt')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy, disabled: !linesValid }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{ received_at: nowRiyadhPickerValue() }}
      >
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item
              name="warehouse_name"
              label={t('purchasing.warehouse')}
              rules={[requiredRule(t), { max: 100 }]}
            >
              <Input />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="received_at"
              label={t('purchasing.receivedAt')}
              rules={[{ required: true, message: t('validation.required') }]}
            >
              <RiyadhDateTimePicker />
            </Form.Item>
          </Col>
        </Row>
        <Table
          rowKey="product_variant_id"
          size="small"
          pagination={false}
          dataSource={po.lines.map((l, index) => ({ ...l, index, line: lines[index]! }))}
          columns={[
            {
              title: t('suppliers.variant'),
              key: 'variant',
              render: (_, l) => `${pick(l.product_variant, 'name')} (${l.product_variant.sku})`,
            },
            {
              title: t('purchasing.expected'),
              dataIndex: 'qty',
              render: (v: number) => <Ltr>{v}</Ltr>,
            },
            {
              title: t('purchasing.received'),
              key: 'received',
              render: (_, l) => (
                <InputNumber
                  aria-label={`${t('purchasing.received')} ${l.product_variant.sku}`}
                  min={0}
                  precision={0}
                  value={l.line.qty_received}
                  onChange={(v) => update(l.index, { qty_received: v ?? 0 })}
                />
              ),
            },
            {
              title: t('purchasing.damaged'),
              key: 'damaged',
              render: (_, l) => (
                <InputNumber
                  aria-label={`${t('purchasing.damaged')} ${l.product_variant.sku}`}
                  min={0}
                  max={l.line.qty_received}
                  precision={0}
                  value={l.line.qty_damaged}
                  status={l.line.qty_damaged > l.line.qty_received ? 'error' : undefined}
                  onChange={(v) => update(l.index, { qty_damaged: v ?? 0 })}
                />
              ),
            },
            {
              title: t('purchasing.missing'),
              key: 'missing',
              render: (_, l) => {
                const missing = Math.max(0, l.qty - l.line.qty_received);
                return (
                  <Typography.Text type={missing ? 'danger' : undefined}>{missing}</Typography.Text>
                );
              },
            },
          ]}
        />
        <Form.Item
          name="notes"
          label={t('common.note')}
          rules={[{ max: 5000 }]}
          style={{ marginTop: 16 }}
        >
          <Input.TextArea rows={2} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
