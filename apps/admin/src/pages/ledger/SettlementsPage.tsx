import { Card, Table, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { $api, api } from '../../api/client';
import { unwrap, useCursorList } from '../../api/lists';
import type { MoyasarSettlement, MoyasarSettlementLine } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { CodeText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';

function SettlementLines({ settlement }: { settlement: MoyasarSettlement }) {
  const { t } = useTranslation();
  const lines = $api.useQuery('get', '/admin/settlements/{settlement_id}/lines', {
    params: { path: { settlement_id: settlement.id }, query: { per_page: 100 } },
  });
  if (!lines.data) return <QueryState isPending={lines.isPending} error={lines.error} />;
  return (
    <Table<MoyasarSettlementLine>
      rowKey={(l) => `${l.type}-${l.provider_payment_id ?? ''}-${l.transacted_at ?? ''}`}
      size="small"
      pagination={false}
      dataSource={lines.data.data}
      columns={[
        { title: t('settlements.lineType'), dataIndex: 'type' },
        {
          title: t('settlements.payment'),
          key: 'payment',
          render: (_, l) =>
            l.payment_id ? (
              <Link to={`/payments/${l.payment_id}`}>
                <Ltr>{l.provider_payment_id}</Ltr>
              </Link>
            ) : (
              <>
                <CodeText>{l.provider_payment_id ?? '—'}</CodeText>{' '}
                {l.provider_payment_id && (
                  <Typography.Text type="warning">
                    ({t('settlements.unknownPayment')})
                  </Typography.Text>
                )}
              </>
            ),
        },
        {
          title: t('settlements.net'),
          key: 'amount',
          render: (_, l) => <MoneyText value={l.amount} />,
        },
        { title: t('settlements.fee'), key: 'fee', render: (_, l) => <MoneyText value={l.fee} /> },
        {
          title: t('settlements.transactedAt'),
          dataIndex: 'transacted_at',
          render: (v: string | null) => <DateTimeText value={v} />,
        },
      ]}
    />
  );
}

/** Moyasar's payouts to the bank, with what each one paid for. */
export function SettlementsPage() {
  const { t } = useTranslation();
  const list = useCursorList(['get', '/admin/settlements'], (cursor, signal) =>
    api.GET('/admin/settlements', { params: { query: { cursor } }, signal }).then(unwrap),
  );
  return (
    <>
      <PageHeader title={t('nav.settlements')} subtitle={t('settlements.subtitle')} />
      <Card>
        <CursorTable<MoyasarSettlement>
          list={list}
          expandable={{ expandedRowRender: (r) => <SettlementLines settlement={r} /> }}
          columns={[
            {
              title: t('settlements.settledAt'),
              dataIndex: 'settled_at',
              render: (v: string | null) => <DateTimeText value={v} />,
            },
            {
              title: t('settlements.providerId'),
              dataIndex: 'provider_settlement_id',
              render: (v: string) => <CodeText>{v}</CodeText>,
            },
            {
              title: t('settlements.gross'),
              key: 'gross',
              render: (_, r) => <MoneyText value={r.gross} />,
            },
            {
              title: t('settlements.fee'),
              key: 'fee',
              render: (_, r) => <MoneyText value={r.fee} />,
            },
            {
              title: t('settlements.feeVat'),
              key: 'vat',
              render: (_, r) => <MoneyText value={r.fee_vat} />,
            },
            {
              title: t('settlements.net'),
              key: 'net',
              render: (_, r) => <MoneyText value={r.net} strong />,
            },
            { title: t('settlements.lines'), dataIndex: 'line_count' },
            {
              title: (
                <Tooltip title={t('settlements.unmatchedHelp')}>
                  {t('settlements.unmatched')}
                </Tooltip>
              ),
              dataIndex: 'unmatched_count',
              render: (v: number) =>
                v > 0 ? <Typography.Text type="warning">{v}</Typography.Text> : v,
            },
          ]}
        />
      </Card>
    </>
  );
}
