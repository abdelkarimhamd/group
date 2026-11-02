import { Card, Input, Select, Space, Switch, Tooltip, Typography } from 'antd';
import { WarningOutlined } from '@ant-design/icons';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AdminPayment, PaymentStatus } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { EXPIRY_ALERT_DAYS } from '../../config';
import { hoursUntil } from '../../lib/time';

type PaymentQuery = { status?: PaymentStatus; campaign_id?: string; expiring_within_days?: number };

/** A hold close to its tracked expiry, which no capture may cross. */
export function isExpiringSoon(
  p: Pick<AdminPayment, 'status' | 'expires_at'>,
  now = new Date(),
): boolean {
  if (p.status !== 'authorized') return false;
  const hours = hoursUntil(p.expires_at, now);
  return hours <= EXPIRY_ALERT_DAYS * 24;
}

export function PaymentsTable({ query }: { query: PaymentQuery }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const clean = cleanQuery(query);
  const list = useCursorList(['get', '/admin/payments', clean], (cursor, signal) =>
    api.GET('/admin/payments', { params: { query: { ...clean, cursor } }, signal }).then(unwrap),
  );
  return (
    <CursorTable<AdminPayment>
      list={list}
      onRowClick={(p) => navigate(`/payments/${p.id}`)}
      columns={[
        {
          title: t('campaigns.code'),
          key: 'campaign',
          render: (_, p) => <Ltr>{p.campaign.code}</Ltr>,
        },
        {
          title: t('common.status'),
          dataIndex: 'status',
          render: (v: PaymentStatus) => <StatusTag kind="payment" value={v} />,
        },
        {
          title: t('payments.card'),
          key: 'card',
          render: (_, p) => (
            <Ltr>
              {p.scheme ? t(`payments.schemes.${p.scheme}`) : p.source_type}
              {p.card_last4 ? ` •${p.card_last4}` : ''}
            </Ltr>
          ),
        },
        {
          title: t('payments.authorized'),
          key: 'auth',
          render: (_, p) => <MoneyText value={p.authorized_amount} />,
        },
        {
          title: t('payments.captured'),
          key: 'cap',
          render: (_, p) => <MoneyText value={p.captured_amount} />,
        },
        {
          title: t('payments.refunded'),
          key: 'ref',
          render: (_, p) => <MoneyText value={p.refunded_amount} />,
        },
        {
          title: t('payments.holdExpires'),
          key: 'expires',
          render: (_, p) => (
            <Space size={4}>
              {isExpiringSoon(p) && (
                <Tooltip title={t('payments.expiringSoon', { days: EXPIRY_ALERT_DAYS })}>
                  <WarningOutlined style={{ color: '#d46b08' }} />
                </Tooltip>
              )}
              <DateTimeText value={p.expires_at} />
            </Space>
          ),
        },
        {
          title: t('payments.captureCutoff'),
          dataIndex: 'capture_cutoff_at',
          render: (v: string) => <DateTimeText value={v} />,
        },
      ]}
    />
  );
}

export function PaymentsPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const expiring = params.get('expiring') === '1';
  const query: PaymentQuery = cleanQuery({
    status: (params.get('status') as PaymentStatus | null) ?? undefined,
    campaign_id: params.get('campaign_id') ?? undefined,
    expiring_within_days: expiring ? EXPIRY_ALERT_DAYS : undefined,
  });

  const setFilter = (key: string, value: string | undefined) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader title={t('nav.payments')} subtitle={t('payments.subtitle')} />
      <Card>
        <Space wrap style={{ marginBottom: 16 }} size={16}>
          <Select
            allowClear
            placeholder={t('common.status')}
            value={expiring ? 'authorized' : query.status}
            disabled={expiring}
            onChange={(v?: string) => setFilter('status', v)}
            options={statusOptions(t, 'payment')}
            style={{ width: 200 }}
          />
          <Input
            allowClear
            placeholder={t('payments.campaignId')}
            defaultValue={query.campaign_id}
            onPressEnter={(e) =>
              setFilter('campaign_id', e.currentTarget.value.trim() || undefined)
            }
            onClear={() => setFilter('campaign_id', undefined)}
            style={{ width: 320 }}
            dir="ltr"
          />
          <Space>
            <Switch
              checked={expiring}
              onChange={(on) => setFilter('expiring', on ? '1' : undefined)}
            />
            <Typography.Text>
              {t('payments.expiringFilter', { days: EXPIRY_ALERT_DAYS })}
            </Typography.Text>
          </Space>
        </Space>
        <PaymentsTable query={query} />
      </Card>
    </>
  );
}
