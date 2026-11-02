import { App, Button, Card, Form, Space } from 'antd';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router-dom';
import { api } from '../../api/client';
import { ApiErrorAlert } from '../../components/ApiErrorAlert';
import { applyFieldErrors } from '../../components/forms';
import { PageHeader } from '../../components/PageHeader';
import { CampaignForm, campaignInputOf, type CampaignFormValues } from './CampaignForm';

export function CampaignCreatePage() {
  const { t } = useTranslation();
  const { message } = App.useApp();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [form] = Form.useForm<CampaignFormValues>();
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    const values = await form.validateFields();
    setBusy(true);
    setError(null);
    try {
      const { data } = await api.POST('/admin/campaigns', { body: campaignInputOf(values) });
      await queryClient.invalidateQueries({ queryKey: ['get', '/admin/campaigns'] });
      message.success(t('campaigns.createdDraft'));
      navigate(`/campaigns/${data!.data.id}?tab=tiers`);
    } catch (e) {
      if (!applyFieldErrors(form, e)) setError(e);
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <PageHeader
        backTo="/campaigns"
        title={t('campaigns.newCampaign')}
        subtitle={t('campaigns.newSubtitle')}
      />
      <Card>
        <ApiErrorAlert error={error} style={{ marginBottom: 12 }} />
        <Form<CampaignFormValues> form={form} layout="vertical" requiredMark="optional">
          <CampaignForm form={form} />
          <Space>
            <Button type="primary" loading={busy} onClick={() => void submit()}>
              {t('campaigns.createDraft')}
            </Button>
            <Button onClick={() => navigate('/campaigns')}>{t('common.cancel')}</Button>
          </Space>
        </Form>
      </Card>
    </>
  );
}
