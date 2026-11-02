import type { CampaignStatus, OrderStatus, ParticipationStatus } from '@/api/types';
import { useFormat } from '@/i18n/useFormat';
import { campaignPhase } from '@/lib/campaign';

import { Pill, type Tone } from './Pill';

const campaignTone = {
  upcoming: 'info',
  open: 'primary',
  closing: 'warning',
  success: 'success',
  failed: 'neutral',
  cancelled: 'neutral',
} as const satisfies Record<ReturnType<typeof campaignPhase>, Tone>;

export function CampaignStatusPill({ status }: { status: CampaignStatus }) {
  const { t } = useFormat();
  return <Pill tone={campaignTone[campaignPhase(status)]} label={t(`campaignStatus.${status}`)} />;
}

const participationTone: Record<ParticipationStatus, Tone> = {
  pending_payment: 'warning',
  authorized: 'primary',
  payment_failed: 'danger',
  reservation_expired: 'neutral',
  cancelled: 'neutral',
  released: 'neutral',
  captured: 'success',
  capture_failed: 'danger',
};

export function ParticipationStatusPill({ status }: { status: ParticipationStatus }) {
  const { t } = useFormat();
  return <Pill tone={participationTone[status]} label={t(`joins.status_${status}`)} />;
}

const orderTone: Record<OrderStatus, Tone> = {
  confirmed: 'info',
  processing: 'info',
  ready_to_ship: 'info',
  shipped: 'primary',
  delivered: 'success',
  completed: 'success',
  cancelled: 'neutral',
  return_requested: 'warning',
  returned: 'neutral',
};

export function OrderStatusPill({ status }: { status: OrderStatus }) {
  const { t } = useFormat();
  return <Pill tone={orderTone[status]} label={t(`orders.status_${status}`)} />;
}
