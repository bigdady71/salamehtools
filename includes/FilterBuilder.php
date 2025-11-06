<?php
/**
 * FilterBuilder - Universal query filter builder
 *
 * Features:
 * - Dynamic WHERE clause generation
 * - Multiple filter types (text, date range, select, multi-select)
 * - URL parameter handling
 * - SQL injection protection
 * - Filter persistence across pages
 *
 * Usage:
 *   $filter = new FilterBuilder();
 *   $filter->addTextFilter('customer_name', 'c.name');
 *   $filter->addDateRangeFilter('date', 'o.created_at');
 *   $filter->addSelectFilter('status', 'o.status', ['pending', 'approved', 'completed']);
 *
 *   $whereClause = $filter->buildWhereClause();
 *   $params = $filter->getParameters();
 */

class FilterBuilder
{
    private array $filters = [];
    private array $parameters = [];
    private array $activeFilters = [];

    /**
     * Add text search filter (LIKE %search%)
     *
     * @param string $paramName URL parameter name
     * @param string $columnName Database column name (with table alias)
     * @param string $operator Comparison operator (LIKE, =, >, <, etc.)
     */
    public function addTextFilter(string $paramName, string $columnName, string $operator = 'LIKE'): self
    {
        $value = trim($_GET[$paramName] ?? '');

        if ($value !== '') {
            if ($operator === 'LIKE') {
                $this->filters[] = "$columnName LIKE :$paramName";
                $this->parameters[":$paramName"] = "%$value%";
            } else {
                $this->filters[] = "$columnName $operator :$paramName";
                $this->parameters[":$paramName"] = $value;
            }

            $this->activeFilters[$paramName] = $value;
        }

        return $this;
    }

    /**
     * Add exact match filter
     *
     * @param string $paramName URL parameter name
     * @param string $columnName Database column name
     */
    public function addExactFilter(string $paramName, string $columnName): self
    {
        return $this->addTextFilter($paramName, $columnName, '=');
    }

    /**
     * Add date range filter (from-to)
     *
     * @param string $paramName Base parameter name (appends _from and _to)
     * @param string $columnName Database column name
     */
    public function addDateRangeFilter(string $paramName, string $columnName): self
    {
        $fromParam = $paramName . '_from';
        $toParam = $paramName . '_to';

        $from = $_GET[$fromParam] ?? '';
        $to = $_GET[$toParam] ?? '';

        if ($from !== '') {
            $this->filters[] = "$columnName >= :{$fromParam}";
            $this->parameters[":{$fromParam}"] = $from . ' 00:00:00';
            $this->activeFilters[$fromParam] = $from;
        }

        if ($to !== '') {
            $this->filters[] = "$columnName <= :{$toParam}";
            $this->parameters[":{$toParam}"] = $to . ' 23:59:59';
            $this->activeFilters[$toParam] = $to;
        }

        return $this;
    }

    /**
     * Add select/dropdown filter
     *
     * @param string $paramName URL parameter name
     * @param string $columnName Database column name
     * @param array|null $allowedValues Optional whitelist of allowed values
     */
    public function addSelectFilter(string $paramName, string $columnName, ?array $allowedValues = null): self
    {
        $value = $_GET[$paramName] ?? '';

        if ($value !== '' && $value !== 'all') {
            // Validate against whitelist if provided
            if ($allowedValues !== null && !in_array($value, $allowedValues, true)) {
                return $this; // Invalid value, skip filter
            }

            $this->filters[] = "$columnName = :$paramName";
            $this->parameters[":$paramName"] = $value;
            $this->activeFilters[$paramName] = $value;
        }

        return $this;
    }

    /**
     * Add multi-select filter (IN clause)
     *
     * @param string $paramName URL parameter name (expects array)
     * @param string $columnName Database column name
     * @param array|null $allowedValues Optional whitelist of allowed values
     */
    public function addMultiSelectFilter(string $paramName, string $columnName, ?array $allowedValues = null): self
    {
        $values = $_GET[$paramName] ?? [];

        if (!is_array($values)) {
            $values = [$values];
        }

        $values = array_filter($values, fn($v) => $v !== '' && $v !== 'all');

        if (!empty($values)) {
            // Validate against whitelist if provided
            if ($allowedValues !== null) {
                $values = array_intersect($values, $allowedValues);
            }

            if (!empty($values)) {
                $placeholders = [];
                foreach ($values as $idx => $value) {
                    $placeholder = ":{$paramName}_{$idx}";
                    $placeholders[] = $placeholder;
                    $this->parameters[$placeholder] = $value;
                }

                $this->filters[] = "$columnName IN (" . implode(', ', $placeholders) . ")";
                $this->activeFilters[$paramName] = $values;
            }
        }

        return $this;
    }

    /**
     * Add numeric range filter (min-max)
     *
     * @param string $paramName Base parameter name (appends _min and _max)
     * @param string $columnName Database column name
     */
    public function addNumericRangeFilter(string $paramName, string $columnName): self
    {
        $minParam = $paramName . '_min';
        $maxParam = $paramName . '_max';

        $min = $_GET[$minParam] ?? '';
        $max = $_GET[$maxParam] ?? '';

        if ($min !== '' && is_numeric($min)) {
            $this->filters[] = "$columnName >= :{$minParam}";
            $this->parameters[":{$minParam}"] = (float)$min;
            $this->activeFilters[$minParam] = $min;
        }

        if ($max !== '' && is_numeric($max)) {
            $this->filters[] = "$columnName <= :{$maxParam}";
            $this->parameters[":{$maxParam}"] = (float)$max;
            $this->activeFilters[$maxParam] = $max;
        }

        return $this;
    }

    /**
     * Add boolean filter
     *
     * @param string $paramName URL parameter name
     * @param string $columnName Database column name
     */
    public function addBooleanFilter(string $paramName, string $columnName): self
    {
        $value = $_GET[$paramName] ?? '';

        if ($value === '1' || $value === 'true' || $value === 'yes') {
            $this->filters[] = "$columnName = 1";
            $this->activeFilters[$paramName] = '1';
        } elseif ($value === '0' || $value === 'false' || $value === 'no') {
            $this->filters[] = "$columnName = 0";
            $this->activeFilters[$paramName] = '0';
        }

        return $this;
    }

    /**
     * Add custom SQL filter
     *
     * @param string $condition SQL condition (must handle parameterization manually)
     * @param array $params Parameters for the condition
     */
    public function addCustomFilter(string $condition, array $params = []): self
    {
        if (!empty($condition)) {
            $this->filters[] = "($condition)";
            $this->parameters = array_merge($this->parameters, $params);
        }

        return $this;
    }

    /**
     * Build WHERE clause from active filters
     *
     * @param bool $includeWhere Include "WHERE" keyword
     * @return string SQL WHERE clause
     */
    public function buildWhereClause(bool $includeWhere = true): string
    {
        if (empty($this->filters)) {
            return '';
        }

        $clause = implode(' AND ', $this->filters);
        return $includeWhere ? "WHERE $clause" : $clause;
    }

    /**
     * Get filter parameters for prepared statement
     *
     * @return array Parameters array
     */
    public function getParameters(): array
    {
        return $this->parameters;
    }

    /**
     * Get active filters (for display/debugging)
     *
     * @return array Active filters
     */
    public function getActiveFilters(): array
    {
        return $this->activeFilters;
    }

    /**
     * Check if any filters are active
     *
     * @return bool True if filters are active
     */
    public function hasActiveFilters(): bool
    {
        return !empty($this->activeFilters);
    }

    /**
     * Get filter count
     *
     * @return int Number of active filters
     */
    public function getFilterCount(): int
    {
        return count($this->activeFilters);
    }

    /**
     * Clear all filters
     */
    public function clear(): void
    {
        $this->filters = [];
        $this->parameters = [];
        $this->activeFilters = [];
    }

    /**
     * Generate URL with current filters
     *
     * @param string $baseUrl Base URL
     * @param array $additionalParams Additional parameters to merge
     * @return string Complete URL with filters
     */
    public function buildUrl(string $baseUrl, array $additionalParams = []): string
    {
        $params = array_merge($this->activeFilters, $additionalParams);

        if (empty($params)) {
            return $baseUrl;
        }

        $query = http_build_query($params);
        $separator = strpos($baseUrl, '?') !== false ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    /**
     * Generate hidden form fields for current filters
     *
     * @param array $exclude Parameters to exclude
     * @return string HTML hidden input fields
     */
    public function generateHiddenFields(array $exclude = []): string
    {
        $html = '';

        foreach ($this->activeFilters as $name => $value) {
            if (in_array($name, $exclude)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $v) {
                    $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '[]" value="' . htmlspecialchars($v) . '">' . "\n";
                }
            } else {
                $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '">' . "\n";
            }
        }

        return $html;
    }

    /**
     * Render filter summary badges
     *
     * @param array $labels Human-readable labels for parameters
     * @return string HTML filter badges
     */
    public function renderFilterBadges(array $labels = []): string
    {
        if (empty($this->activeFilters)) {
            return '';
        }

        $html = '<div class="filter-badges" style="margin: 10px 0;">';

        foreach ($this->activeFilters as $name => $value) {
            $label = $labels[$name] ?? ucfirst(str_replace('_', ' ', $name));

            if (is_array($value)) {
                $displayValue = implode(', ', $value);
            } else {
                $displayValue = $value;
            }

            // Create clear filter URL
            $clearUrl = $this->buildUrl($_SERVER['PHP_SELF'], array_diff_key($this->activeFilters, [$name => '']));

            $html .= '<span class="filter-badge" style="display: inline-block; background: #00ff88; color: #000; padding: 5px 10px; margin: 2px; border-radius: 3px;">';
            $html .= htmlspecialchars($label) . ': <strong>' . htmlspecialchars($displayValue) . '</strong>';
            $html .= ' <a href="' . htmlspecialchars($clearUrl) . '" style="color: #000; text-decoration: none; font-weight: bold; margin-left: 5px;">×</a>';
            $html .= '</span>';
        }

        // Clear all filters link
        $clearAllUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $html .= ' <a href="' . htmlspecialchars($clearAllUrl) . '" style="color: #ff0000; text-decoration: none; margin-left: 10px;">Clear All Filters</a>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render filter form UI
     *
     * @param array $config Filter configuration
     * @return string HTML filter form
     */
    public function renderFilterForm(array $config): string
    {
        $html = '<form method="GET" action="" class="filter-form" style="background: #1a1a1a; padding: 15px; margin: 10px 0; border-radius: 5px;">';
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">';

        foreach ($config as $field) {
            $type = $field['type'] ?? 'text';
            $name = $field['name'];
            $label = $field['label'] ?? ucfirst(str_replace('_', ' ', $name));
            $value = $_GET[$name] ?? '';

            $html .= '<div class="filter-field">';
            $html .= '<label style="display: block; color: #00ff88; margin-bottom: 5px;">' . htmlspecialchars($label) . '</label>';

            switch ($type) {
                case 'text':
                    $html .= '<input type="text" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '" style="width: 100%; padding: 8px; background: #2a2a2a; color: #fff; border: 1px solid #444; border-radius: 3px;">';
                    break;

                case 'date':
                    $html .= '<input type="date" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '" style="width: 100%; padding: 8px; background: #2a2a2a; color: #fff; border: 1px solid #444; border-radius: 3px;">';
                    break;

                case 'select':
                    $options = $field['options'] ?? [];
                    $html .= '<select name="' . htmlspecialchars($name) . '" style="width: 100%; padding: 8px; background: #2a2a2a; color: #fff; border: 1px solid #444; border-radius: 3px;">';
                    $html .= '<option value="">All</option>';
                    foreach ($options as $optValue => $optLabel) {
                        $selected = $value == $optValue ? 'selected' : '';
                        $html .= '<option value="' . htmlspecialchars($optValue) . '" ' . $selected . '>' . htmlspecialchars($optLabel) . '</option>';
                    }
                    $html .= '</select>';
                    break;

                case 'number':
                    $html .= '<input type="number" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars($value) . '" step="any" style="width: 100%; padding: 8px; background: #2a2a2a; color: #fff; border: 1px solid #444; border-radius: 3px;">';
                    break;
            }

            $html .= '</div>';
        }

        // Filter and Export buttons
        $html .= '<div class="filter-actions" style="display: flex; gap: 10px; align-items: flex-end;">';
        $html .= '<button type="submit" style="padding: 8px 20px; background: #00ff88; color: #000; border: none; border-radius: 3px; cursor: pointer; font-weight: bold;">Apply Filters</button>';
        $html .= '<a href="' . htmlspecialchars(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)) . '" style="padding: 8px 20px; background: #ff4444; color: #fff; text-decoration: none; border-radius: 3px; display: inline-block;">Clear</a>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }
}
