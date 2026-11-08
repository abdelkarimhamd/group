import { DeleteOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { Alert, App, Button, Form, Input, Modal, Select, Space, Typography } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { LedgerAccountCode, LedgerTransaction } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { CodeText } from '../../components/format';
import { applyFieldErrors, minorOf, MoneyInput, moneyRules } from '../../components/forms';
import { ReasonModal } from '../../components/ReasonModal';
import { formatMinor, parseMajorToMinor } from '../../lib/money';

/** The chart of accounts, in the API's order. */
export const LEDGER_ACCOUNTS: LedgerAccountCode[] = [
  'moyasar_clearing',
  'bank',
  'inventory_in_transit',
  'customer_prepayments',
  'vat_output_payable',
  'supplier_payable',
  'sales_revenue',
  'payment_fees',
  'vat_input_recoverable',
];

type LineValues = { account?: LedgerAccountCode; direction?: 'D' | 'C'; amount?: string };

function useRefreshLedger() {
  const queryClient = useQueryClient();
  return () =>
    Promise.all([
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/ledger/adjustments'] }),
      queryClient.invalidateQueries({ queryKey: ['get', '/admin/approval-requests'] }),
    ]);
}

function totals(lines: LineValues[] | undefined): { debits: number; credits: number } {
  let debits = 0;
  let credits = 0;
  for (const line of lines ?? []) {
    const minor = line?.amount ? parseMajorToMinor(line.amount) : null;
    if (minor === null) continue;
    if (line.direction === 'D') debits += minor;
    if (line.direction === 'C') credits += minor;
  }
  return { debits, credits };
}

/** Balanced lines to post once a second admin with the finance role approves. */
export function LedgerAdjustmentModal({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshLedger();
  const [form] = Form.useForm<{ description: string; lines: LineValues[] }>();
  const lines = Form.useWatch('lines', form);
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);
  const { debits, credits } = totals(lines);
  const balanced = debits === credits && debits > 0;

  const submit = async () => {
    const v = await form.validateFields();
    if (!balanced) return;
    setBusy(true);
    setError(null);
    try {
      await api.POST('/admin/ledger/adjustments', {
        body: {
          description: v.description.trim(),
          lines: v.lines.map((line) => ({
            account: line.account!,
            direction: line.direction!,
            amount_halalas: minorOf(line.amount),
          })),
        },
      });
      await refresh();
      message.success(t('ledger.requested'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  const accountOptions = LEDGER_ACCOUNTS.map((code) => ({
    value: code,
    label: t(`ledger.accounts.${code}`),
  }));

  return (
    <Modal
      open
      width={720}
      title={t('ledger.newAdjustment')}
      okText={t('refunds.submit')}
      okButtonProps={{ loading: busy, disabled: !balanced }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <Alert type="info" showIcon title={t('ledger.adjustmentHelp')} style={{ marginBottom: 12 }} />
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={{
          lines: [{ direction: 'D' }, { direction: 'C' }],
        }}
      >
        <Form.Item
          name="description"
          label={t('ledger.description')}
          rules={[
            { required: true, message: t('validation.required') },
            { min: 3, max: 255 },
          ]}
        >
          <Input />
        </Form.Item>
        <Form.List name="lines">
          {(fields, { add, remove }) => (
            <>
              {fields.map((field, index) => (
                <Space key={field.key} align="start" wrap style={{ display: 'flex' }}>
                  <Form.Item
                    name={[field.name, 'account']}
                    label={index === 0 ? t('ledger.account') : undefined}
                    rules={[{ required: true, message: t('validation.required') }]}
                  >
                    <Select
                      aria-label={`${t('ledger.account')} ${index + 1}`}
                      options={accountOptions}
                      style={{ width: 280 }}
                      showSearch
                    />
                  </Form.Item>
                  <Form.Item
                    name={[field.name, 'direction']}
                    label={index === 0 ? t('ledger.direction') : undefined}
                    rules={[{ required: true, message: t('validation.required') }]}
                  >
                    <Select
                      aria-label={`${t('ledger.direction')} ${index + 1}`}
                      style={{ width: 110 }}
                      options={(['D', 'C'] as const).map((d) => ({
                        value: d,
                        label: t(`ledger.directions.${d}`),
                      }))}
                    />
                  </Form.Item>
                  <Form.Item
                    name={[field.name, 'amount']}
                    label={index === 0 ? t('payments.amount') : undefined}
                    rules={moneyRules(t, { min: 1 })}
                  >
                    <MoneyInput aria-label={`${t('payments.amount')} ${index + 1}`} />
                  </Form.Item>
                  {fields.length > 2 && (
                    <Button
                      type="text"
                      icon={<DeleteOutlined />}
                      aria-label={t('common.delete')}
                      onClick={() => remove(field.name)}
                      style={{ marginTop: index === 0 ? 30 : 0 }}
                    />
                  )}
                </Space>
              ))}
              {fields.length < 20 && (
                <Button icon={<PlusOutlined />} onClick={() => add({ direction: 'D' })}>
                  {t('ledger.addLine')}
                </Button>
              )}
            </>
          )}
        </Form.List>
        <Typography.Paragraph style={{ marginTop: 12 }}>
          <Typography.Text type={balanced || debits + credits === 0 ? 'secondary' : 'danger'}>
            {t('ledger.totals', {
              debits: formatMinor(debits, 'SAR'),
              credits: formatMinor(credits, 'SAR'),
            })}
            {!balanced && debits + credits > 0 ? ` · ${t('ledger.unbalanced')}` : ''}
          </Typography.Text>
        </Typography.Paragraph>
      </Form>
    </Modal>
  );
}

/** Asks for a posting to be reversed in full, with why. */
export function ReverseTransactionModal({
  transaction,
  onClose,
}: {
  transaction: LedgerTransaction;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const refresh = useRefreshLedger();
  return (
    <ReasonModal
      open
      danger
      title={t('ledger.reverseTitle')}
      okText={t('refunds.submit')}
      label={t('ledger.description')}
      maxLength={255}
      description={
        <>
          <Typography.Paragraph>{t('ledger.reverseHelp')}</Typography.Paragraph>
          <CodeText>{transaction.reference}</CodeText> · {transaction.description}
        </>
      }
      onCancel={onClose}
      onSubmit={async (description) => {
        await api.POST('/admin/ledger/adjustments', {
          body: { description, reverses_transaction_id: transaction.id },
        });
        await refresh();
        message.success(t('ledger.requested'));
        onClose();
      }}
    />
  );
}
