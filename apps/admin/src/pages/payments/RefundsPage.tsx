import { Card, Select, Space } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { Refund, RefundStatus } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, DateTimeText, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';

export function RefundsPage() {
  const { t } = useTranslation();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery({ status: (params.get('status') as RefundStatus | null) ?? undefined });
  const list = useCursorList(['get', '/admin/refunds', query], (cursor, signal) =>
    api.GET('/admin/refunds', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  return (
    <>
      <PageHeader title={t('nav.refunds')} subtitle={t('refunds.subtitle')} />
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
            options={statusOptions(t, 'refund')}
            style={{ width: 200 }}
          />
        </Space>
        <CursorTable<Refund>
          list={list}
          columns={[
            {
              title: t('common.createdAt'),
              dataIndex: 'created_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
            {
              title: t('refunds.amount'),
              key: 'amount',
              render: (_, r) => <MoneyText value={r.amount} strong />,
            },
            {
              title: t('refunds.method'),
              dataIndex: 'method',
              render: (v: Refund['method']) => t(`refunds.methods.${v}`),
            },
            {
              title: t('common.reason'),
              dataIndex: 'reason',
              render: (v: Refund['reason']) => t(`refunds.reasons.${v}`),
            },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: RefundStatus) => <StatusTag kind="refund" value={v} />,
            },
            {
              title: t('payments.requestedBy'),
              key: 'by',
              render: (_, r) => <ActorText actor={r.requested_by} />,
            },
            {
              title: t('payments.payment'),
              key: 'payment',
              render: (_, r) => <Link to={`/payments/${r.payment_id}`}>{t('common.open')}</Link>,
            },
            {
              title: t('orders.order'),
              key: 'order',
              render: (_, r) =>
                r.customer_order_id && can('orders.view') ? (
                  <Link to={`/orders/${r.customer_order_id}`}>{t('common.open')}</Link>
                ) : (
                  '—'
                ),
            },
            {
              title: t('refunds.processedAt'),
              dataIndex: 'processed_at',
              render: (v: string | null) => <DateTimeText value={v} />,
            },
          ]}
        />
      </Card>
    </>
  );
}
