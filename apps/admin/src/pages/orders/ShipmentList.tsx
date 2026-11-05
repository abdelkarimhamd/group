import { FilePdfOutlined, PlusOutlined, StopOutlined } from '@ant-design/icons';
import { Alert, Button, Divider, Empty, Space, Tag, Timeline, Typography } from 'antd';
import { Fragment } from 'react';
import { useTranslation } from 'react-i18next';
import type { AdminShipment } from '../../api/types';
import { Fields } from '../../components/Fields';
import { CodeText, DateTimeText, Ltr } from '../../components/format';
import { StatusTag } from '../../components/StatusTag';

const FINAL: AdminShipment['status'][] = ['delivered', 'returned', 'cancelled'];

/** A shipment the order is still waiting on: not brought back and not called off. */
export function isOpenShipment(s: AdminShipment): boolean {
  return s.status !== 'returned' && s.status !== 'cancelled';
}

/** Tracking can be added once there is a waybill and the parcel has not reached its end. */
export function canTrack(s: AdminShipment): boolean {
  return s.tracking_number !== null && !FINAL.includes(s.status);
}

/** Only a parcel the courier has not collected can be called off, and not mid-booking. */
export function canCancel(s: AdminShipment): boolean {
  return s.status === 'pending' && s.booking?.status !== 'requested';
}

/** Every shipment of an order, newest first, with its booking and tracking history. */
export function ShipmentList({
  shipments,
  canEdit,
  onAddEvent,
  onCancel,
}: {
  shipments: AdminShipment[];
  canEdit: boolean;
  onAddEvent: (s: AdminShipment) => void;
  onCancel: (s: AdminShipment) => void;
}) {
  const { t } = useTranslation();
  if (shipments.length === 0) return <Empty description={t('orders.noShipment')} />;

  return (
    <>
      {shipments.map((s, index) => (
        <Fragment key={s.id}>
          {index > 0 && <Divider>{t('orders.earlierShipment')}</Divider>}
          <section aria-label={t('orders.shipmentBy', { courier: s.courier })}>
            {canEdit && (canTrack(s) || canCancel(s)) && (
              <Space wrap style={{ marginBottom: 12 }}>
                {canTrack(s) && (
                  <Button size="small" icon={<PlusOutlined />} onClick={() => onAddEvent(s)}>
                    {t('orders.addTrackingEvent')}
                  </Button>
                )}
                {canCancel(s) && (
                  <Button size="small" danger icon={<StopOutlined />} onClick={() => onCancel(s)}>
                    {t('orders.cancelShipment')}
                  </Button>
                )}
              </Space>
            )}
            {s.booking?.status === 'requested' && (
              <Alert
                type="info"
                showIcon
                title={t('orders.bookingWaiting', { courier: s.courier })}
                style={{ marginBottom: 12 }}
              />
            )}
            {s.booking?.error && (
              <Alert
                type="error"
                showIcon
                title={t('orders.bookingFailed')}
                description={s.booking.error}
                style={{ marginBottom: 12 }}
              />
            )}
            <Fields
              items={[
                { label: t('orders.courier'), value: s.courier },
                {
                  label: t('orders.trackingNumber'),
                  value:
                    s.tracking_number === null ? (
                      '—'
                    ) : s.tracking_url ? (
                      <Typography.Link
                        href={s.tracking_url}
                        target="_blank"
                        rel="noreferrer noopener"
                      >
                        <Ltr>{s.tracking_number}</Ltr>
                      </Typography.Link>
                    ) : (
                      <Ltr>{s.tracking_number}</Ltr>
                    ),
                },
                {
                  label: t('common.status'),
                  value: <StatusTag kind="shipment" value={s.status} />,
                },
                { label: t('orders.shippedAt'), value: <DateTimeText value={s.shipped_at} /> },
                {
                  label: t('orders.deliveredAt'),
                  value: <DateTimeText value={s.delivered_at} />,
                  hidden: !s.delivered_at,
                },
                {
                  label: t('orders.booking'),
                  value: s.booking && <StatusTag kind="booking" value={s.booking.status} />,
                  hidden: !s.booking,
                },
                {
                  label: t('orders.bookingReference'),
                  value: s.booking && <CodeText>{s.booking.reference}</CodeText>,
                  hidden: !s.booking,
                },
                {
                  label: t('orders.label'),
                  value: s.booking?.label_url && (
                    <Typography.Link
                      href={s.booking.label_url}
                      target="_blank"
                      rel="noreferrer noopener"
                    >
                      <FilePdfOutlined /> {t('orders.downloadLabel')}
                    </Typography.Link>
                  ),
                  hidden: !s.booking?.label_url,
                },
              ]}
            />
            {s.events.length > 0 && (
              <Timeline
                style={{ marginTop: 16 }}
                items={s.events.map((e, i) => ({
                  key: i,
                  content: (
                    <>
                      <StatusTag kind="shipment" value={e.status} />
                      {e.source === 'courier_api' && <Tag>{t('orders.fromCourier')}</Tag>}
                      <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                        <DateTimeText value={e.occurred_at} />
                        {e.location ? ` · ${e.location}` : ''}
                      </Typography.Text>
                      {e.description && <div>{e.description}</div>}
                    </>
                  ),
                }))}
              />
            )}
          </section>
        </Fragment>
      ))}
    </>
  );
}
