import { useQueryClient } from '@tanstack/react-query';
import { App, Col, Form, Input, Modal, Rate, Row } from 'antd';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import type { Supplier, SupplierInput } from '../../api/types';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors, requiredRule } from '../../components/forms';

export function supplierInputOf(s: Supplier): SupplierInput {
  return {
    name: s.name,
    alibaba_store_url: s.alibaba_store_url,
    country: s.country,
    contact_name: s.contact_name ?? null,
    contact_channel: s.contact_channel ?? null,
    quality_rating: s.quality_rating ?? null,
    review_notes: s.review_notes ?? null,
  };
}

export function SupplierFormModal({
  supplier,
  onClose,
}: {
  supplier: Supplier | null;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<SupplierInput>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const v = await form.validateFields();
    const body: SupplierInput = {
      name: v.name.trim(),
      alibaba_store_url: v.alibaba_store_url.trim(),
      country: v.country.trim().toUpperCase(),
      contact_name: v.contact_name?.trim() || null,
      contact_channel: v.contact_channel?.trim() || null,
      quality_rating: v.quality_rating || null,
      review_notes: v.review_notes?.trim() || null,
    };
    setBusy(true);
    setError(null);
    try {
      if (supplier) {
        await api.PATCH('/admin/suppliers/{supplier_id}', {
          params: { path: { supplier_id: supplier.id } },
          body,
        });
        await queryClient.invalidateQueries({
          queryKey: ['get', '/admin/suppliers/{supplier_id}'],
        });
      } else {
        const { data } = await api.POST('/admin/suppliers', { body });
        navigate(`/suppliers/${data!.data.id}`);
      }
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/suppliers'] });
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
      title={supplier ? t('suppliers.editSupplier') : t('suppliers.newSupplier')}
      okText={t('common.save')}
      okButtonProps={{ loading: busy }}
      onOk={() => void submit()}
      onCancel={onClose}
      destroyOnHidden
    >
      <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
      <Form<SupplierInput>
        form={form}
        layout="vertical"
        requiredMark="optional"
        initialValues={supplier ? supplierInputOf(supplier) : { country: 'CN' }}
      >
        <Row gutter={12}>
          <Col xs={24} md={16}>
            <Form.Item name="name" label={t('fields.name')} rules={[requiredRule(t), { max: 200 }]}>
              <Input dir="ltr" />
            </Form.Item>
          </Col>
          <Col xs={24} md={8}>
            <Form.Item
              name="country"
              label={t('suppliers.country')}
              normalize={(v: string) => v.toUpperCase()}
              rules={[requiredRule(t), { pattern: /^[A-Z]{2}$/, message: t('validation.country') }]}
            >
              <Input dir="ltr" maxLength={2} />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item
              name="alibaba_store_url"
              label={t('suppliers.storeUrl')}
              rules={[requiredRule(t), { type: 'url', message: t('validation.url') }]}
            >
              <Input dir="ltr" placeholder="https://example.en.alibaba.com" />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="contact_name"
              label={t('suppliers.contactName')}
              rules={[{ max: 100 }]}
            >
              <Input />
            </Form.Item>
          </Col>
          <Col xs={24} md={12}>
            <Form.Item
              name="contact_channel"
              label={t('suppliers.contactChannel')}
              rules={[{ max: 200 }]}
            >
              <Input placeholder={t('suppliers.contactChannelHint')} />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item name="quality_rating" label={t('suppliers.qualityRating')}>
              <Rate />
            </Form.Item>
          </Col>
          <Col span={24}>
            <Form.Item
              name="review_notes"
              label={t('suppliers.reviewNotes')}
              rules={[{ max: 5000 }]}
            >
              <Input.TextArea rows={4} />
            </Form.Item>
          </Col>
        </Row>
      </Form>
    </Modal>
  );
}
