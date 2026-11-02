import { Card, Col, Input, Row, Space, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AuditLogEntry } from '../../api/types';
import { CursorTable } from '../../components/CursorTable';
import { ActorText, CodeText, DateTimeText, Ltr } from '../../components/format';
import { JsonBlock } from '../../components/JsonBlock';
import { PageHeader } from '../../components/PageHeader';

const FILTERS = ['action', 'subject_type', 'subject_id', 'actor_admin_id'] as const;

export function AuditLogPage() {
  const { t } = useTranslation();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery(Object.fromEntries(FILTERS.map((f) => [f, params.get(f) ?? undefined])));
  const list = useCursorList(['get', '/admin/audit-logs', query], (cursor, signal) =>
    api.GET('/admin/audit-logs', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  return (
    <>
      <PageHeader title={t('nav.auditLog')} subtitle={t('audit.subtitle')} />
      <Card>
        <Space wrap style={{ marginBottom: 16 }}>
          {FILTERS.map((f) => (
            <Input.Search
              key={f}
              allowClear
              dir="ltr"
              placeholder={t(`audit.filters.${f}`)}
              defaultValue={query[f] as string | undefined}
              onSearch={(value) => {
                const next = new URLSearchParams(params);
                if (value.trim()) next.set(f, value.trim());
                else next.delete(f);
                setParams(next, { replace: true });
              }}
              style={{ width: f.endsWith('_id') ? 320 : 200 }}
            />
          ))}
        </Space>
        <CursorTable<AuditLogEntry>
          list={list}
          expandable={{
            expandedRowRender: (e) => (
              <Row gutter={16}>
                <Col xs={24} md={12}>
                  <Typography.Text strong>{t('audit.before')}</Typography.Text>
                  <JsonBlock value={e.before} />
                </Col>
                <Col xs={24} md={12}>
                  <Typography.Text strong>{t('audit.after')}</Typography.Text>
                  <JsonBlock value={e.after} />
                </Col>
                <Col span={24} style={{ marginTop: 8 }}>
                  <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                    IP: <Ltr>{e.ip ?? '—'}</Ltr> · {t('audit.userAgent')}:{' '}
                    <Ltr>{e.user_agent ?? '—'}</Ltr>
                  </Typography.Text>
                </Col>
              </Row>
            ),
          }}
          columns={[
            {
              title: t('audit.when'),
              dataIndex: 'occurred_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
            {
              title: t('audit.who'),
              key: 'actor',
              render: (_, e) => <ActorText actor={e.actor} />,
            },
            {
              title: t('audit.action'),
              dataIndex: 'action',
              render: (v: string) => <Ltr>{v}</Ltr>,
            },
            {
              title: t('approvals.subject'),
              key: 'subject',
              render: (_, e) => (
                <Ltr>
                  {e.subject_type ?? '—'}
                  {e.subject_id ? ` ${e.subject_id.slice(0, 8)}…` : ''}
                </Ltr>
              ),
            },
            {
              title: t('errors.requestId'),
              dataIndex: 'request_id',
              render: (v: string | null) => (v ? <CodeText>{v}</CodeText> : '—'),
            },
          ]}
        />
      </Card>
    </>
  );
}
