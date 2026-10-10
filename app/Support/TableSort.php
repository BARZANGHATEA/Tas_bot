<?php

namespace App\Support;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Whitelisted, server-side sorting and page size for admin tables.
 * Only columns listed by the controller can ever reach ORDER BY.
 */
final class TableSort
{
    public const PER_PAGE = [25, 50, 100];

    /**
     * @param  array<string, string>  $columns  public sort key => SQL column/alias
     * @return array{sort: string, dir: string}
     */
    public static function apply(Builder $query, Request $request, array $columns, string $default, string $defaultDir = 'desc'): array
    {
        $sort = (string) $request->query('sort', $default);
        if (! array_key_exists($sort, $columns)) {
            $sort = $default;
        }

        $dir = strtolower((string) $request->query('dir', $defaultDir));
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : $defaultDir;

        $query->orderBy($columns[$sort], $dir);

        // Stable order for equal values (pagination must not shuffle rows).
        $model = $query->getModel();
        if ($columns[$sort] !== $model->getQualifiedKeyName() && $columns[$sort] !== $model->getKeyName()) {
            $query->orderBy($model->getQualifiedKeyName(), $dir);
        }

        return ['sort' => $sort, 'dir' => $dir];
    }

    public static function perPage(Request $request, int $default = 25): int
    {
        $perPage = (int) $request->query('per_page', $default);

        return in_array($perPage, self::PER_PAGE, true) ? $perPage : $default;
    }
}
