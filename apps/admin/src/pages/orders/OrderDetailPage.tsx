import { PlusOutlined, SendOutlined, UndoOutlined } from '@ant-design/icons';
import { Button, Card, Col, Empty, Row, Space, Table, Timeline, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api } from '../../api/client';
import type { Refund } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { Fields } from '../../components/Fields';
import { ActorText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { refundableMinor, RefundRequestModal } from '../payments/RefundRequestModal';
import { CreateShipmentModal, ShipmentEventModal } from './ShipmentModals';

export function OrderDetailPage() {
  const { orderId = '' } = useParams();
  const { t } = useTranslation();
  const { can } = useAuth();
  const order = $api.useQuery('get', '/admin/orders/{order_id}', {
    params: { path: { order_id: orderId } },
  });
  const [modal, setModal] = useState<'ship' | 'event' | 'refund' | null>(null);

  const o = order.data?.data;
  if (!o) return <QueryState isPending={order.isPending} error={order.error} />;
  const a = o.shipping_address;
  const refundable = refundableMinor(o.payment.captured_amount, o.payment.refunded_amount);

  return (
    <>
      <PageHeader
        backTo="/orders"
        title={<Ltr>{o.order_number}</Ltr>}
        subtitle={o.campaign.title}
        tags={<StatusTag kind="order" value={o.status} />}
        extra={
          <>
            <Can permission="fulfilment.edit">
              {o.status === 'ready_to_ship' && !o.shipment && (
                <Button type="primary" icon={<SendOutlined />} onClick={() => setModal('ship')}>
                  {t('orders.createShipment')}
                </Button>
              )}
            </Can>
            <Can permission="refunds.request">
              {refundable > 0 && (
                <Button icon={<UndoOutlined />} onClick={() => setModal('refund')}>
                  {t('refunds.request')}
                </Button>
              )}
            </Can>
          </>
        }
      />
      <Row gutter={[16, 16]}>
        <Col xs={24} lg={14}>
          <Space orientation="vertical" size={16} style={{ width: '100%' }}>
            <Card title={t('orders.order')}>
              <Fields
                items={[
                  {
                    label: t('nav.campaigns'),
                    value: can('campaigns.view') ? (
                      <Link to={`/campaigns/${o.campaign.id}`}>
                        <Ltr>{o.campaign.code}</Ltr>
                      </Link>
                    ) : (
                      <Ltr>{o.campaign.code}</Ltr>
                    ),
                  },
                  {
                    label: t('suppliers.variant'),
                    value: `${o.product_variant.name} (${o.product_variant.sku})`,
                  },
                  { label: t('common.quantity'), value: o.quantity },
                  { label: t('orders.unitPrice'), value: <MoneyText value={o.unit_price} /> },
                  { label: t('orders.goodsTotal'), value: <MoneyText value={o.goods_total} /> },
                  { label: t('orders.deliveryFee'), value: <MoneyText value={o.delivery_fee} /> },
                  { label: t('orders.total'), value: <MoneyText value={o.total} strong /> },
                  { label: t('orders.vatIncluded'), value: <MoneyText value={o.vat} /> },
                  { label: t('common.createdAt'), value: <DateTimeText value={o.created_at} /> },
                  {
                    label: t('orders.deliveredAt'),
                    value: <DateTimeText value={o.delivered_at} />,
                  },
                  {
                    label: t('orders.returnWindowEnds'),
                    value: <DateTimeText value={o.return_window_ends_at} />,
                  },
                ]}
              />
            </Card>
            <Card
              title={t('orders.shipment')}
              extra={
                o.shipment && (
                  <Can permission="fulfilment.edit">
                    <Button size="small" icon={<PlusOutlined />} onClick={() => setModal('event')}>
                      {t('orders.addTrackingEvent')}
                    </Button>
                  </Can>
                )
              }
            >
              {!o.shipment ? (
                <Empty description={t('orders.noShipment')} />
              ) : (
                <>
                  <Fields
                    items={[
                      { label: t('orders.courier'), value: o.shipment.courier },
                      {
                        label: t('orders.trackingNumber'),
                        value: o.shipment.tracking_url ? (
                          <Typography.Link
                            href={o.shipment.tracking_url}
                            target="_blank"
                            rel="noreferrer noopener"
                          >
                            <Ltr>{o.shipment.tracking_number}</Ltr>
                          </Typography.Link>
                        ) : (
                          <Ltr>{o.shipment.tracking_number}</Ltr>
                        ),
                      },
                      {
                        label: t('common.status'),
                        value: <StatusTag kind="shipment" value={o.shipment.status} />,
                      },
                      {
                        label: t('orders.shippedAt'),
                        value: <DateTimeText value={o.shipment.shipped_at} />,
                      },
                    ]}
                  />
                  <Timeline
                    style={{ marginTop: 16 }}
                    items={o.shipment.events.map((e, i) => ({
                      key: i,
                      content: (
                        <>
                          <StatusTag kind="shipment" value={e.status} />
                          <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                            <DateTimeText value={e.occurred_at} />
                            {e.location ? ` · ${e.location}` : ''}
                          </Typography.Text>
                          {e.description && <div>{e.description}</div>}
                        </>
                      ),
                    }))}
                  />
                </>
              )}
            </Card>
          </Space>
        </Col>
        <Col xs={24} lg={10}>
          <Space orientation="vertical" size={16} style={{ width: '100%' }}>
            <Card title={t('orders.customer')}>
              <Typography.Paragraph style={{ margin: 0 }}>
                {o.customer.name ?? '—'}
                <br />
                <Ltr>{o.customer.phone}</Ltr>
              </Typography.Paragraph>
              <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
                {a.recipient_name} · <Ltr>{a.recipient_phone}</Ltr>
                <br />
                {[a.building_no, a.street, a.district, a.city, a.postal_code]
                  .filter(Boolean)
                  .join('، ')}
                {a.short_address && (
                  <>
                    <br />
                    <Ltr>{a.short_address}</Ltr>
                  </>
                )}
              </Typography.Paragraph>
            </Card>
            <Card
              title={t('nav.payments')}
              extra={
                can('payments.view') && (
                  <Link to={`/payments/${o.payment_id}`}>{t('common.open')}</Link>
                )
              }
            >
              <Fields
                column={1}
                items={[
                  {
                    label: t('common.status'),
                    value: <StatusTag kind="payment" value={o.payment.status} />,
                  },
                  {
                    label: t('payments.captured'),
                    value: <MoneyText value={o.payment.captured_amount} />,
                  },
                  {
                    label: t('payments.refunded'),
                    value: <MoneyText value={o.payment.refunded_amount} />,
                  },
                ]}
              />
            </Card>
            <Card title={t('orders.invoices')}>
              {o.invoices.length === 0 ? (
                <Typography.Text type="secondary">—</Typography.Text>
              ) : (
                <Space orientation="vertical">
                  {o.invoices.map((inv) => (
                    <Space key={inv.id} wrap>
                      {inv.pdf_url ? (
                        <Typography.Link
                          href={inv.pdf_url}
                          target="_blank"
                          rel="noreferrer noopener"
                        >
                          <Ltr>{inv.invoice_number}</Ltr>
                        </Typography.Link>
                      ) : (
                        <Ltr>{inv.invoice_number}</Ltr>
                      )}
                      <Typography.Text type="secondary">
                        {t(`orders.invoiceTypes.${inv.type}`)} · <MoneyText value={inv.total} /> ·{' '}
                        <DateTimeText value={inv.issued_at} />
                      </Typography.Text>
                    </Space>
                  ))}
                </Space>
              )}
            </Card>
            <Card title={t('nav.refunds')}>
              <Table<Refund>
                rowKey="id"
                size="small"
                pagination={false}
                dataSource={o.refunds}
                locale={{ emptyText: '—' }}
                columns={[
                  {
                    title: t('refunds.amount'),
                    key: 'amount',
                    render: (_, r) => <MoneyText value={r.amount} />,
                  },
                  {
                    title: t('common.reason'),
                    dataIndex: 'reason',
                    render: (v: Refund['reason']) => t(`refunds.reasons.${v}`),
                  },
                  {
                    title: t('common.status'),
                    dataIndex: 'status',
                    render: (v: string) => <StatusTag kind="refund" value={v} />,
                  },
                  {
                    title: t('payments.requestedBy'),
                    key: 'by',
                    render: (_, r) => <ActorText actor={r.requested_by} />,
                  },
                ]}
              />
            </Card>
          </Space>
        </Col>
      </Row>
      {modal === 'ship' && <CreateShipmentModal orderId={o.id} onClose={() => setModal(null)} />}
      {modal === 'event' && o.shipment && (
        <ShipmentEventModal shipmentId={o.shipment.id} onClose={() => setModal(null)} />
      )}
      {modal === 'refund' && (
        <RefundRequestModal
          paymentId={o.payment_id}
          orderId={o.id}
          captured={o.payment.captured_amount}
          refunded={o.payment.refunded_amount}
          onClose={() => setModal(null)}
        />
      )}
    </>
  );
}
