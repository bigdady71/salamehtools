<?php
/**
 * Paginator - Database query pagination with efficient counting
 *
 * Features:
 * - Efficient pagination without loading all rows
 * - Automatic total count caching
 * - Query builder integration
 * - URL parameter handling
 * - Bootstrap/Tailwind compatible HTML output
 *
 * Usage:
 *   $paginator = new Paginator($pdo);
 *   $result = $paginator->paginate(
 *       "SELECT * FROM orders WHERE status = :status",
 *       ['status' => 'approved'],
 *       $page,
 *       50
 *   );
 */

class Paginator
{
    private PDO $pdo;
    private ?CacheManager $cache;
    private ?Logger $logger;

    /**
     * @param PDO $pdo Database connection
     * @param CacheManager|null $cache Cache manager (optional)
     * @param Logger|null $logger Logger instance (optional)
     */
    public function __construct(PDO $pdo, ?CacheManager $cache = null, ?Logger $logger = null)
    {
        $this->pdo = $pdo;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Paginate query results
     *
     * @param string $sql Base SQL query (without LIMIT)
     * @param array $params Query parameters
     * @param int $page Current page (1-indexed)
     * @param int $perPage Items per page
     * @param string|null $countCacheKey Cache key for count (optional)
     * @return array Pagination result
     */
    public function paginate(
        string $sql,
        array $params = [],
        int $page = 1,
        int $perPage = 50,
        ?string $countCacheKey = null
    ): array {
        $page = max(1, $page); // Ensure page >= 1
        $offset = ($page - 1) * $perPage;

        // Get total count
        $total = $this->getTotalCount($sql, $params, $countCacheKey);

        // Calculate pagination metadata
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $lastPage); // Ensure page <= lastPage
        $offset = ($page - 1) * $perPage;

        // Fetch paginated data
        $data = $this->fetchPage($sql, $params, $offset, $perPage);

        // Build result
        return [
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
            'has_more_pages' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    /**
     * Get total count for query
     *
     * @param string $sql Base SQL query
     * @param array $params Query parameters
     * @param string|null $cacheKey Cache key (optional)
     * @return int Total count
     */
    private function getTotalCount(string $sql, array $params, ?string $cacheKey): int
    {
        // Try cache first
        if ($cacheKey && $this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                if ($this->logger) {
                    $this->logger->debug('Pagination count cache hit', ['key' => $cacheKey]);
                }
                return (int)$cached;
            }
        }

        // Build count query
        $countSql = $this->buildCountQuery($sql);

        try {
            $stmt = $this->pdo->prepare($countSql);
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            // Cache the count (5 minutes default)
            if ($cacheKey && $this->cache) {
                $this->cache->put($cacheKey, $total, 300);
            }

            return $total;
        } catch (PDOException $e) {
            if ($this->logger) {
                $this->logger->error('Pagination count failed', [
                    'sql' => $countSql,
                    'error' => $e->getMessage(),
                ]);
            }
            return 0;
        }
    }

    /**
     * Fetch page of results
     *
     * @param string $sql Base SQL query
     * @param array $params Query parameters
     * @param int $offset Offset
     * @param int $limit Limit
     * @return array Results
     */
    private function fetchPage(string $sql, array $params, int $offset, int $limit): array
    {
        $sql .= " LIMIT :limit OFFSET :offset";

        try {
            $stmt = $this->pdo->prepare($sql);

            // Bind original parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }

            // Bind pagination parameters
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            if ($this->logger) {
                $this->logger->error('Pagination fetch failed', [
                    'sql' => $sql,
                    'offset' => $offset,
                    'limit' => $limit,
                    'error' => $e->getMessage(),
                ]);
            }
            return [];
        }
    }

    /**
     * Build COUNT query from SELECT query
     *
     * @param string $sql Original SELECT query
     * @return string COUNT query
     */
    private function buildCountQuery(string $sql): string
    {
        // Remove ORDER BY clause (not needed for count)
        $sql = preg_replace('/\s+ORDER\s+BY\s+.+$/i', '', $sql);

        // Wrap in COUNT query
        return "SELECT COUNT(*) FROM ($sql) AS count_query";
    }

    /**
     * Generate pagination links HTML
     *
     * @param array $pagination Pagination data from paginate()
     * @param string $baseUrl Base URL for links
     * @param array $queryParams Additional query parameters
     * @return string HTML pagination links
     */
    public function renderLinks(array $pagination, string $baseUrl, array $queryParams = []): string
    {
        $currentPage = $pagination['current_page'];
        $lastPage = $pagination['lastPage'];
        $prevPage = $pagination['prev_page'];
        $nextPage = $pagination['next_page'];

        // Calculate page range to show
        $range = $this->calculatePageRange($currentPage, $lastPage);

        $html = '<nav aria-label="Pagination">';
        $html .= '<ul class="pagination">';

        // Previous button
        if ($prevPage) {
            $url = $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $prevPage]));
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">« Previous</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">« Previous</span></li>';
        }

        // First page
        if ($range['start'] > 1) {
            $url = $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => 1]));
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">1</a></li>';

            if ($range['start'] > 2) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }
        }

        // Page numbers
        for ($i = $range['start']; $i <= $range['end']; $i++) {
            $url = $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $i]));
            $active = $i === $currentPage ? ' active' : '';
            $html .= '<li class="page-item' . $active . '"><a class="page-link" href="' . htmlspecialchars($url) . '">' . $i . '</a></li>';
        }

        // Last page
        if ($range['end'] < $lastPage) {
            if ($range['end'] < $lastPage - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
            }

            $url = $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $lastPage]));
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">' . $lastPage . '</a></li>';
        }

        // Next button
        if ($nextPage) {
            $url = $this->buildUrl($baseUrl, array_merge($queryParams, ['page' => $nextPage]));
            $html .= '<li class="page-item"><a class="page-link" href="' . htmlspecialchars($url) . '">Next »</a></li>';
        } else {
            $html .= '<li class="page-item disabled"><span class="page-link">Next »</span></li>';
        }

        $html .= '</ul>';
        $html .= '</nav>';

        return $html;
    }

    /**
     * Generate pagination info text
     *
     * @param array $pagination Pagination data from paginate()
     * @return string Info text (e.g., "Showing 1-50 of 234 results")
     */
    public function renderInfo(array $pagination): string
    {
        $from = $pagination['from'];
        $to = $pagination['to'];
        $total = $pagination['total'];

        if ($total === 0) {
            return 'No results found';
        }

        return "Showing $from-$to of $total results";
    }

    /**
     * Calculate page range to display
     *
     * @param int $currentPage Current page
     * @param int $lastPage Last page
     * @param int $onEachSide Pages to show on each side of current
     * @return array ['start' => int, 'end' => int]
     */
    private function calculatePageRange(int $currentPage, int $lastPage, int $onEachSide = 2): array
    {
        $start = max(1, $currentPage - $onEachSide);
        $end = min($lastPage, $currentPage + $onEachSide);

        // Adjust if range is too small
        if ($end - $start < ($onEachSide * 2)) {
            if ($start === 1) {
                $end = min($lastPage, $start + ($onEachSide * 2));
            } else {
                $start = max(1, $end - ($onEachSide * 2));
            }
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Build URL with query parameters
     *
     * @param string $baseUrl Base URL
     * @param array $params Query parameters
     * @return string Complete URL
     */
    private function buildUrl(string $baseUrl, array $params): string
    {
        if (empty($params)) {
            return $baseUrl;
        }

        $query = http_build_query($params);
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * Get current page from request
     *
     * @return int Current page (1-indexed)
     */
    public static function getCurrentPage(): int
    {
        return max(1, (int)($_GET['page'] ?? 1));
    }

    /**
     * Paginate array data (for non-database pagination)
     *
     * @param array $items All items
     * @param int $page Current page
     * @param int $perPage Items per page
     * @return array Pagination result
     */
    public static function paginateArray(array $items, int $page = 1, int $perPage = 50): array
    {
        $page = max(1, $page);
        $total = count($items);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        $data = array_slice($items, $offset, $perPage);

        return [
            'data' => $data,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
            'has_more_pages' => $page < $lastPage,
            'prev_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ];
    }

    /**
     * Clear count cache for specific key
     *
     * @param string $cacheKey Cache key to clear
     */
    public function clearCountCache(string $cacheKey): void
    {
        if ($this->cache) {
            $this->cache->forget($cacheKey);
        }
    }
}
