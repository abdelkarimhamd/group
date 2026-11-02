export function HowSteps({ steps }: { steps: Array<{ title: string; body: string }> }) {
  return (
    <ol className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      {steps.map((step, index) => (
        <li key={step.title} className="rounded-2xl bg-white p-5 ring-1 ring-slate-200">
          <span className="flex size-9 items-center justify-center rounded-full bg-brand-700 font-bold text-white">
            {index + 1}
          </span>
          <h3 className="mt-4 font-semibold text-slate-900">{step.title}</h3>
          <p className="mt-2 text-sm leading-relaxed text-slate-600">{step.body}</p>
        </li>
      ))}
    </ol>
  );
}
