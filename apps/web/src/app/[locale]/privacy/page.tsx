import type { Metadata } from "next";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { alternates } from "@/lib/site";
import { LegalPage } from "@/components/legal-page";

export async function generateMetadata({ params }: PageProps<"/[locale]/privacy">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).legal.privacyTitle, alternates: alternates(locale, "/privacy") };
}

export default async function PrivacyPage() {
  const { t } = await getI18n();
  return <LegalPage title={t.legal.privacyTitle} sections={t.legal.privacy} />;
}
