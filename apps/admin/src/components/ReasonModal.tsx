import { Form, Input, Modal } from 'antd';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { ApiErrorAlert } from './ApiErrorAlert';

/** Asks for a written reason (3 to 1000 characters by default, like ReasonInput) before an action. */
export function ReasonModal({
  open,
  title,
  description,
  okText,
  danger,
  label,
  maxLength = 1000,
  onCancel,
  onSubmit,
}: {
  open: boolean;
  title: ReactNode;
  description?: ReactNode;
  okText?: ReactNode;
  danger?: boolean;
  label?: ReactNode;
  maxLength?: number;
  onCancel: () => void;
  onSubmit: (reason: string) => Promise<unknown>;
}) {
  const { t } = useTranslation();
  const [form] = Form.useForm<{ reason: string }>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const { reason } = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      await onSubmit(reason.trim());
      form.resetFields();
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      title={title}
      okText={okText ?? t('common.confirm')}
      okButtonProps={{ danger, loading: busy }}
      onOk={() => void submit()}
      onCancel={() => {
        setError(null);
        onCancel();
      }}
      destroyOnHidden
    >
      {description && <div style={{ marginBottom: 12 }}>{description}</div>}
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form form={form} layout="vertical" preserve={false}>
        <Form.Item
          name="reason"
          label={label ?? t('common.reason')}
          rules={[
            { required: true, whitespace: true, message: t('validation.required') },
            { min: 3, message: t('validation.minLength', { n: 3 }) },
            { max: maxLength, message: t('validation.maxLength', { n: maxLength }) },
          ]}
        >
          <Input.TextArea rows={4} autoFocus />
        </Form.Item>
      </Form>
    </Modal>
  );
}
