import { Card } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { unwrap, useCursorList } from '../../api/lists';
import type { ReconciliationRun } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { DateText, DateTimeText } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag } from '../../components/StatusTag';

export function ReconciliationPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const list = useCursorList(['get', '/admin/reconciliation-runs'], (cursor, signal) =>
    api.GET('/admin/reconciliation-runs', { params: { query: { cursor } }, signal }).then(unwrap),
  );
  return (
    <>
      <PageHeader title={t('nav.reconciliation')} subtitle={t('reconciliation.subtitle')} />
      <Card>
        <CursorTable<ReconciliationRun>
          list={list}
          onRowClick={(r) => navigate(`/reconciliation/${r.id}`)}
          columns={[
            {
              title: t('reconciliation.runDate'),
              dataIndex: 'run_date',
              render: (v: string) => <DateText value={v} />,
            },
            { title: t('reconciliation.provider'), dataIndex: 'provider', render: () => 'Moyasar' },
            {
              title: t('common.status'),
              dataIndex: 'status',
              render: (v: ReconciliationRun['status']) => (
                <StatusTag kind="reconciliationRun" value={v} />
              ),
            },
            { title: t('reconciliation.paymentsChecked'), dataIndex: 'payments_checked' },
            { title: t('reconciliation.mismatches'), dataIndex: 'mismatch_count' },
            {
              title: t('reconciliation.startedAt'),
              dataIndex: 'started_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
            {
              title: t('reconciliation.finishedAt'),
              dataIndex: 'finished_at',
              render: (v: string | null) => <DateTimeText value={v} />,
            },
          ]}
        />
      </Card>
    </>
  );
}
