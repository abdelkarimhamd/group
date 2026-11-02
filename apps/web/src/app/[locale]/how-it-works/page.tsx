import type { Metadata } from "next";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { alternates } from "@/lib/site";
import { HowSteps } from "@/components/how-steps";
import { ButtonLink, Container, PageHeader } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/how-it-works">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  const t = getDictionary(locale);
  return { title: t.how.title, description: t.how.intro, alternates: alternates(locale, "/how-it-works") };
}

export default async function HowItWorksPage() {
  const { t, locale } = await getI18n();
  return (
    <Container className="py-10">
      <PageHeader title={t.how.title} intro={t.how.intro} />
      <HowSteps steps={t.how.steps} />

      <section aria-labelledby="faq-title" className="mt-14 max-w-3xl">
        <h2 id="faq-title" className="text-2xl font-bold text-slate-900">
          {t.how.faqTitle}
        </h2>
        <div className="mt-6 divide-y divide-slate-200 rounded-2xl bg-white ring-1 ring-slate-200">
          {t.how.faq.map((item) => (
            <details key={item.q} className="group px-5 py-4">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-semibold text-slate-900">
                {item.q}
                <span aria-hidden className="text-xl text-slate-400 transition group-open:rotate-45">
                  +
                </span>
              </summary>
              <p className="mt-3 leading-relaxed text-slate-700">{item.a}</p>
            </details>
          ))}
        </div>
      </section>

      <div className="mt-12">
        <ButtonLink href={`/${locale}/campaigns`}>{t.home.heroCta}</ButtonLink>
      </div>
    </Container>
  );
}
