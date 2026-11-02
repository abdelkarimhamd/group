import { Card, Select, Space } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AdminParticipation, ParticipationStatus } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr, MoneyText } from '../../components/format';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';

export function ParticipationsTab({ campaignId }: { campaignId: string }) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { can } = useAuth();
  const [status, setStatus] = useState<ParticipationStatus | undefined>();
  const query = cleanQuery({ status });
  const list = useCursorList(
    ['get', '/admin/campaigns/{campaign_id}/participations', campaignId, query],
    (cursor, signal) =>
      api
        .GET('/admin/campaigns/{campaign_id}/participations', {
          params: { path: { campaign_id: campaignId }, query: { ...query, cursor } },
          signal,
        })
        .then(unwrap),
  );

  return (
    <Card>
      <Space style={{ marginBottom: 16 }}>
        <Select
          allowClear
          placeholder={t('common.status')}
          value={status}
          onChange={setStatus}
          options={statusOptions(t, 'participation')}
          style={{ width: 200 }}
        />
      </Space>
      <CursorTable<AdminParticipation>
        list={list}
        columns={[
          {
            title: t('orders.customer'),
            key: 'customer',
            render: (_, p) => (
              <>
                {p.customer.name ?? '—'}
                <br />
                <Ltr>{p.customer.phone}</Ltr>
              </>
            ),
          },
          {
            title: t('suppliers.variant'),
            key: 'variant',
            render: (_, p) => pick(p.product_variant, 'name'),
          },
          { title: t('common.quantity'), dataIndex: 'quantity' },
          {
            title: t('common.status'),
            dataIndex: 'status',
            render: (v: ParticipationStatus) => <StatusTag kind="participation" value={v} />,
          },
          {
            title: t('campaigns.holdUnitPrice'),
            key: 'hup',
            render: (_, p) => <MoneyText value={p.hold_unit_price} />,
          },
          {
            title: t('campaigns.holdAmount'),
            key: 'ha',
            render: (_, p) => <MoneyText value={p.hold_amount} />,
          },
          {
            title: t('campaigns.finalUnitPrice'),
            key: 'fup',
            render: (_, p) => <MoneyText value={p.final_unit_price} />,
          },
          {
            title: t('campaigns.captureAmount'),
            key: 'ca',
            render: (_, p) => <MoneyText value={p.capture_amount} />,
          },
          {
            title: t('nav.payments'),
            key: 'payment',
            render: (_, p) =>
              p.payment ? (
                can('payments.view') ? (
                  <Link to={`/payments/${p.payment.id}`}>
                    <StatusTag kind="payment" value={p.payment.status} />
                  </Link>
                ) : (
                  <StatusTag kind="payment" value={p.payment.status} />
                )
              ) : (
                '—'
              ),
          },
          {
            title: t('common.createdAt'),
            dataIndex: 'created_at',
            render: (v: string) => <DateTimeText value={v} />,
          },
        ]}
      />
    </Card>
  );
}
