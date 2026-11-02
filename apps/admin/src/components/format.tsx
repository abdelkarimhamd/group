import { Typography } from 'antd';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { Actor, Money } from '../api/types';
import { formatMoney } from '../lib/money';
import { formatDate, formatDateTime } from '../lib/time';

/** Codes, references and amounts read left to right even on Arabic screens. */
export function Ltr({ children }: { children: ReactNode }) {
  return (
    <bdi dir="ltr" style={{ unicodeBidi: 'isolate' }}>
      {children}
    </bdi>
  );
}

export function MoneyText({
  value,
  strong,
}: {
  value: Money | null | undefined;
  strong?: boolean;
}) {
  const text = <Ltr>{formatMoney(value)}</Ltr>;
  return strong ? <Typography.Text strong>{text}</Typography.Text> : text;
}

export function DateTimeText({ value }: { value: string | null | undefined }) {
  return <Ltr>{formatDateTime(value)}</Ltr>;
}

export function DateText({ value }: { value: string | null | undefined }) {
  return <Ltr>{formatDate(value)}</Ltr>;
}

export function ActorText({ actor }: { actor: Actor | null | undefined }) {
  const { t } = useTranslation();
  if (!actor) return <>—</>;
  if (actor.type === 'system') return <>{t('common.system')}</>;
  return <>{actor.name ?? t(actor.type === 'admin' ? 'common.anAdmin' : 'common.aCustomer')}</>;
}

export function CodeText({ children }: { children: ReactNode }) {
  return (
    <Typography.Text code copyable={typeof children === 'string' ? { text: children } : false}>
      <Ltr>{children}</Ltr>
    </Typography.Text>
  );
}
