import { getI18n } from "@/i18n/server";
import { interpolate, ltr } from "@/i18n/format";
import { LEGAL_VERSION } from "@/lib/legal";
import { Alert, Container } from "./ui";

/** Privacy, terms and returns: numbered sections from the dictionary. */
export async function LegalPage({
  title,
  sections,
}: {
  title: string;
  sections: ReadonlyArray<{ heading: string; body: string }>;
}) {
  const { t } = await getI18n();
  return (
    <Container className="max-w-3xl py-10">
      <h1 className="text-3xl font-bold text-slate-900">{title}</h1>
      <p className="mt-2 text-sm text-slate-500">
        {interpolate(t.legal.version, { version: ltr(LEGAL_VERSION) })}
      </p>
      <Alert tone="warning" className="mt-6">
        {t.common.draft}
      </Alert>
      <ol className="mt-8 space-y-8">
        {sections.map((section, index) => (
          <li key={section.heading}>
            <h2 className="text-lg font-bold text-slate-900">
              {index + 1}. {section.heading}
            </h2>
            <p className="mt-2 leading-relaxed text-slate-700">{section.body}</p>
          </li>
        ))}
      </ol>
    </Container>
  );
}
