import { UnlockOutlined, UndoOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Button, Card, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { $api, api } from '../../api/client';
import type { PaymentTransaction } from '../../api/types';
import { Can, useAuth } from '../../auth/AuthProvider';
import { Fields } from '../../components/Fields';
import { ActorText, CodeText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { ReasonModal } from '../../components/ReasonModal';
import { QueryState } from '../../components/Results';
import { StatusTag } from '../../components/StatusTag';
import { EXPIRY_ALERT_DAYS } from '../../config';
import { isExpiringSoon } from './PaymentsPage';
import { refundableMinor, RefundRequestModal } from './RefundRequestModal';

export function PaymentDetailPage() {
  const { paymentId = '' } = useParams();
  const { t } = useTranslation();
  const { can } = useAuth();
  const payment = $api.useQuery('get', '/admin/payments/{payment_id}', {
    params: { path: { payment_id: paymentId } },
  });
  const [refunding, setRefunding] = useState(false);
  const [releasing, setReleasing] = useState(false);
  const { message } = App.useApp();
  const queryClient = useQueryClient();

  const p = payment.data?.data;
  if (!p) return <QueryState isPending={payment.isPending} error={payment.error} />;
  const refundable = refundableMinor(p.captured_amount, p.refunded_amount);

  return (
    <>
      <PageHeader
        backTo="/payments"
        title={t('payments.payment')}
        subtitle={<Ltr>{p.campaign.code}</Ltr>}
        tags={<StatusTag kind="payment" value={p.status} />}
        extra={
          <Space wrap>
            <Can permission="refunds.request">
              {refundable > 0 && (
                <Button icon={<UndoOutlined />} onClick={() => setRefunding(true)}>
                  {t('refunds.request')}
                </Button>
              )}
            </Can>
            <Can permission="payments.void">
              {p.void_requestable && (
                <Button danger icon={<UnlockOutlined />} onClick={() => setReleasing(true)}>
                  {t('payments.releaseHold')}
                </Button>
              )}
            </Can>
          </Space>
        }
      />
      <Space orientation="vertical" size={16} style={{ width: '100%' }}>
        {isExpiringSoon(p) && (
          <Alert
            type="warning"
            showIcon
            title={t('payments.expiringSoon', { days: EXPIRY_ALERT_DAYS })}
          />
        )}
        <Card>
          <Fields
            items={[
              {
                label: t('nav.campaigns'),
                value: can('campaigns.view') ? (
                  <Link to={`/campaigns/${p.campaign.id}`}>
                    <Ltr>{p.campaign.code}</Ltr>
                  </Link>
                ) : (
                  <Ltr>{p.campaign.code}</Ltr>
                ),
              },
              {
                label: t('payments.providerPaymentId'),
                value: p.provider_payment_id ? <CodeText>{p.provider_payment_id}</CodeText> : '—',
              },
              {
                label: t('payments.card'),
                value: (
                  <Ltr>
                    {p.scheme ? t(`payments.schemes.${p.scheme}`) : '—'}
                    {p.card_last4 ? ` •${p.card_last4}` : ''}
                    {p.source_type ? ` (${t(`payments.sources.${p.source_type}`)})` : ''}
                  </Ltr>
                ),
              },
              {
                label: t('payments.rejectionReason'),
                value: p.rejection_reason
                  ? t(`payments.rejectionReasons.${p.rejection_reason}`)
                  : '—',
              },
              { label: t('payments.authorized'), value: <MoneyText value={p.authorized_amount} /> },
              {
                label: t('payments.captured'),
                value: <MoneyText value={p.captured_amount} strong />,
              },
              { label: t('payments.refunded'), value: <MoneyText value={p.refunded_amount} /> },
              {
                label: t('payments.authorizedAt'),
                value: <DateTimeText value={p.authorized_at} />,
              },
              { label: t('payments.holdExpires'), value: <DateTimeText value={p.expires_at} /> },
              {
                label: t('payments.captureCutoff'),
                value: <DateTimeText value={p.capture_cutoff_at} />,
              },
              { label: t('payments.capturedAt'), value: <DateTimeText value={p.captured_at} /> },
              { label: t('payments.voidedAt'), value: <DateTimeText value={p.voided_at} /> },
              { label: t('payments.providerStatus'), value: <Ltr>{p.provider_status ?? '—'}</Ltr> },
              { label: t('payments.lastSynced'), value: <DateTimeText value={p.last_synced_at} /> },
            ]}
          />
        </Card>
        <Card title={t('payments.transactions')}>
          <Typography.Paragraph type="secondary">
            {t('payments.transactionsHelp')}
          </Typography.Paragraph>
          <Table<PaymentTransaction>
            rowKey="id"
            size="small"
            pagination={false}
            scroll={{ x: 'max-content' }}
            dataSource={p.transactions}
            columns={[
              {
                title: t('payments.txType'),
                dataIndex: 'type',
                render: (v: string) => t(`payments.txTypes.${v as PaymentTransaction['type']}`),
              },
              {
                title: t('payments.amount'),
                key: 'amount',
                render: (_, tx) => <MoneyText value={tx.amount} />,
              },
              {
                title: t('common.status'),
                dataIndex: 'status',
                render: (v: string) => <StatusTag kind="transaction" value={v} />,
              },
              {
                title: t('payments.idempotencyKey'),
                dataIndex: 'idempotency_key',
                render: (v: string) => <CodeText>{v}</CodeText>,
              },
              {
                title: t('payments.providerReference'),
                dataIndex: 'provider_reference',
                render: (v: string | null) => (v ? <Ltr>{v}</Ltr> : '—'),
              },
              {
                title: t('payments.error'),
                key: 'error',
                render: (_, tx) =>
                  tx.error_code ? (
                    <Typography.Text type="danger">
                      <Ltr>{tx.error_code}</Ltr>
                      {tx.error_message ? `: ${tx.error_message}` : ''}
                    </Typography.Text>
                  ) : (
                    '—'
                  ),
              },
              {
                title: t('payments.requestedBy'),
                key: 'by',
                render: (_, tx) => <ActorText actor={tx.requested_by} />,
              },
              {
                title: t('common.createdAt'),
                dataIndex: 'created_at',
                render: (v: string) => <DateTimeText value={v} />,
              },
              {
                title: t('payments.completedAt'),
                dataIndex: 'completed_at',
                render: (v: string | null) => <DateTimeText value={v} />,
              },
            ]}
          />
        </Card>
      </Space>
      <ReasonModal
        open={releasing}
        danger
        title={t('payments.releaseHoldTitle')}
        okText={t('refunds.submit')}
        description={t('payments.releaseHoldHelp')}
        onCancel={() => setReleasing(false)}
        onSubmit={async (reason) => {
          await api.POST('/admin/payments/{payment_id}/void', {
            params: { path: { payment_id: p.id } },
            body: { reason },
          });
          await queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] });
          message.success(t('payments.releaseHoldRequested'));
          setReleasing(false);
        }}
      />
      {refunding && (
        <RefundRequestModal
          paymentId={p.id}
          captured={p.captured_amount}
          refunded={p.refunded_amount}
          onClose={() => setRefunding(false)}
        />
      )}
    </>
  );
}
