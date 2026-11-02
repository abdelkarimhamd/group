import { EditOutlined, InboxOutlined, RightOutlined } from '@ant-design/icons';
import { Alert, Button, Card, Space, Steps, Table, Tooltip, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api } from '../../api/client';
import type { InboundReceipt, PurchaseOrderStatus } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { Fields } from '../../components/Fields';
import { ActorText, DateText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import {
  AdvancePurchaseOrderModal,
  EditPurchaseOrderModal,
  InboundReceiptModal,
  NEXT_PO_STATUS,
} from './PurchaseOrderModals';

const FLOW: PurchaseOrderStatus[] = [
  'draft',
  'placed',
  'supplier_confirmed',
  'paid',
  'shipped',
  'received',
];

export function PurchaseOrderDetailPage() {
  const { purchaseOrderId = '' } = useParams();
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const query = $api.useQuery('get', '/admin/purchase-orders/{purchase_order_id}', {
    params: { path: { purchase_order_id: purchaseOrderId } },
  });
  const [modal, setModal] = useState<'edit' | 'advance' | 'receipt' | null>(null);

  const po = query.data?.data;
  if (!po) return <QueryState isPending={query.isPending} error={query.error} />;
  const next = NEXT_PO_STATUS[po.status];
  const needsTracking = next === 'shipped' && !po.freight_tracking_ref;
  const variantName = (id: string) => {
    const line = po.lines.find((l) => l.product_variant.id === id);
    return line ? pick(line.product_variant, 'name') : id;
  };
  const shortReceipt = po.receipts.some((r) =>
    r.lines.some((l) => l.qty_missing > 0 || l.qty_damaged > 0),
  );

  return (
    <>
      <PageHeader
        backTo="/purchase-orders"
        title={<Ltr>{po.po_number}</Ltr>}
        subtitle={<Ltr>{po.supplier.name}</Ltr>}
        tags={<StatusTag kind="purchaseOrder" value={po.status} />}
        extra={
          <Can permission="purchasing.edit">
            {next && (
              <Tooltip title={needsTracking ? t('purchasing.needsTracking') : undefined}>
                <Button
                  type="primary"
                  icon={<RightOutlined />}
                  disabled={needsTracking}
                  onClick={() => setModal('advance')}
                >
                  {t(`purchasing.advance.${next}`)}
                </Button>
              </Tooltip>
            )}
            {po.status !== 'received' && po.status !== 'cancelled' && (
              <Button icon={<EditOutlined />} onClick={() => setModal('edit')}>
                {t('purchasing.editDetails')}
              </Button>
            )}
          </Can>
        }
      />
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        {po.status !== 'cancelled' && (
          <Card>
            <Steps
              size="small"
              current={FLOW.indexOf(po.status)}
              items={FLOW.map((s) => ({ title: t(`status.purchaseOrder.${s}`) }))}
            />
          </Card>
        )}
        {shortReceipt && (
          <Alert
            type="warning"
            showIcon
            title={t('purchasing.shortAlert')}
            action={
              can('orders.view') && (
                <Link to={`/orders?campaign_id=${po.campaign.id}`}>
                  <Button size="small">{t('purchasing.pickOrders')}</Button>
                </Link>
              )
            }
          />
        )}
        <Card>
          <Fields
            items={[
              {
                label: t('nav.campaigns'),
                value: can('campaigns.view') ? (
                  <Link to={`/campaigns/${po.campaign.id}`}>
                    <Ltr>{po.campaign.code}</Ltr>
                  </Link>
                ) : (
                  <Ltr>{po.campaign.code}</Ltr>
                ),
              },
              {
                label: t('campaigns.quotation'),
                value: can('suppliers.view') ? (
                  <Link to={`/quotations/${po.supplier_quotation_id}`}>{t('common.open')}</Link>
                ) : (
                  '—'
                ),
              },
              {
                label: t('purchasing.alibabaOrderRef'),
                value: <Ltr>{po.alibaba_order_ref ?? '—'}</Ltr>,
              },
              { label: t('purchasing.total'), value: <MoneyText value={po.total} /> },
              { label: t('suppliers.fxRate'), value: <Ltr>{po.fx_rate_to_sar}</Ltr> },
              { label: t('purchasing.totalSar'), value: <MoneyText value={po.total_sar} strong /> },
              { label: t('purchasing.placedAt'), value: <DateTimeText value={po.placed_at} /> },
              {
                label: t('purchasing.confirmedAt'),
                value: <DateTimeText value={po.supplier_confirmed_at} />,
              },
              { label: t('purchasing.paidAt'), value: <DateTimeText value={po.paid_at} /> },
              { label: t('purchasing.eta'), value: <DateText value={po.eta} /> },
              { label: t('purchasing.forwarder'), value: po.freight_forwarder ?? '—' },
              {
                label: t('purchasing.freightTracking'),
                value: <Ltr>{po.freight_tracking_ref ?? '—'}</Ltr>,
              },
            ]}
          />
        </Card>
        <Card title={t('purchasing.lines')}>
          <Table
            rowKey={(l) => l.product_variant.id}
            size="small"
            pagination={false}
            dataSource={po.lines}
            columns={[
              {
                title: t('catalog.sku'),
                key: 'sku',
                render: (_, l) => <Ltr>{l.product_variant.sku}</Ltr>,
              },
              {
                title: t('suppliers.variant'),
                key: 'variant',
                render: (_, l) => pick(l.product_variant, 'name'),
              },
              { title: t('purchasing.capturedUnits'), dataIndex: 'qty' },
              {
                title: t('suppliers.unitCost'),
                key: 'cost',
                render: (_, l) => <MoneyText value={l.unit_cost} />,
              },
            ]}
          />
        </Card>
        <Card
          title={t('purchasing.receipts')}
          extra={
            <Can permission="fulfilment.edit">
              {po.status === 'shipped' && (
                <Button size="small" icon={<InboxOutlined />} onClick={() => setModal('receipt')}>
                  {t('purchasing.recordReceipt')}
                </Button>
              )}
            </Can>
          }
        >
          {po.receipts.length === 0 ? (
            <Typography.Text type="secondary">{t('purchasing.noReceipts')}</Typography.Text>
          ) : (
            po.receipts.map((r: InboundReceipt) => (
              <div key={r.id} style={{ marginBottom: 16 }}>
                <Typography.Text strong>{r.warehouse_name}</Typography.Text>
                <Typography.Text type="secondary">
                  {' · '}
                  <DateTimeText value={r.received_at} /> · <ActorText actor={r.received_by} />
                </Typography.Text>
                {r.notes && (
                  <Typography.Paragraph style={{ margin: '4px 0' }}>{r.notes}</Typography.Paragraph>
                )}
                <Table
                  rowKey="product_variant_id"
                  size="small"
                  pagination={false}
                  dataSource={r.lines}
                  columns={[
                    {
                      title: t('suppliers.variant'),
                      key: 'v',
                      render: (_, l) => variantName(l.product_variant_id),
                    },
                    { title: t('purchasing.expected'), dataIndex: 'qty_expected' },
                    { title: t('purchasing.received'), dataIndex: 'qty_received' },
                    {
                      title: t('purchasing.damaged'),
                      dataIndex: 'qty_damaged',
                      render: (v: number) => (
                        <Typography.Text type={v ? 'danger' : undefined}>{v}</Typography.Text>
                      ),
                    },
                    {
                      title: t('purchasing.missing'),
                      dataIndex: 'qty_missing',
                      render: (v: number) => (
                        <Typography.Text type={v ? 'danger' : undefined}>{v}</Typography.Text>
                      ),
                    },
                  ]}
                />
                {r.photo_urls.length > 0 && (
                  <Space wrap style={{ marginTop: 8 }}>
                    {r.photo_urls.map((url, i) => (
                      <Typography.Link
                        key={url}
                        href={url}
                        target="_blank"
                        rel="noreferrer noopener"
                      >
                        {t('purchasing.photo')} {i + 1}
                      </Typography.Link>
                    ))}
                  </Space>
                )}
              </div>
            ))
          )}
        </Card>
      </Space>
      {modal === 'edit' && <EditPurchaseOrderModal po={po} onClose={() => setModal(null)} />}
      {modal === 'advance' && <AdvancePurchaseOrderModal po={po} onClose={() => setModal(null)} />}
      {modal === 'receipt' && <InboundReceiptModal po={po} onClose={() => setModal(null)} />}
    </>
  );
}
