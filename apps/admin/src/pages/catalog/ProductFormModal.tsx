import { MinusCircleOutlined, PlusOutlined } from '@ant-design/icons';
import { useQueryClient } from '@tanstack/react-query';
import { App, Button, Col, Divider, Form, Input, Modal, Row, Select, Space } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { useCategories } from '../../api/lookups';
import type { AdminProduct, AdminProductInput, AdminVariantInput } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors, requiredRule } from '../../components/forms';
import { statusOptions } from '../../components/StatusTag';
import { useBilingual } from '../../i18n/LocaleProvider';

type FormValues = AdminProductInput & { variants?: AdminVariantInput[] };

/** Creates a product (with its first variants) or edits one. */
export function ProductFormModal({
  product,
  onClose,
}: {
  product: AdminProduct | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const pick = useBilingual();
  const { message } = App.useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const categories = useCategories();
  const [form] = Form.useForm<FormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const values = await form.validateFields();
    const base: AdminProductInput = {
      category_id: values.category_id,
      name_ar: values.name_ar.trim(),
      name_en: values.name_en.trim(),
      description_ar: values.description_ar?.trim() || null,
      description_en: values.description_en?.trim() || null,
      alibaba_url: values.alibaba_url.trim(),
      hs_code: values.hs_code?.trim() || null,
      ...(product ? { status: values.status } : {}),
    };
    setBusy(true);
    setError(null);
    try {
      if (product) {
        await api.PATCH('/admin/products/{product_id}', {
          params: { path: { product_id: product.id } },
          body: base,
        });
        await queryClient.invalidateQueries({ queryKey: ['get', '/admin/products/{product_id}'] });
      } else {
        const { data } = await api.POST('/admin/products', {
          body: { ...base, variants: (values.variants ?? []).map(cleanVariant) },
        });
        navigate(`/products/${data!.data.id}`);
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/products'] });
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
      width={760}
      title={product ? t('catalog.editProduct') : t('catalog.newProduct')}
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
        initialValues={product ?? { variants: [{ sku: '', name_ar: '', name_en: '' }] }}
      >
        <Form.Item
          name="alibaba_url"
          label={t('catalog.alibabaUrl')}
          rules={[requiredRule(t), { type: 'url', message: t('validation.url') }]}
        >
          <Input dir="ltr" placeholder="https://www.alibaba.com/product-detail/..." />
        </Form.Item>
        <Row gutter={12}>
          <Col xs={24} md={12}>
            <Form.Item
              name="name_ar"
              label={t('fields.nameAr')}
              rules={[requiredRule(t), { max: 200 }]}
            >
              <Input dir="rtl" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="name_en"
              label={t('fields.nameEn')}
              rules={[requiredRule(t), { max: 200 }]}
            >
              <Input dir="ltr" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item name="category_id" label={t('catalog.category')} rules={[requiredRule(t)]}>
              <Select
                loading={categories.isPending}
                showSearch
                optionFilterProp="label"
                options={(categories.data?.data ?? []).map((c) => ({
                  value: c.id,
                  label: pick(c, 'name'),
                }))}
              />
            </Form.Item>
          </Col>
          <Col xs={24} md={product ? 6 : 12}>
            <Form.Item
              name="hs_code"
              label={t('catalog.hsCode')}
              rules={[{ pattern: /^[0-9]{6,12}$/, message: t('validation.hsCode') }]}
            >
              <Input dir="ltr" inputMode="numeric" />
            </Form.Item>
          </Col>
          {product && (
            <Col xs={24} md={6}>
              <Form.Item name="status" label={t('common.status')}>
                <Select options={statusOptions(t, 'product')} />
              </Form.Item>
            </Col>
          )}
          <Col xs={24} md={12}>
            <Form.Item
              name="description_ar"
              label={t('fields.descriptionAr')}
              rules={[{ max: 10000 }]}
            >
              <Input.TextArea rows={4} dir="rtl" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="description_en"
              label={t('fields.descriptionEn')}
              rules={[{ max: 10000 }]}
            >
              <Input.TextArea rows={4} dir="ltr" />
            </Form.Item>
          </Col>
        </Row>

        {!product && (
          <>
            <Divider titlePlacement="start">{t('catalog.variants')}</Divider>
            <Form.List
              name="variants"
              rules={[
                {
                  validator: async (_, value: unknown[] | undefined) => {
                    if (!value || value.length === 0) throw new Error(t('catalog.variantRequired'));
                  },
                },
              ]}
            >
              {(fields, { add, remove }, { errors }) => (
                <>
                  {fields.map((field) => (
                    <Space key={field.key} align="baseline" wrap style={{ display: 'flex' }}>
                      <Form.Item name={[field.name, 'sku']} rules={[requiredRule(t), { max: 64 }]}>
                        <Input dir="ltr" placeholder={t('catalog.sku')} />
                      </Form.Item>
                      <Form.Item
                        name={[field.name, 'name_ar']}
                        rules={[requiredRule(t), { max: 100 }]}
                      >
                        <Input dir="rtl" placeholder={t('fields.nameAr')} />
                      </Form.Item>
                      <Form.Item
                        name={[field.name, 'name_en']}
                        rules={[requiredRule(t), { max: 100 }]}
                      >
                        <Input dir="ltr" placeholder={t('fields.nameEn')} />
                      </Form.Item>
                      {fields.length > 1 && (
                        <MinusCircleOutlined
                          aria-label={t('common.remove')}
                          onClick={() => remove(field.name)}
                        />
                      )}
                    </Space>
                  ))}
                  <Button type="dashed" icon={<PlusOutlined />} onClick={() => add()}>
                    {t('catalog.addVariant')}
                  </Button>
                  <Form.ErrorList errors={errors} />
                </>
              )}
            </Form.List>
          </>
        )}
      </Form>
    </Modal>
  );
}

function cleanVariant(v: AdminVariantInput): AdminVariantInput {
  return { sku: v.sku.trim(), name_ar: v.name_ar.trim(), name_en: v.name_en.trim() };
}
