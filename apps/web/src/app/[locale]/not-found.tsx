import { getI18n } from "@/i18n/server";
import { ButtonLink, Container } from "@/components/ui";

export default async function NotFound() {
  const { t, locale } = await getI18n();
  return (
    <Container className="py-20 text-center">
      <p className="text-5xl font-bold text-brand-700">404</p>
      <h1 className="mt-4 text-2xl font-bold">{t.common.notFoundTitle}</h1>
      <p className="mt-2 text-slate-600">{t.common.notFoundBody}</p>
      <ButtonLink href={`/${locale}`} className="mt-8">
        {t.common.goHome}
      </ButtonLink>
    </Container>
  );
}
