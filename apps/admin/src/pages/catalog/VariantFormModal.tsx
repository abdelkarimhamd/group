import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Col, Form, Input, InputNumber, Modal, Row, Select, Space } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { api } from '../../api/client';
import type { AdminVariant, AdminVariantInput } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors, requiredRule } from '../../components/forms';
import { statusOptions } from '../../components/StatusTag';

type FormValues = Omit<AdminVariantInput, 'option_values'> & {
  options?: { key: string; value: string }[];
};

export function VariantFormModal({
  productId,
  variant,
  onClose,
}: {
  productId: string;
  variant: AdminVariant | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<FormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const initial: FormValues | undefined = variant
    ? {
        ...variant,
        options: Object.entries(variant.option_values ?? {}).map(([key, value]) => ({
          key,
          value,
        })),
      }
    : { sku: '', name_ar: '', name_en: '', status: 'active', options: [] };

  const submit = async () => {
    const values = await form.validateFields();
    const body: AdminVariantInput = {
      sku: values.sku.trim(),
      name_ar: values.name_ar.trim(),
      name_en: values.name_en.trim(),
      option_values: Object.fromEntries(
        (values.options ?? [])
          .filter((o) => o?.key?.trim())
          .map((o) => [o.key.trim(), (o.value ?? '').trim()]),
      ),
      weight_grams: values.weight_grams ?? null,
      length_mm: values.length_mm ?? null,
      width_mm: values.width_mm ?? null,
      height_mm: values.height_mm ?? null,
      status: values.status,
    };
    setBusy(true);
    setError(null);
    try {
      if (variant) {
        await api.PATCH('/admin/variants/{variant_id}', {
          params: { path: { variant_id: variant.id } },
          body,
        });
      } else {
        await api.POST('/admin/products/{product_id}/variants', {
          params: { path: { product_id: productId } },
          body,
        });
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/products/{product_id}'] });
      message.success(t('common.saved'));
      onClose();
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open
      width={640}
      title={variant ? t('catalog.editVariant') : t('catalog.addVariant')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form<FormValues>
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={initial}
      >
        <Row gutter={12}>
          <Col xs={24} md={8}>
            <Form.Item name="sku" label={t('catalog.sku')} rules={[requiredRule(t), { max: 64 }]}>
              <Input dir="ltr" />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item
              name="name_ar"
              label={t('fields.nameAr')}
              rules={[requiredRule(t), { max: 100 }]}
            >
              <Input dir="rtl" />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item
              name="name_en"
              label={t('fields.nameEn')}
              rules={[requiredRule(t), { max: 100 }]}
            >
              <Input dir="ltr" />
            </Form.Item>
          </Col>
          {(['weight_grams', 'length_mm', 'width_mm', 'height_mm'] as const).map((name) => (
            <Col xs={12} md={6} key={name}>
              <Form.Item name={name} label={t(`catalog.dimensions.${name}`)}>
                <InputNumber min={0} precision={0} style={{ width: '100%' }} />
              </Form.Item>
            </Col>
          ))}
          <Col xs={24} md={8}>
            <Form.Item name="status" label={t('common.status')}>
              <Select options={statusOptions(t, 'variant')} />
            </Form.Item>
          </Col>
        </Row>
        <Form.List name="options">
          {(fields, { add, remove }) => (
            <>
              <div style={{ marginBottom: 8 }}>{t('catalog.optionValues')}</div>
              {fields.map((field) => (
                <Space key={field.key} align="baseline" style={{ display: 'flex' }}>
                  <Form.Item name={[field.name, 'key']} rules={[requiredRule(t)]}>
                    <Input dir="ltr" placeholder={t('catalog.optionName')} />
                  </Form.Item>
                  <Form.Item name={[field.name, 'value']} rules={[requiredRule(t)]}>
                    <Input placeholder={t('catalog.optionValue')} />
                  </Form.Item>
                  <MinusCircleOutlined
                    aria-label={t('common.remove')}
                    onClick={() => remove(field.name)}
                  />
                </Space>
              ))}
              <Button type="dashed" size="small" icon={<PlusOutlined />} onClick={() => add()}>
                {t('catalog.addOption')}
              </Button>
            </>
          )}
        </Form.List>
      </Form>
    </Modal>
  );
}
