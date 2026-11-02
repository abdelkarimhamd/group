import { DeleteOutlined, EditOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Button, Card, Form, Input, InputNumber, Space, Table, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { AdminCampaign, AdminTier } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr, MoneyText } from '../../components/format';
import { MoneyInput } from '../../components/forms';
import { formatMinor, minorToMajorText, parseMajorToMinor } from '../../lib/money';
import {
  chainTiers,
  checkTiers,
  tierFor,
  type TierDraft,
  type TierIssueCode,
} from '../../lib/tiers';

type Row = { max_qty: number | null; price: string };

/** Current tier set, and the editor for drafts. Saving stores a new full version. */
export function TiersTab({
  campaign,
  invalidate,
}: {
  campaign: AdminCampaign;
  invalidate: () => Promise<void>;
}) {
  const { t } = useTranslation();
  const [editing, setEditing] = useState(false);
  const canEdit = campaign.available_actions.includes('edit_tiers');
  const nextUnitTier = tierFor(campaign.tiers, campaign.committed_qty + 1);

  if (editing) {
    return (
      <TierEditor campaign={campaign} onDone={() => setEditing(false)} invalidate={invalidate} />
    );
  }

  return (
    <Card
      title={
        campaign.tier_version
          ? t('campaigns.tiers.version', { version: campaign.tier_version })
          : t('campaigns.tiers.title')
      }
      extra={
        canEdit && (
          <Button type="primary" icon={<EditOutlined />} onClick={() => setEditing(true)}>
            {campaign.tiers.length ? t('campaigns.tiers.edit') : t('campaigns.tiers.add')}
          </Button>
        )
      }
    >
      {campaign.tiers.length === 0 ? (
        <Alert type="info" showIcon title={t('campaigns.tiers.none')} />
      ) : (
        <Table<AdminTier>
          rowKey="min_qty"
          size="small"
          pagination={false}
          dataSource={campaign.tiers}
          rowClassName={(tier) => (tier === nextUnitTier ? 'ant-table-row-selected' : '')}
          columns={[
            {
              title: t('campaigns.tiers.units'),
              key: 'range',
              render: (_, tier) => (
                <Ltr>
                  {tier.min_qty} – {tier.max_qty}
                </Ltr>
              ),
            },
            {
              title: t('campaigns.tiers.unitPrice'),
              key: 'price',
              render: (_, tier) => <MoneyText value={tier.unit_price} strong />,
            },
            {
              key: 'current',
              render: (_, tier) =>
                tier === nextUnitTier && campaign.status === 'active' ? (
                  <Typography.Text type="secondary">
                    {t('campaigns.tiers.nextUnit')}
                  </Typography.Text>
                ) : null,
            },
          ]}
        />
      )}
      {!canEdit && campaign.status !== 'draft' && campaign.tiers.length > 0 && (
        <Typography.Paragraph type="secondary" style={{ marginTop: 12, marginBottom: 0 }}>
          {t('campaigns.tiers.locked')}
        </Typography.Paragraph>
      )}
    </Card>
  );
}

function TierEditor({
  campaign,
  onDone,
  invalidate,
}: {
  campaign: AdminCampaign;
  onDone: () => void;
  invalidate: () => Promise<void>;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [rows, setRows] = useState<Row[]>(() =>
    campaign.tiers.length
      ? campaign.tiers.map((tier) => ({
          max_qty: tier.max_qty,
          price: minorToMajorText(tier.unit_price.amount_minor),
        }))
      : [{ max_qty: campaign.max_qty, price: '' }],
  );
  const [reason, setReason] = useState('');
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const [submitted, setSubmitted] = useState(false);
  const needsReason = (campaign.tier_version ?? 0) >= 1;

  const chained = chainTiers(rows);
  const drafts: (TierDraft | null)[] = chained.map((row) => {
    const price = parseMajorToMinor(row.price);
    if (row.max_qty === null || price === null || price < 1) return null;
    return { min_qty: row.min_qty, max_qty: row.max_qty, unit_price_halalas: price };
  });
  const complete = drafts.every((d): d is TierDraft => d !== null);
  const issues = complete ? checkTiers(drafts as TierDraft[], campaign.max_qty) : [];

  const update = (index: number, patch: Partial<Row>) =>
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));

  const addRow = () =>
    setRows((prev) => {
      const last = prev[prev.length - 1];
      // Split the last tier: it keeps its price up to one unit before the new one.
      if (last && last.max_qty !== null && last.max_qty >= campaign.max_qty) {
        const lastMin = chainTiers(prev)[prev.length - 1]!.min_qty;
        const splitAt = Math.max(lastMin, Math.floor((lastMin + campaign.max_qty) / 2));
        return [
          ...prev.slice(0, -1),
          { ...last, max_qty: splitAt },
          { max_qty: campaign.max_qty, price: '' },
        ];
      }
      return [...prev, { max_qty: campaign.max_qty, price: '' }];
    });

  const save = async () => {
    setSubmitted(true);
    if (!complete || issues.length > 0) return;
    if (needsReason && reason.trim().length < 1) return;
    setBusy(true);
    setError(null);
    try {
      await api.PUT('/admin/campaigns/{campaign_id}/tiers', {
        params: { path: { campaign_id: campaign.id } },
        body: {
          lock_version: campaign.lock_version,
          reason: needsReason ? reason.trim() : null,
          tiers: drafts as TierDraft[],
        },
      });
      await invalidate();
      await queryClient.invalidateQueries({
        queryKey: ['get', '/admin/campaigns/{campaign_id}/margin'],
      });
      message.success(t('campaigns.tiers.saved'));
      onDone();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  const issueText = (code: TierIssueCode) =>
    t(`campaigns.tiers.issues.${code}`, { max: campaign.max_qty });
  const rowIssues = (index: number) => issues.filter((i) => i.index === index);
  const apiError = error ? toApiError(error) : null;

  return (
    <Card title={t('campaigns.tiers.editorTitle')}>
      <Typography.Paragraph type="secondary">
        {t('campaigns.tiers.editorHelp', {
          max: campaign.max_qty,
          landed: formatMinor(
            campaign.landed_cost_per_unit.amount_minor,
            campaign.landed_cost_per_unit.currency,
          ),
        })}
      </Typography.Paragraph>
      {apiError && apiError.code === 'stale_campaign' ? (
        <Alert
          type="warning"
          showIcon
          title={t('campaigns.staleCampaign')}
          style={{ marginBottom: 12 }}
        />
      ) : (
        <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      )}
      <Table
        rowKey={(_, index) => String(index)}
        size="small"
        pagination={false}
        dataSource={chained}
        columns={[
          {
            title: t('campaigns.tiers.from'),
            key: 'min',
            width: 90,
            render: (_, row) => <Ltr>{row.min_qty}</Ltr>,
          },
          {
            title: t('campaigns.tiers.to'),
            key: 'max',
            width: 140,
            render: (_, row, index) => (
              <InputNumber
                aria-label={`${t('campaigns.tiers.to')} ${index + 1}`}
                min={row.min_qty}
                max={campaign.max_qty}
                precision={0}
                value={row.max_qty}
                status={submitted && row.max_qty === null ? 'error' : undefined}
                onChange={(value) => update(index, { max_qty: value })}
              />
            ),
          },
          {
            title: t('campaigns.tiers.unitPrice'),
            key: 'price',
            render: (_, row, index) => {
              const parsed = parseMajorToMinor(row.price);
              const bad = (submitted || row.price !== '') && (parsed === null || parsed < 1);
              return (
                <MoneyInput
                  aria-label={`${t('campaigns.tiers.unitPrice')} ${index + 1}`}
                  value={row.price}
                  status={bad ? 'error' : undefined}
                  onChange={(e) => update(index, { price: e.target.value })}
                  style={{ maxWidth: 200 }}
                />
              );
            },
          },
          {
            key: 'issues',
            render: (_, __, index) => (
              <Space orientation="vertical" size={0}>
                {rowIssues(index).map((issue) => (
                  <Typography.Text key={issue.code} type="danger" style={{ fontSize: 12 }}>
                    {issueText(issue.code)}
                  </Typography.Text>
                ))}
              </Space>
            ),
          },
          {
            key: 'remove',
            width: 48,
            render: (_, __, index) =>
              rows.length > 1 && (
                <Button
                  type="text"
                  danger
                  icon={<DeleteOutlined />}
                  aria-label={t('common.remove')}
                  onClick={() => setRows((prev) => prev.filter((_, i) => i !== index))}
                />
              ),
          },
        ]}
      />
      <Button type="dashed" icon={<PlusOutlined />} onClick={addRow} style={{ marginTop: 12 }}>
        {t('campaigns.tiers.addRow')}
      </Button>
      {needsReason && (
        <Form.Item
          label={t('campaigns.tiers.reason')}
          required
          validateStatus={submitted && !reason.trim() ? 'error' : undefined}
          help={
            submitted && !reason.trim() ? t('validation.required') : t('campaigns.tiers.reasonHelp')
          }
          layout="vertical"
          style={{ marginTop: 16, maxWidth: 560 }}
        >
          <Input.TextArea
            rows={2}
            maxLength={1000}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
        </Form.Item>
      )}
      <Space style={{ marginTop: 16 }}>
        <Button type="primary" loading={busy} onClick={() => void save()}>
          {t('campaigns.tiers.save')}
        </Button>
        <Button onClick={onDone}>{t('common.cancel')}</Button>
      </Space>
    </Card>
  );
}
