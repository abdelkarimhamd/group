import { Tag } from 'antd';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';

const COLORS = {
  green: 'green',
  blue: 'blue',
  gold: 'gold',
  red: 'red',
  grey: 'default',
  purple: 'purple',
  cyan: 'cyan',
  orange: 'orange',
} as const;
type Color = keyof typeof COLORS;

/** Colour per status, grouped by meaning: done, moving, waiting on someone, failed, inactive. */
const PALETTE = {
  campaign: {
    draft: 'grey',
    pending_approval: 'gold',
    scheduled: 'cyan',
    active: 'blue',
    closing: 'purple',
    purchasing: 'purple',
    fulfilling: 'purple',
    completed: 'green',
    failed: 'red',
    cancelled: 'grey',
  },
  closingStep: {
    settling_joins: 'blue',
    awaiting_supplier: 'gold',
    capturing: 'blue',
    needs_admin: 'orange',
    notifying: 'blue',
    done: 'green',
  },
  participation: {
    pending_payment: 'gold',
    authorized: 'blue',
    payment_failed: 'red',
    reservation_expired: 'grey',
    cancelled: 'grey',
    released: 'grey',
    captured: 'green',
    capture_failed: 'red',
  },
  payment: {
    pending_authorization: 'gold',
    authorized: 'blue',
    failed: 'red',
    voided: 'grey',
    expired: 'grey',
    captured: 'green',
    capture_failed: 'red',
    partially_refunded: 'orange',
    refunded: 'purple',
  },
  refund: {
    pending_approval: 'gold',
    approved: 'cyan',
    processing: 'blue',
    succeeded: 'green',
    failed: 'red',
    rejected: 'grey',
  },
  approval: { pending: 'gold', approved: 'green', rejected: 'red', cancelled: 'grey' },
  order: {
    confirmed: 'blue',
    processing: 'blue',
    ready_to_ship: 'cyan',
    shipped: 'purple',
    delivered: 'green',
    completed: 'green',
    cancelled: 'grey',
    return_requested: 'orange',
    returned: 'grey',
  },
  shipment: {
    pending: 'gold',
    picked_up: 'blue',
    in_transit: 'blue',
    out_for_delivery: 'cyan',
    delivered: 'green',
    failed_attempt: 'orange',
    returned: 'red',
    cancelled: 'grey',
  },
  booking: { requested: 'gold', booked: 'green', failed: 'red' },
  return: { requested: 'gold', approved: 'cyan', rejected: 'grey', completed: 'green' },
  purchaseOrder: {
    draft: 'grey',
    placed: 'blue',
    supplier_confirmed: 'cyan',
    paid: 'purple',
    shipped: 'purple',
    received: 'green',
    cancelled: 'grey',
  },
  product: { draft: 'grey', active: 'green', archived: 'default' },
  variant: { active: 'green', archived: 'default' },
  supplier: { prospect: 'gold', approved: 'green', blocked: 'red' },
  quotation: { draft: 'grey', accepted: 'green', rejected: 'red', expired: 'default' },
  reconciliationRun: { running: 'blue', matched: 'green', mismatches: 'orange', failed: 'red' },
  transaction: { pending: 'gold', succeeded: 'green', failed: 'red' },
  admin: { active: 'green', blocked: 'red' },
} as const;

export type StatusKind = keyof typeof PALETTE;

export function statusLabel(t: TFunction, kind: StatusKind, value: string): string {
  return t(`status.${kind}.${value}` as never, { defaultValue: value });
}

export function StatusTag({ kind, value }: { kind: StatusKind; value: string | null | undefined }) {
  const { t } = useTranslation();
  if (!value) return <>—</>;
  const palette = PALETTE[kind] as Record<string, Color | 'default'>;
  const color = palette[value] ?? 'grey';
  return (
    <Tag color={color === 'default' ? undefined : COLORS[color as Color]} variant="filled">
      {statusLabel(t, kind, value)}
    </Tag>
  );
}

/** Options for a status filter, in contract order. */
export function statusOptions(t: TFunction, kind: StatusKind) {
  return Object.keys(PALETTE[kind]).map((value) => ({ value, label: statusLabel(t, kind, value) }));
}
