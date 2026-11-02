import { Descriptions, Grid, type DescriptionsProps } from 'antd';
import type { ReactNode } from 'react';

export type FieldItem = {
  label: ReactNode;
  value: ReactNode;
  span?: number | 'filled';
  hidden?: boolean;
};

type ColumnMap = Partial<Record<'xs' | 'sm' | 'md' | 'lg' | 'xl' | 'xxl', number>>;

const LARGEST_FIRST = ['xxl', 'xl', 'lg', 'md', 'sm', 'xs'] as const;

/** Label and value pairs for detail pages. */
export function Fields({
  items,
  column = { xs: 1, md: 2 },
  ...props
}: { items: FieldItem[]; column?: number | ColumnMap } & Omit<
  DescriptionsProps,
  'items' | 'column'
>) {
  const screens = Grid.useBreakpoint();
  const columns = typeof column === 'number' ? column : columnFor(screens, column);
  const visible = fillRows(
    items.filter((item) => !item.hidden),
    columns,
  );
  return (
    <Descriptions
      size="small"
      column={columns}
      items={visible.map((item, index) => ({
        key: index,
        label: item.label,
        children: item.value ?? '—',
        span: item.span,
      }))}
      {...props}
    />
  );
}

/** The column count for the current screen: the largest breakpoint that matches and has a value. */
function columnFor(screens: Partial<Record<string, boolean>>, map: ColumnMap): number {
  for (const breakpoint of LARGEST_FIRST) {
    const value = map[breakpoint];
    if (screens[breakpoint] && value !== undefined) return value;
  }
  // Before the first measurement, assume a desktop screen.
  return Math.max(1, ...Object.values(map));
}

/**
 * Stretches the item before a row break, and the last item, so every row adds
 * up to the column count (Ant Design warns otherwise and leaves gaps).
 */
function fillRows(items: FieldItem[], columns: number): FieldItem[] {
  const out = items.map((item) => ({ ...item }));
  let used = 0;
  out.forEach((item, index) => {
    const span = item.span === 'filled' ? columns - used : Math.min(item.span ?? 1, columns);
    if (used > 0 && used + span > columns) {
      out[index - 1]!.span = 'filled';
      used = 0;
    }
    used = item.span === 'filled' ? columns : used + span;
    if (used >= columns) used = 0;
  });
  const last = out[out.length - 1];
  if (last && used > 0) last.span = 'filled';
  return out;
}
