import { CheckOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Card, Col, Row, Segmented, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { ReconciliationItem } from '../../api/types';
import { useAuth } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, DateTimeText } from '../../components/format';
import { JsonBlock } from '../../components/JsonBlock';
import { PageHeader } from '../../components/PageHeader';
import { ReasonModal } from '../../components/ReasonModal';

type Filter = 'open' | 'resolved' | 'all';

export function ReconciliationRunPage() {
  const { runId = '' } = useParams();
  const { t } = useTranslation();
  const { can } = useAuth();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [filter, setFilter] = useState<Filter>('open');
  const [resolving, setResolving] = useState<ReconciliationItem | null>(null);
  const query = cleanQuery({ resolved: filter === 'all' ? undefined : filter === 'resolved' });
  const list = useCursorList(
    ['get', '/admin/reconciliation-runs/{run_id}/items', runId, query],
    (cursor, signal) =>
      api
        .GET('/admin/reconciliation-runs/{run_id}/items', {
          params: { path: { run_id: runId }, query: { ...query, cursor } },
          signal,
        })
        .then(unwrap),
  );

  return (
    <>
      <PageHeader
        backTo="/reconciliation"
        title={t('reconciliation.mismatchesTitle')}
        subtitle={t('reconciliation.resolveHelp')}
      />
      <Card>
        <Segmented<Filter>
          style={{ marginBottom: 16 }}
          value={filter}
          onChange={setFilter}
          options={[
            { value: 'open', label: t('reconciliation.open') },
            { value: 'resolved', label: t('reconciliation.resolved') },
            { value: 'all', label: t('common.all') },
          ]}
        />
        <CursorTable<ReconciliationItem>
          list={list}
          expandable={{
            expandedRowRender: (item) => (
              <Row gutter={16}>
                <Col xs={24} md={12}>
                  <Typography.Text strong>{t('reconciliation.ours')}</Typography.Text>
                  <JsonBlock value={item.our_value} />
                </Col>
                <Col xs={24} md={12}>
                  <Typography.Text strong>{t('reconciliation.moyasar')}</Typography.Text>
                  <JsonBlock value={item.provider_value} />
                </Col>
              </Row>
            ),
          }}
          columns={[
            {
              title: t('reconciliation.mismatchType'),
              dataIndex: 'mismatch_type',
              render: (v: ReconciliationItem['mismatch_type']) => t(`reconciliation.types.${v}`),
            },
            {
              title: t('payments.payment'),
              dataIndex: 'payment_id',
              render: (v: string | null) =>
                v ? <Link to={`/payments/${v}`}>{t('common.open')}</Link> : '—',
            },
            {
              title: t('reconciliation.resolution'),
              key: 'resolution',
              render: (_, item) =>
                item.resolved_at ? (
                  <>
                    <ActorText actor={item.resolved_by} /> ·{' '}
                    <DateTimeText value={item.resolved_at} />
                    <br />
                    <Typography.Text type="secondary">{item.resolution_note}</Typography.Text>
                  </>
                ) : (
                  '—'
                ),
            },
            ...(can('reconciliation.resolve')
              ? [
                  {
                    key: 'resolve',
                    render: (_: unknown, item: ReconciliationItem) =>
                      !item.resolved_at && (
                        <Button
                          size="small"
                          icon={<CheckOutlined />}
                          onClick={() => setResolving(item)}
                        >
                          {t('reconciliation.resolve')}
                        </Button>
                      ),
                  },
                ]
              : []),
          ]}
        />
      </Card>
      <ReasonModal
        open={resolving !== null}
        title={t('reconciliation.resolve')}
        description={t('reconciliation.resolveHelp')}
        label={t('common.note')}
        maxLength={2000}
        onCancel={() => setResolving(null)}
        onSubmit={async (note) => {
          if (!resolving) return;
          await api.POST('/admin/reconciliation-items/{item_id}/resolve', {
            params: { path: { item_id: resolving.id } },
            body: { note },
          });
          await queryClient.invalidateQueries({
            queryKey: ['get', '/admin/reconciliation-runs/{run_id}/items'],
          });
          message.success(t('reconciliation.resolvedDone'));
          setResolving(null);
        }}
      />
    </>
  );
}
