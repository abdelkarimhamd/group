import { Progress, Tooltip, Typography } from 'antd';
import { useTranslation } from 'react-i18next';
import type { AdminCampaign } from '../../api/types';
import { Ltr } from '../../components/format';

/** Committed units (solid) and reserved units (light) against the maximum, with the target as text. */
export function CampaignProgress({
  campaign,
  compact,
}: {
  campaign: AdminCampaign;
  compact?: boolean;
}) {
  const { t } = useTranslation();
  const {
    committed_qty: committed,
    reserved_qty: reserved,
    min_target_qty: target,
    max_qty: max,
  } = campaign;
  // Unit counts, not money. Floor so the bar never shows full before the maximum.
  const pct = (n: number) => (max > 0 ? Math.min(100, Math.floor((n / max) * 100)) : 0);
  const reachedTarget = committed >= target;
  return (
    <Tooltip title={t('campaigns.progressTooltip', { committed, reserved, target, max })}>
      <div style={{ minWidth: compact ? 160 : 240 }}>
        <Progress
          percent={pct(committed + reserved)}
          success={{ percent: pct(committed), strokeColor: reachedTarget ? '#389e0d' : '#1677ff' }}
          strokeColor="#bae0ff"
          showInfo={false}
          size="small"
        />
        <Typography.Text type="secondary" style={{ fontSize: 12 }}>
          <Ltr>
            {committed} / {target} → {max}
          </Ltr>
        </Typography.Text>
      </div>
    </Tooltip>
  );
}
