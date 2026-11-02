import { Button, Card, Collapse, Timeline, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import { unwrap, useCursorList } from '../../api/lists';
import type { CampaignEvent } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { ActorText, DateTimeText } from '../../components/format';
import { JsonBlock } from '../../components/JsonBlock';
import { Loading } from '../../components/Results';
import { statusLabel } from '../../components/StatusTag';

/** Campaign history, oldest first, including every price change. */
export function EventsTab({ campaignId }: { campaignId: string }) {
  const { t } = useTranslation();
  const list = useCursorList<CampaignEvent>(
    ['get', '/admin/campaigns/{campaign_id}/events', campaignId],
    (cursor, signal) =>
      api
        .GET('/admin/campaigns/{campaign_id}/events', {
          params: { path: { campaign_id: campaignId }, query: { cursor, per_page: 100 } },
          signal,
        })
        .then(unwrap),
  );

  if (list.isPending) return <Loading />;

  return (
    <Card>
      {list.isError && <ApiErrorAlert error={list.error} style={{ marginBottom: 12 }} />}
      <Timeline
        items={list.items.map((e) => ({
          key: e.id,
          color: e.event_type === 'failed' ? 'red' : e.to_status ? 'blue' : 'gray',
          content: (
            <>
              <Typography.Text strong>
                {t(`campaigns.events.${e.event_type}` as never, { defaultValue: e.event_type })}
              </Typography.Text>
              {e.from_status && e.to_status && (
                <Typography.Text type="secondary">
                  {' '}
                  · {statusLabel(t, 'campaign', e.from_status)} →{' '}
                  {statusLabel(t, 'campaign', e.to_status)}
                </Typography.Text>
              )}
              <div>
                <Typography.Text type="secondary" style={{ fontSize: 12 }}>
                  <DateTimeText value={e.occurred_at} /> · <ActorText actor={e.actor} /> ·{' '}
                  {t('campaigns.committedAfter', { n: e.committed_qty_after })}
                </Typography.Text>
              </div>
              {Object.keys(e.payload ?? {}).length > 0 && (
                <Collapse
                  ghost
                  size="small"
                  items={[
                    {
                      key: 'p',
                      label: t('common.details'),
                      children: <JsonBlock value={e.payload} />,
                    },
                  ]}
                />
              )}
            </>
          ),
        }))}
      />
      {list.hasNextPage && (
        <Button onClick={() => void list.fetchNextPage()} loading={list.isFetchingNextPage}>
          {t('common.loadMore')}
        </Button>
      )}
    </Card>
  );
}
