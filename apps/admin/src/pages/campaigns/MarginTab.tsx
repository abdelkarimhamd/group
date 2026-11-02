import { CheckCircleTwoTone, CloseCircleTwoTone } from '@ant-design/icons';
import { Alert, Card, Space, Statistic, Table, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import { $api } from '../../api/client';
import { toApiError } from '../../api/errors';
import type { AdminCampaign, MarginReport } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { Ltr, MoneyText } from '../../components/format';
import { Loading } from '../../components/Results';
import { formatBasisPoints, formatMoney } from '../../lib/money';

export function MarginTab({ campaign }: { campaign: AdminCampaign }) {
  const { t } = useTranslation();
  const margin = $api.useQuery('get', '/admin/campaigns/{campaign_id}/margin', {
    params: { path: { campaign_id: campaign.id } },
  });
  if (margin.isPending) return <Loading />;
  if (margin.isError) {
    const code = toApiError(margin.error).code;
    if (code === 'tiers_missing' || code === 'landed_cost_missing') {
      return <Alert type="info" showIcon title={t(`campaigns.margin.${code}`)} />;
    }
    return <ApiErrorAlert error={margin.error} />;
  }
  return (
    <Card>
      <MarginReportView report={margin.data.data} />
    </Card>
  );
}

/** The margin check per tier, as shown before approval. */
export function MarginReportView({ report }: { report: MarginReport }) {
  const { t } = useTranslation();
  return (
    <Space orientation="vertical" size={16} style={{ width: '100%' }}>
      {report.passes ? (
        <Alert
          type="success"
          showIcon
          title={t('campaigns.margin.passes', { floor: formatBasisPoints(report.min_margin_bp) })}
        />
      ) : (
        <Alert
          type="error"
          showIcon
          title={t('campaigns.margin.fails', { floor: formatBasisPoints(report.min_margin_bp) })}
        />
      )}
      {report.warnings.map((w) => (
        <Alert key={w} type="warning" showIcon title={t(`campaigns.warnings.${w}`)} />
      ))}
      <Space wrap size={32}>
        <Statistic
          title={t('campaigns.margin.landedCost')}
          value={formatMoney(report.landed_cost_per_unit)}
        />
        <Statistic
          title={t('campaigns.margin.paymentFee')}
          value={formatMoney(report.payment_fee_estimate)}
        />
        <Statistic
          title={t('campaigns.margin.deliveryCost')}
          value={formatMoney(report.delivery_cost_estimate)}
        />
      </Space>
      <Table
        rowKey="min_qty"
        size="small"
        pagination={false}
        scroll={{ x: 'max-content' }}
        dataSource={report.tiers}
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
            render: (_, tier) => <MoneyText value={tier.unit_price} />,
          },
          {
            title: t('campaigns.margin.netPrice'),
            key: 'net',
            render: (_, tier) => <MoneyText value={tier.net_price} />,
          },
          {
            title: t('campaigns.margin.margin'),
            key: 'margin',
            render: (_, tier) => <MoneyText value={tier.margin} />,
          },
          {
            title: t('campaigns.margin.marginPct'),
            key: 'pct',
            render: (_, tier) => (
              <Typography.Text type={tier.passes ? undefined : 'danger'} strong>
                <Ltr>{formatBasisPoints(tier.margin_bp)}</Ltr>
              </Typography.Text>
            ),
          },
          {
            title: t('campaigns.margin.check'),
            key: 'passes',
            render: (_, tier) =>
              tier.passes ? (
                <CheckCircleTwoTone
                  twoToneColor="#52c41a"
                  aria-label={t('campaigns.margin.pass')}
                />
              ) : (
                <CloseCircleTwoTone
                  twoToneColor="#ff4d4f"
                  aria-label={t('campaigns.margin.fail')}
                />
              ),
          },
        ]}
      />
    </Space>
  );
}
