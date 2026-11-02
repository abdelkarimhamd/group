import { Badge, Card, Col, Empty, Flex, Row, Typography, theme } from 'antd';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router-dom';
import { $api } from '../../api/client';
import { useAuth } from '../../auth/AuthProvider';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { ActorText, DateText, DateTimeText, Ltr, MoneyText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag } from '../../components/StatusTag';
import { EXPIRY_ALERT_DAYS } from '../../config';
import { useBilingual } from '../../i18n/LocaleProvider';

const PREVIEW = 5;

/** What needs someone today: decisions, holds near expiry, approvals and reconciliation. */
export function DashboardPage() {
  const { t } = useTranslation();
  const { admin, can } = useAuth();
  return (
    <>
      <PageHeader
        title={t('dashboard.greeting', { name: admin?.name ?? '' })}
        subtitle={t('dashboard.subtitle')}
      />
      <Row gutter={[16, 16]}>
        {can('campaigns.view') && (
          <Col xs={24} xl={12}>
            <CampaignsNeedingAttention />
          </Col>
        )}
        {can('payments.view') && (
          <Col xs={24} xl={12}>
            <ExpiringHolds />
          </Col>
        )}
        {can('approvals.view') && (
          <Col xs={24} xl={12}>
            <PendingApprovals />
          </Col>
        )}
        {can('reconciliation.view') && (
          <Col xs={24} xl={12}>
            <LatestReconciliation />
          </Col>
        )}
      </Row>
    </>
  );
}

function Panel({
  title,
  count,
  more,
  children,
  error,
  loading,
}: {
  title: string;
  count?: number;
  more: string;
  children: ReactNode;
  error: unknown;
  loading: boolean;
}) {
  const { t } = useTranslation();
  return (
    <Card
      loading={loading}
      title={
        <>
          {title}{' '}
          {count !== undefined && (
            <Badge
              count={count}
              showZero
              color={count ? '#d46b08' : '#bfbfbf'}
              overflowCount={99}
            />
          )}
        </>
      }
      extra={<Link to={more}>{t('dashboard.viewAll')}</Link>}
      style={{ height: '100%' }}
    >
      {error ? <ApiErrorAlert error={error} /> : children}
    </Card>
  );
}

function CampaignsNeedingAttention() {
  const { t } = useTranslation();
  const pick = useBilingual();
  const q = $api.useQuery('get', '/admin/campaigns', {
    params: { query: { needs_attention: true, per_page: PREVIEW } },
  });
  const rows = q.data?.data ?? [];
  return (
    <Panel
      title={t('dashboard.campaignsAttention')}
      count={q.data ? rows.length + (q.data.meta.next_cursor ? 1 : 0) : undefined}
      more="/campaigns?needs_attention=true"
      error={q.error}
      loading={q.isPending}
    >
      {rows.length === 0 ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('dashboard.nothingWaiting')} />
      ) : (
        <PreviewList
          rows={rows}
          render={(c) => ({
            title: <Link to={`/campaigns/${c.id}`}>{pick(c, 'title')}</Link>,
            description: (
              <>
                <Ltr>{c.code}</Ltr>
                {c.supplier_decision_due_at && (
                  <>
                    {' · '}
                    {t('campaigns.supplierDecision.dueBy')}{' '}
                    <DateTimeText value={c.supplier_decision_due_at} />
                  </>
                )}
              </>
            ),
            extra: (
              <StatusTag
                kind={c.closing_step ? 'closingStep' : 'campaign'}
                value={c.closing_step ?? c.status}
              />
            ),
          })}
        />
      )}
    </Panel>
  );
}

function ExpiringHolds() {
  const { t } = useTranslation();
  const q = $api.useQuery('get', '/admin/payments', {
    params: { query: { expiring_within_days: EXPIRY_ALERT_DAYS, per_page: PREVIEW } },
  });
  const rows = q.data?.data ?? [];
  return (
    <Panel
      title={t('dashboard.expiringHolds', { days: EXPIRY_ALERT_DAYS })}
      count={q.data ? rows.length + (q.data.meta.next_cursor ? 1 : 0) : undefined}
      more="/payments?expiring=1"
      error={q.error}
      loading={q.isPending}
    >
      {rows.length === 0 ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('dashboard.noExpiringHolds')} />
      ) : (
        <PreviewList
          rows={rows}
          render={(p) => ({
            title: (
              <Link to={`/payments/${p.id}`}>
                <Ltr>{p.campaign.code}</Ltr>
              </Link>
            ),
            description: (
              <>
                {t('payments.holdExpires')}: <DateTimeText value={p.expires_at} />
              </>
            ),
            extra: <MoneyText value={p.authorized_amount} />,
          })}
        />
      )}
    </Panel>
  );
}

function PendingApprovals() {
  const { t } = useTranslation();
  const q = $api.useQuery('get', '/admin/approval-requests', {
    params: { query: { status: 'pending', per_page: PREVIEW } },
  });
  const rows = q.data?.data ?? [];
  return (
    <Panel
      title={t('dashboard.pendingApprovals')}
      count={q.data ? rows.length + (q.data.meta.next_cursor ? 1 : 0) : undefined}
      more="/approvals"
      error={q.error}
      loading={q.isPending}
    >
      {rows.length === 0 ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('dashboard.noApprovals')} />
      ) : (
        <PreviewList
          rows={rows}
          render={(r) => ({
            title: t(`approvals.types.${r.type}`),
            description: (
              <>
                <ActorText actor={r.requested_by} /> · <DateTimeText value={r.created_at} />
              </>
            ),
          })}
        />
      )}
    </Panel>
  );
}

function LatestReconciliation() {
  const { t } = useTranslation();
  const q = $api.useQuery('get', '/admin/reconciliation-runs', {
    params: { query: { per_page: 1 } },
  });
  const run = q.data?.data[0];
  return (
    <Panel
      title={t('dashboard.latestReconciliation')}
      more="/reconciliation"
      error={q.error}
      loading={q.isPending}
    >
      {!run ? (
        <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={t('dashboard.noRuns')} />
      ) : (
        <Link to={`/reconciliation/${run.id}`}>
          <Typography.Paragraph style={{ margin: 0 }}>
            <DateText value={run.run_date} /> ·{' '}
            <StatusTag kind="reconciliationRun" value={run.status} />
          </Typography.Paragraph>
          <Typography.Text type="secondary">
            {t('dashboard.runSummary', {
              checked: run.payments_checked,
              mismatches: run.mismatch_count,
            })}
          </Typography.Text>
        </Link>
      )}
    </Panel>
  );
}

type PreviewRow = { title: React.ReactNode; description: React.ReactNode; extra?: React.ReactNode };

/** A short list of rows for a dashboard panel. */
function PreviewList<T extends { id: string }>({
  rows,
  render,
}: {
  rows: T[];
  render: (row: T) => PreviewRow;
}) {
  const { token } = theme.useToken();
  return (
    <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
      {rows.map((row, index) => {
        const { title, description, extra } = render(row);
        return (
          <li
            key={row.id}
            style={{
              padding: '8px 0',
              borderTop: index === 0 ? undefined : `1px solid ${token.colorSplit}`,
            }}
          >
            <Flex justify="space-between" align="center" gap={12}>
              <div style={{ minWidth: 0 }}>
                <div>{title}</div>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  {description}
                </Typography.Text>
              </div>
              {extra && <div style={{ flexShrink: 0 }}>{extra}</div>}
            </Flex>
          </li>
        );
      })}
    </ul>
  );
}
