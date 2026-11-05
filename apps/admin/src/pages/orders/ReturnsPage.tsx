import { Card, Select, Space } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { OrderReturn, ReturnStatus } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';

/** Customer returns, newest first. Approving or declining happens on the order. */
export function ReturnsPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery({ status: (params.get('status') as ReturnStatus | null) ?? undefined });
  const list = useCursorList(['get', '/admin/returns', query], (cursor, signal) =>
    api.GET('/admin/returns', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  return (
    <>
      <PageHeader title={t('nav.returns')} subtitle={t('returns.subtitle')} />
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
            options={statusOptions(t, 'return')}
            style={{ width: 200 }}
          />
        </Space>
        <CursorTable<OrderReturn>
          list={list}
          onRowClick={(r) => navigate(`/orders/${r.customer_order_id}`)}
          columns={[
            {
              title: t('returns.recordedAt'),
              dataIndex: 'recorded_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
            {
              title: t('orders.number'),
              key: 'order',
              render: (_, r) => (
                <Link to={`/orders/${r.customer_order_id}`} onClick={(e) => e.stopPropagation()}>
                  <Ltr>{r.order_number}</Ltr>
                </Link>
              ),
            },
            {
              title: t('orders.customer'),
              key: 'customer',
              render: (_, r) => (
                <>
                  {r.customer.name ?? '—'} · <Ltr>{r.customer.phone}</Ltr>
                </>
              ),
            },
            {
              title: t('common.reason'),
              dataIndex: 'reason',
              render: (v: OrderReturn['reason']) => t(`returns.reasons.${v}`),
            },
            {
              title: t('common.status'),
              key: 'status',
              render: (_, r) => (
                <Space size={4}>
                  <StatusTag kind="return" value={r.status} />
                  {r.status !== 'requested' && r.open && (
                    <StatusTag kind="refund" value={r.refund?.status} />
                  )}
                </Space>
              ),
            },
            {
              title: t('returns.refund'),
              key: 'refund',
              render: (_, r) => (r.refund ? <MoneyText value={r.refund.amount} /> : '—'),
            },
            {
              title: t('returns.recordedBy'),
              key: 'by',
              render: (_, r) => <ActorText actor={r.recorded_by} />,
            },
          ]}
        />
      </Card>
    </>
  );
}
