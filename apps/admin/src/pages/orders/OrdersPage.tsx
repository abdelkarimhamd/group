import { Card, Input, Select, Space } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AdminOrder, OrderStatus } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';

type OrderQuery = { status?: OrderStatus; campaign_id?: string; q?: string };

export function OrdersTable({ query }: { query: OrderQuery }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const clean = cleanQuery(query);
  const list = useCursorList(['get', '/admin/orders', clean], (cursor, signal) =>
    api.GET('/admin/orders', { params: { query: { ...clean, cursor } }, signal }).then(unwrap),
  );
  return (
    <CursorTable<AdminOrder>
      list={list}
      onRowClick={(o) => navigate(`/orders/${o.id}`)}
      columns={[
        {
          title: t('orders.number'),
          dataIndex: 'order_number',
          render: (v: string) => <Ltr>{v}</Ltr>,
        },
        {
          title: t('orders.customer'),
          key: 'customer',
          render: (_, o) => (
            <>
              {o.customer.name ?? '—'}
              <br />
              <Ltr>{o.customer.phone}</Ltr>
            </>
          ),
        },
        {
          title: t('campaigns.code'),
          key: 'campaign',
          render: (_, o) => <Ltr>{o.campaign.code}</Ltr>,
        },
        {
          title: t('suppliers.variant'),
          key: 'variant',
          render: (_, o) => `${o.product_variant.name} × ${o.quantity}`,
        },
        { title: t('orders.total'), key: 'total', render: (_, o) => <MoneyText value={o.total} /> },
        {
          title: t('common.status'),
          dataIndex: 'status',
          render: (v: OrderStatus) => <StatusTag kind="order" value={v} />,
        },
        {
          title: t('nav.payments'),
          key: 'payment',
          render: (_, o) => <StatusTag kind="payment" value={o.payment.status} />,
        },
        {
          title: t('orders.shipment'),
          key: 'shipment',
          render: (_, o) =>
            o.shipment ? <StatusTag kind="shipment" value={o.shipment.status} /> : '—',
        },
        {
          title: t('common.createdAt'),
          dataIndex: 'created_at',
          render: (v: string) => <DateTimeText value={v} />,
        },
      ]}
    />
  );
}

export function OrdersPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const query: OrderQuery = cleanQuery({
    status: (params.get('status') as OrderStatus | null) ?? undefined,
    campaign_id: params.get('campaign_id') ?? undefined,
    q: params.get('q') ?? undefined,
  });
  const setFilter = (key: string, value: string | undefined) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader title={t('nav.orders')} subtitle={t('orders.subtitle')} />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          <Input.Search
            allowClear
            placeholder={t('orders.search')}
            defaultValue={query.q}
            onSearch={(v) => setFilter('q', v.trim() || undefined)}
            style={{ width: 280 }}
          />
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(v?: string) => setFilter('status', v)}
            options={statusOptions(t, 'order')}
            style={{ width: 200 }}
          />
        </Space>
        <OrdersTable query={query} />
      </Card>
    </>
  );
}
