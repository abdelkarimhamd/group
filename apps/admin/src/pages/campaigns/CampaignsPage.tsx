import { PlusOutlined } from '@ant-design/icons';
import { Button, Card, Select, Space, Switch, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { api } from '../../api/client';
import { cleanQuery, unwrap, useCursorList } from '../../api/lists';
import type { AdminCampaign, CampaignStatus } from '../../api/types';
import { Can } from '../../auth/AuthProvider';
import { CursorTable } from '../../components/CursorTable';
import { DateTimeText, Ltr } from '../../components/format';
import { PageHeader } from '../../components/PageHeader';
import { StatusTag, statusOptions } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';
import { CampaignProgress } from './CampaignProgress';

export function CampaignsPage() {
  const { t } = useTranslation();
  const pick = useBilingual();
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const query = cleanQuery({
    status: (params.get('status') as CampaignStatus | null) ?? undefined,
    needs_attention: params.get('needs_attention') === 'true' ? true : undefined,
  });
  const list = useCursorList(['get', '/admin/campaigns', query], (cursor, signal) =>
    api.GET('/admin/campaigns', { params: { query: { ...query, cursor } }, signal }).then(unwrap),
  );

  const setFilter = (key: string, value: string | undefined) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    setParams(next, { replace: true });
  };

  return (
    <>
      <PageHeader
        title={t('nav.campaigns')}
        extra={
          <Can permission="campaigns.edit">
            <Button
              type="primary"
              icon={<PlusOutlined />}
              onClick={() => navigate('/campaigns/new')}
            >
              {t('campaigns.newCampaign')}
            </Button>
          </Can>
        }
      />
      <Card>
        <Space wrap style={{ marginBottom: 16 }} size={16}>
          <Select
            allowClear
            placeholder={t('common.status')}
            value={query.status}
            onChange={(v?: string) => setFilter('status', v)}
            options={statusOptions(t, 'campaign')}
            style={{ width: 200 }}
          />
          <Space>
            <Switch
              checked={query.needs_attention === true}
              onChange={(checked) => setFilter('needs_attention', checked ? 'true' : undefined)}
            />
            <Typography.Text>{t('campaigns.needsAttention')}</Typography.Text>
          </Space>
        </Space>
        <CursorTable<AdminCampaign>
          list={list}
          onRowClick={(c) => navigate(`/campaigns/${c.id}`)}
          columns={[
            {
              title: t('campaigns.code'),
              dataIndex: 'code',
              render: (v: string) => <Ltr>{v}</Ltr>,
            },
            { title: t('fields.title'), key: 'title', render: (_, c) => pick(c, 'title') },
            {
              title: t('common.status'),
              key: 'status',
              render: (_, c) => (
                <Space size={4} wrap>
                  <StatusTag kind="campaign" value={c.status} />
                  {c.closing_step && c.closing_step !== 'done' && (
                    <StatusTag kind="closingStep" value={c.closing_step} />
                  )}
                </Space>
              ),
            },
            {
              title: t('campaigns.progress'),
              key: 'progress',
              render: (_, c) => <CampaignProgress campaign={c} compact />,
            },
            {
              title: t('campaigns.deadline'),
              dataIndex: 'deadline_at',
              render: (v: string | null) => <DateTimeText value={v} />,
            },
            {
              title: t('campaigns.startsAt'),
              dataIndex: 'starts_at',
              render: (v: string) => <DateTimeText value={v} />,
            },
          ]}
        />
      </Card>
    </>
  );
}
