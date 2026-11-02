import type { Metadata } from "next";
import { Suspense } from "react";
import { getI18n } from "@/i18n/server";
import { getDictionary } from "@/i18n/dictionaries";
import { isLocale } from "@/i18n/config";
import { WelcomeForm } from "@/components/profile-forms";
import { Card, Skeleton } from "@/components/ui";

export async function generateMetadata({ params }: PageProps<"/[locale]/account/welcome">): Promise<Metadata> {
  const { locale } = await params;
  if (!isLocale(locale)) return {};
  return { title: getDictionary(locale).pages.welcome, robots: { index: false } };
}

export default async function WelcomePage() {
  const { t } = await getI18n();
  return (
    <div className="flex justify-center py-4 sm:py-10">
      <Card className="w-full max-w-md">
        <h1 className="text-2xl font-bold">{t.auth.welcomeTitle}</h1>
        <p className="mt-1 mb-6 text-sm text-slate-600">{t.auth.welcomeBody}</p>
        <Suspense fallback={<Skeleton className="h-28 w-full" />}>
          <WelcomeForm />
        </Suspense>
      </Card>
    </div>
  );
}
