import { useQueryClient } from '@tanstack/react-query';
import { App, Form, Input, Modal, Select } from 'antd';
import type { Dayjs } from 'dayjs';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { ShipmentEventInput } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import {
  applyFieldErrors,
  MoneyInput,
  moneyRules,
  optionalMinorOf,
  requiredRule,
  RiyadhDateTimePicker,
} from '../../components/forms';
import { statusOptions } from '../../components/StatusTag';
import { nowRiyadhPickerValue, riyadhPickerValueToUtc } from '../../lib/time';

function useRefreshOrder() {
  const queryClient = useQueryClient();
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/orders'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/orders/{order_id}'] }),
    ]);
}

/** One outbound shipment per order, from the warehouse with a local courier. */
export function CreateShipmentModal({
  orderId,
  onClose,
}: {
  orderId: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshOrder();
  const [form] = Form.useForm<{
    courier: string;
    tracking_number: string;
    tracking_url?: string;
    shipping_cost?: string;
  }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/orders/{order_id}/shipment', {
        params: { path: { order_id: orderId } },
        body: {
          courier: v.courier.trim(),
          tracking_number: v.tracking_number.trim(),
          tracking_url: v.tracking_url?.trim() || null,
          shipping_cost_halalas: optionalMinorOf(v.shipping_cost),
        },
      });
      await refresh();
      message.success(t('orders.shipmentCreated'));
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
      title={t('orders.createShipment')}
      okText={t('orders.ship')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form form={form} layout="vertical" requiredMark="optional">
        <Form.Item
          name="courier"
          label={t('orders.courier')}
          rules={[requiredRule(t), { max: 100 }]}
        >
          <Input placeholder="SMSA, Aramex, SPL…" />
        </Form.Item>
        <Form.Item
          name="tracking_number"
          label={t('orders.trackingNumber')}
          rules={[requiredRule(t), { max: 100 }]}
        >
          <Input dir="ltr" />
        </Form.Item>
        <Form.Item
          name="tracking_url"
          label={t('orders.trackingUrl')}
          rules={[{ type: 'url', message: t('validation.url') }]}
        >
          <Input dir="ltr" />
        </Form.Item>
        <Form.Item
          name="shipping_cost"
          label={t('orders.shippingCost')}
          extra={t('orders.shippingCostHelp')}
          rules={moneyRules(t, { required: false })}
        >
          <MoneyInput />
        </Form.Item>
      </Form>
    </Modal>
  );
}

/** What a tracking update may say; returns and cancellations come with returns handling. */
const TRACKABLE: string[] = [
  'picked_up',
  'in_transit',
  'out_for_delivery',
  'failed_attempt',
  'delivered',
] satisfies ShipmentEventInput['status'][];

/** A tracking update typed by hand, for couriers without a tracking API. */
export function ShipmentEventModal({
  shipmentId,
  onClose,
}: {
  shipmentId: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshOrder();
  const [form] = Form.useForm<{
    status: ShipmentEventInput['status'];
    description?: string;
    location?: string;
    occurred_at: Dayjs;
  }>();
  const status = Form.useWatch('status', form);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/shipments/{shipment_id}/events', {
        params: { path: { shipment_id: shipmentId } },
        body: {
          status: v.status,
          description: v.description?.trim() || null,
          location: v.location?.trim() || null,
          occurred_at: riyadhPickerValueToUtc(v.occurred_at),
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
      title={t('orders.addTrackingEvent')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{ occurred_at: nowRiyadhPickerValue() }}
      >
        <Form.Item
          name="status"
          label={t('common.status')}
          extra={status === 'delivered' ? t('orders.deliveredStartsReturnWindow') : undefined}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <Select
            options={statusOptions(t, 'shipment').filter((o) => TRACKABLE.includes(o.value))}
          />
        </Form.Item>
        <Form.Item
          name="occurred_at"
          label={t('orders.occurredAt')}
          rules={[{ required: true, message: t('validation.required') }]}
        >
          <RiyadhDateTimePicker />
        </Form.Item>
        <Form.Item name="location" label={t('orders.location')} rules={[{ max: 200 }]}>
          <Input />
        </Form.Item>
        <Form.Item name="description" label={t('common.description')} rules={[{ max: 500 }]}>
          <Input.TextArea rows={2} />
        </Form.Item>
      </Form>
    </Modal>
  );
}
