import {
  CheckOutlined,
  CloseOutlined,
  RollbackOutlined,
  SendOutlined,
  UndoOutlined,
} from '@ant-design/icons';
import { Button, Card, Col, Row, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api } from '../../api/client';
import type { AdminOrder, AdminShipment, Refund } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { Fields } from '../../components/Fields';
import { ActorText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { hoursUntil } from '../../lib/time';
import { refundableMinor, RefundRequestModal } from '../payments/RefundRequestModal';
import { ApproveReturnModal, RecordReturnModal, RejectReturnModal } from './ReturnModals';
import { isOpenShipment, ShipmentList } from './ShipmentList';
import {
  BookShipmentModal,
  CancelShipmentModal,
  CreateShipmentModal,
  ShipmentEventModal,
} from './ShipmentModals';

type ModalState =
  | { kind: 'ship' | 'book' | 'refund' | 'recordReturn' | 'approveReturn' | 'rejectReturn' }
  | { kind: 'event' | 'cancel'; shipment: AdminShipment };

/** The return can still be recorded: delivered, never returned before, window still open. */
function canRecordReturn(o: AdminOrder): boolean {
  return (
    o.status === 'delivered' &&
    o.return === null &&
    o.return_window_ends_at !== null &&
    hoursUntil(o.return_window_ends_at) >= 0
  );
}

export function OrderDetailPage() {
  const { orderId = '' } = useParams();
  const { t } = useTranslation();
  const { can } = useAuth();
  const order = $api.useQuery('get', '/admin/orders/{order_id}', {
    params: { path: { order_id: orderId } },
  });
  const courier = $api.useQuery('get', '/admin/courier', {}, { enabled: can('fulfilment.edit') });
  const [modal, setModal] = useState<ModalState | null>(null);
  const close = () => setModal(null);

  const o = order.data?.data;
  if (!o) return <QueryState isPending={order.isPending} error={order.error} />;
  const a = o.shipping_address;
  const refundable = refundableMinor(o.payment.captured_amount, o.payment.refunded_amount);
  const canShip = o.status === 'ready_to_ship' && !o.shipments.some(isOpenShipment);
  const books = courier.data?.data.books ? courier.data.data : null;
  const ret = o.return;

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
              {canShip && books && (
                <Button
                  type="primary"
                  icon={<SendOutlined />}
                  onClick={() => setModal({ kind: 'book' })}
                >
                  {t('orders.bookWith', { courier: books.name })}
                </Button>
              )}
              {canShip && (
                <Button
                  type={books ? 'default' : 'primary'}
                  icon={books ? undefined : <SendOutlined />}
                  onClick={() => setModal({ kind: 'ship' })}
                >
                  {books ? t('orders.shipByHand') : t('orders.createShipment')}
                </Button>
              )}
            </Can>
            <Can permission="refunds.request">
              {canRecordReturn(o) && (
                <Button
                  icon={<RollbackOutlined />}
                  onClick={() => setModal({ kind: 'recordReturn' })}
                >
                  {t('returns.record')}
                </Button>
              )}
              {refundable > 0 && (
                <Button icon={<UndoOutlined />} onClick={() => setModal({ kind: 'refund' })}>
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
            <Card title={t('orders.shipment')}>
              <ShipmentList
                shipments={o.shipments}
                canEdit={can('fulfilment.edit')}
                onAddEvent={(shipment) => setModal({ kind: 'event', shipment })}
                onCancel={(shipment) => setModal({ kind: 'cancel', shipment })}
              />
            </Card>
            {ret && (
              <Card
                title={t('returns.return')}
                extra={
                  ret.open && (
                    <Can permission="refunds.request">
                      <Space>
                        <Button
                          size="small"
                          type="primary"
                          icon={<CheckOutlined />}
                          onClick={() => setModal({ kind: 'approveReturn' })}
                        >
                          {t('returns.approve')}
                        </Button>
                        <Button
                          size="small"
                          danger
                          icon={<CloseOutlined />}
                          onClick={() => setModal({ kind: 'rejectReturn' })}
                        >
                          {t('returns.reject')}
                        </Button>
                      </Space>
                    </Can>
                  )
                }
              >
                <Fields
                  items={[
                    {
                      label: t('common.status'),
                      value: <StatusTag kind="return" value={ret.status} />,
                    },
                    { label: t('common.reason'), value: t(`returns.reasons.${ret.reason}`) },
                    { label: t('common.note'), value: ret.note, span: 'filled' },
                    {
                      label: t('returns.recordedBy'),
                      value: <ActorText actor={ret.recorded_by} />,
                    },
                    {
                      label: t('returns.recordedAt'),
                      value: <DateTimeText value={ret.recorded_at} />,
                    },
                    {
                      label: t('returns.decidedBy'),
                      value: <ActorText actor={ret.decided_by} />,
                      hidden: !ret.decided_at,
                    },
                    {
                      label: t('returns.decidedAt'),
                      value: <DateTimeText value={ret.decided_at} />,
                      hidden: !ret.decided_at,
                    },
                    {
                      label: t('returns.decisionNote'),
                      value: ret.decision_note,
                      span: 'filled',
                      hidden: !ret.decision_note,
                    },
                    {
                      label: t('returns.refund'),
                      value: ret.refund && (
                        <Space>
                          <MoneyText value={ret.refund.amount} />
                          <StatusTag kind="refund" value={ret.refund.status} />
                        </Space>
                      ),
                      hidden: !ret.refund,
                    },
                  ]}
                />
                {ret.open && ret.refund && (
                  <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
                    {t('returns.refundDidNotGoThrough')}
                  </Typography.Paragraph>
                )}
              </Card>
            )}
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
      {modal?.kind === 'ship' && <CreateShipmentModal orderId={o.id} onClose={close} />}
      {modal?.kind === 'book' && books && (
        <BookShipmentModal orderId={o.id} courier={books} onClose={close} />
      )}
      {modal?.kind === 'event' && (
        <ShipmentEventModal
          shipmentId={modal.shipment.id}
          collected={modal.shipment.status !== 'pending'}
          onClose={close}
        />
      )}
      {modal?.kind === 'cancel' && (
        <CancelShipmentModal
          shipmentId={modal.shipment.id}
          booked={modal.shipment.booking?.status === 'booked'}
          onClose={close}
        />
      )}
      {modal?.kind === 'refund' && (
        <RefundRequestModal
          paymentId={o.payment_id}
          orderId={o.id}
          captured={o.payment.captured_amount}
          refunded={o.payment.refunded_amount}
          onClose={close}
        />
      )}
      {modal?.kind === 'recordReturn' && <RecordReturnModal orderId={o.id} onClose={close} />}
      {modal?.kind === 'approveReturn' && ret && <ApproveReturnModal ret={ret} onClose={close} />}
      {modal?.kind === 'rejectReturn' && ret && <RejectReturnModal ret={ret} onClose={close} />}
    </>
  );
}
