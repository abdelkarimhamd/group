"use client";

import { useI18n } from "@/i18n/client";
import { Button, Container } from "@/components/ui";

export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  const { t } = useI18n();
  return (
    <Container className="py-20 text-center">
      <h1 className="text-2xl font-bold">{t.common.errorTitle}</h1>
      <p className="mt-2 text-slate-600">{t.common.errorBody}</p>
      <Button className="mt-8" onClick={() => reset()}>
        {t.common.retry}
      </Button>
    </Container>
  );
}
