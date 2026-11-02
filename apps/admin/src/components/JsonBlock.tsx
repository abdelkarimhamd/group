import { Typography } from 'antd';

export function JsonBlock({ value }: { value: unknown }) {
  if (value === null || value === undefined)
    return <Typography.Text type="secondary">—</Typography.Text>;
  return (
    <pre
      dir="ltr"
      style={{
        margin: 0,
        padding: 8,
        fontSize: 12,
        background: 'rgba(0,0,0,0.03)',
        borderRadius: 4,
        maxHeight: 280,
        overflow: 'auto',
        whiteSpace: 'pre-wrap',
        wordBreak: 'break-word',
      }}
    >
      {JSON.stringify(value, null, 2)}
    </pre>
  );
}
