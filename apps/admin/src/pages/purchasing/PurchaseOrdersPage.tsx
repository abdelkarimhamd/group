import { Card, Select, Space } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { PurchaseOrder, PurchaseOrderStatus } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { DateText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';

export function PurchaseOrdersPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery({
    status: (params.get('status') as PurchaseOrderStatus | null) ?? undefined,
  });
  const list = useCursorList(['get', '/admin/purchase-orders', query], (cursor, signal) =>
    api
      .GET('/admin/purchase-orders', { params: { query: { ...query, cursor } }, signal })
      .then(unwrap),
  );

  return (
    <>
      <PageHeader title={t('nav.purchaseOrders')} subtitle={t('purchasing.subtitle')} />
      <Card>
        <Space style={{ marginBottom: 16 }}>
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(v?: string) => {
              const next = new URLSearchParams(params);
              if (v) next.set('status', v);
              else next.delete('status');
              setParams(next, { replace: true });
            }}
            options={statusOptions(t, 'purchaseOrder')}
            style={{ width: 200 }}
          />
        </Space>
        <CursorTable<PurchaseOrder>
          list={list}
          onRowClick={(po) => navigate(`/purchase-orders/${po.id}`)}
          columns={[
            {
              title: t('purchasing.poNumber'),
              dataIndex: 'po_number',
              render: (v: string) => <Ltr>{v}</Ltr>,
            },
            {
              title: t('campaigns.code'),
              key: 'campaign',
              render: (_, po) => <Ltr>{po.campaign.code}</Ltr>,
            },
            {
              title: t('suppliers.supplier'),
              key: 'supplier',
              render: (_, po) => <Ltr>{po.supplier.name}</Ltr>,
            },
            {
              title: t('purchasing.total'),
              key: 'total',
              render: (_, po) => <MoneyText value={po.total} />,
            },
            {
              title: t('purchasing.totalSar'),
              key: 'total_sar',
              render: (_, po) => <MoneyText value={po.total_sar} />,
            },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: PurchaseOrderStatus) => <StatusTag kind="purchaseOrder" value={v} />,
            },
            {
              title: t('purchasing.eta'),
              dataIndex: 'eta',
              render: (v: string | null) => <DateText value={v} />,
            },
          ]}
        />
      </Card>
    </>
  );
}
