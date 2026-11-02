import { Button, Flex, Table, type TableProps } from 'antd';
import { useTranslation } from 'react-i18next';
import type { CursorList } from '../api/lists';
import { ApiErrorAlert } from './ApiErrorAlert';

/** A table over a cursor-paginated list, with a "Load more" button. */
export function CursorTable<T extends object>({
  list,
  rowKey = 'id',
  onRowClick,
  ...tableProps
}: {
  list: CursorList<T>;
  rowKey?: TableProps<T>['rowKey'];
  onRowClick?: (record: T) => void;
} & Omit<TableProps<T>, 'dataSource' | 'loading' | 'pagination' | 'rowKey'>) {
  const { t } = useTranslation();
  return (
    <>
      {list.isError && <ApiErrorAlert error={list.error} style={{ marginBottom: 12 }} />}
      <Table<T>
        size="middle"
        rowKey={rowKey}
        dataSource={list.items}
        loading={list.isPending && list.fetchStatus !== 'idle'}
        pagination={false}
        scroll={{ x: 'max-content' }}
        onRow={
          onRowClick
            ? (record) => ({
                onClick: () => onRowClick(record),
                style: { cursor: 'pointer' },
              })
            : undefined
        }
        {...tableProps}
      />
      {list.hasNextPage && (
        <Flex justify="center" style={{ marginTop: 12 }}>
          <Button onClick={() => void list.fetchNextPage()} loading={list.isFetchingNextPage}>
            {t('common.loadMore')}
          </Button>
        </Flex>
      )}
    </>
  );
}
