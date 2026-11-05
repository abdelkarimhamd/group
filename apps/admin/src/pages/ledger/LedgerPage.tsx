import { PlusOutlined, RollbackOutlined } from '@ant-design/icons';
import { Button, Card, Select, Space, Table, Tabs, Tag, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router-dom';
import { $api, api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type {
  LedgerAccountBalance,
  LedgerAccountCode,
  LedgerAdjustment,
  LedgerAdjustmentStatus,
  LedgerLine,
  LedgerTransaction,
  LedgerTransactionType,
} from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, CodeText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { QueryState } from '../../components/Results';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { LEDGER_ACCOUNTS, LedgerAdjustmentModal, ReverseTransactionModal } from './LedgerModals';

const TYPES: LedgerTransactionType[] = [
  'capture',
  'refund',
  'revenue_recognition',
  'adjustment',
  'reversal',
  'settlement',
];

function Lines({ lines }: { lines: LedgerLine[] }) {
  const { t } = useTranslation();
  return (
    <Table<LedgerLine>
      rowKey={(l) => `${l.account}-${l.direction}-${l.amount.amount_minor}`}
      size="small"
      pagination={false}
      dataSource={lines}
      columns={[
        { title: t('ledger.account'), dataIndex: 'account_name' },
        {
          title: t('ledger.debits'),
          key: 'd',
          render: (_, l) => (l.direction === 'D' ? <MoneyText value={l.amount} /> : ''),
        },
        {
          title: t('ledger.credits'),
          key: 'c',
          render: (_, l) => (l.direction === 'C' ? <MoneyText value={l.amount} /> : ''),
        },
      ]}
    />
  );
}

function Balances() {
  const { t } = useTranslation();
  const accounts = $api.useQuery('get', '/admin/ledger/accounts');
  if (!accounts.data) return <QueryState isPending={accounts.isPending} error={accounts.error} />;
  return (
    <>
      <Typography.Paragraph type="secondary">{t('ledger.balanceHelp')}</Typography.Paragraph>
      <Table<LedgerAccountBalance>
        rowKey="code"
        size="small"
        pagination={false}
        scroll={{ x: 'max-content' }}
        dataSource={accounts.data.data}
        columns={[
          { title: t('ledger.account'), dataIndex: 'name' },
          {
            title: t('ledger.accountType'),
            dataIndex: 'type',
            render: (v: LedgerAccountBalance['type']) => t(`ledger.accountTypes.${v}`),
          },
          {
            title: t('ledger.debits'),
            key: 'debits',
            render: (_, a) => <MoneyText value={a.debits} />,
          },
          {
            title: t('ledger.credits'),
            key: 'credits',
            render: (_, a) => <MoneyText value={a.credits} />,
          },
          {
            title: t('ledger.balance'),
            key: 'balance',
            render: (_, a) => <MoneyText value={a.balance} strong />,
          },
        ]}
      />
    </>
  );
}

function Postings({ onReverse }: { onReverse: (tx: LedgerTransaction) => void }) {
  const { t } = useTranslation();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery({
    type: (params.get('type') as LedgerTransactionType | null) ?? undefined,
    account: (params.get('account') as LedgerAccountCode | null) ?? undefined,
  });
  const list = useCursorList(['get', '/admin/ledger/transactions', query], (cursor, signal) =>
    api
      .GET('/admin/ledger/transactions', { params: { query: { ...query, cursor } }, signal })
      .then(unwrap),
  );
  const filter = (key: 'type' | 'account', value?: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  return (
    <>
      <Space wrap style={{ marginBottom: 16 }}>
        <Select
          allowClear
          placeholder={t('ledger.type')}
          value={query.type}
          onChange={(v?: string) => filter('type', v)}
          options={TYPES.map((v) => ({ value: v, label: t(`ledger.types.${v}`) }))}
          style={{ width: 200 }}
        />
        <Select
          allowClear
          placeholder={t('ledger.account')}
          value={query.account}
          onChange={(v?: string) => filter('account', v)}
          options={LEDGER_ACCOUNTS.map((v) => ({ value: v, label: v }))}
          style={{ width: 240 }}
        />
      </Space>
      <CursorTable<LedgerTransaction>
        list={list}
        expandable={{ expandedRowRender: (r) => <Lines lines={r.entries} /> }}
        columns={[
          {
            title: t('ledger.occurredAt'),
            dataIndex: 'occurred_at',
            render: (v: string) => <DateTimeText value={v} />,
          },
          {
            title: t('ledger.type'),
            dataIndex: 'type',
            render: (v: LedgerTransactionType) => <Tag>{t(`ledger.types.${v}`)}</Tag>,
          },
          { title: t('ledger.description'), dataIndex: 'description' },
          {
            title: t('orders.number'),
            key: 'order',
            render: (_, r) =>
              r.order ? (
                <Link to={`/orders/${r.order.id}`}>
                  <Ltr>{r.order.number}</Ltr>
                </Link>
              ) : (
                '—'
              ),
          },
          {
            title: t('ledger.reference'),
            dataIndex: 'reference',
            render: (v: string) => <CodeText>{v}</CodeText>,
          },
          {
            title: t('ledger.postedBy'),
            key: 'actor',
            render: (_, r) => <ActorText actor={r.actor} />,
          },
          ...(can('ledger.adjust')
            ? [
                {
                  key: 'actions',
                  render: (_: unknown, r: LedgerTransaction) =>
                    r.type === 'reversal' ? null : (
                      <Button
                        size="small"
                        icon={<RollbackOutlined />}
                        onClick={(e) => {
                          e.stopPropagation();
                          onReverse(r);
                        }}
                      >
                        {t('ledger.reverse')}
                      </Button>
                    ),
                },
              ]
            : []),
        ]}
      />
    </>
  );
}

function Adjustments() {
  const { t } = useTranslation();
  const [status, setStatus] = useState<LedgerAdjustmentStatus | undefined>();
  const query = cleanQuery({ status });
  const list = useCursorList(['get', '/admin/ledger/adjustments', query], (cursor, signal) =>
    api
      .GET('/admin/ledger/adjustments', { params: { query: { ...query, cursor } }, signal })
      .then(unwrap),
  );
  return (
    <>
      <Select
        allowClear
        placeholder={t('common.status')}
        value={status}
        onChange={(v?: LedgerAdjustmentStatus) => setStatus(v)}
        options={statusOptions(t, 'ledgerAdjustment')}
        style={{ width: 220, marginBottom: 16 }}
      />
      <CursorTable<LedgerAdjustment>
        list={list}
        expandable={{
          expandedRowRender: (r) =>
            r.lines ? (
              <Lines lines={r.lines} />
            ) : (
              <>
                {t('ledger.reverses')}: <CodeText>{r.reverses?.reference}</CodeText> ·{' '}
                {r.reverses?.description}
              </>
            ),
        }}
        columns={[
          {
            title: t('common.createdAt'),
            dataIndex: 'created_at',
            render: (v: string) => <DateTimeText value={v} />,
          },
          { title: t('ledger.description'), dataIndex: 'description' },
          {
            title: t('ledger.type'),
            key: 'kind',
            render: (_, r) =>
              r.reverses ? t('ledger.types.reversal') : t('ledger.types.adjustment'),
          },
          {
            title: t('common.status'),
            dataIndex: 'status',
            render: (v: LedgerAdjustmentStatus) => <StatusTag kind="ledgerAdjustment" value={v} />,
          },
          {
            title: t('payments.requestedBy'),
            key: 'by',
            render: (_, r) => <ActorText actor={r.requested_by} />,
          },
        ]}
      />
    </>
  );
}

/** Balances, postings and hand adjustments of the double-entry ledger. */
export function LedgerPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') ?? 'balances';
  const accounts = $api.useQuery('get', '/admin/ledger/accounts');
  const [adjusting, setAdjusting] = useState(false);
  const [reversing, setReversing] = useState<LedgerTransaction | null>(null);
  const accountNames = Object.fromEntries(
    (accounts.data?.data ?? []).map((a) => [a.code, a.name]),
  ) as Partial<Record<LedgerAccountCode, string>>;

  return (
    <>
      <PageHeader
        title={t('nav.ledger')}
        subtitle={t('ledger.subtitle')}
        extra={
          <Can permission="ledger.adjust">
            <Button type="primary" icon={<PlusOutlined />} onClick={() => setAdjusting(true)}>
              {t('ledger.newAdjustment')}
            </Button>
          </Can>
        }
      />
      <Card>
        <Tabs
          activeKey={tab}
          onChange={(key) => setParams({ tab: key }, { replace: true })}
          items={[
            { key: 'balances', label: t('ledger.balances'), children: <Balances /> },
            {
              key: 'postings',
              label: t('ledger.postings'),
              children: <Postings onReverse={setReversing} />,
            },
            { key: 'adjustments', label: t('ledger.adjustments'), children: <Adjustments /> },
          ]}
        />
      </Card>
      {adjusting && (
        <LedgerAdjustmentModal accountNames={accountNames} onClose={() => setAdjusting(false)} />
      )}
      {reversing && (
        <ReverseTransactionModal transaction={reversing} onClose={() => setReversing(null)} />
      )}
    </>
  );
}
