<?php
/**
 * Record driver data formatting view helper
 *
 * PHP version 7
 *
 * Copyright (C) Villanova University 2016.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:architecture:record_data_formatter
 * Wiki
 */
namespace VuFind\View\Helper\Root;

use VuFind\RecordDriver\AbstractBase as RecordDriver;
use Zend\View\Helper\AbstractHelper;

/**
 * Record driver data formatting view helper
 *
 * @category VuFind
 * @package  View_Helpers
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development:architecture:record_data_formatter
 * Wiki
 */
class RecordDataFormatter extends AbstractHelper
{
    /**
     * Default settings.
     *
     * @var array
     */
    protected $defaults = [];

    /**
     * Sort callback for field specification.
     *
     * @param array $a First value to compare
     * @param array $b Second value to compare
     *
     * @return int
     */
    public function specSortCallback($a, $b)
    {
        return ($a['pos'] ?? 0) <=> ($b['pos'] ?? 0);
    }

    /**
     * Create formatted key/value data based on a record driver and field spec.
     *
     * @param RecordDriver $driver Record driver object.
     * @param array        $spec   Formatting specification
     *
     * @return array
     */
    public function getData(RecordDriver $driver, array $spec)
    {
        $result = [];

        // Sort the spec into order by position:
        uasort($spec, [$this, 'specSortCallback']);

        // Apply the spec:
        foreach ($spec as $field => $current) {
            // Extract the relevant data from the driver.
            $data = $this->extractData($driver, $current);
            $allowZero = $current['allowZero'] ?? true;
            if (!empty($data) || ($allowZero && ($data === 0 || $data === '0'))) {
                // Determine the rendering method to use with the second element
                // of the current spec.
                $renderMethod = empty($current['renderType'])
                    ? 'renderSimple' : 'render' . $current['renderType'];

                // Add the rendered data to the return value if it is non-empty:
                if (is_callable([$this, $renderMethod])) {
                    $text = $this->$renderMethod($driver, $data, $current);
                    if (!$text && (!$allowZero || ($text !== 0 && $text !== '0'))) {
                        continue;
                    }
                    // Allow dynamic label override:
                    $label = is_callable($current['labelFunction'] ?? null)
                        ? call_user_func($current['labelFunction'], $data, $driver)
                        : $field;
                    $result[$label] = [
                        'value' => $text,
                        'context' => $current['context'] ?? [],
                    ];
                }
            }
        }
        return $result;
    }

    /**
     * Get default configuration.
     *
     * @param string $key Key for configuration to look up.
     *
     * @return array
     */
    public function getDefaults($key)
    {
        // No value stored? Return empty array:
        if (!isset($this->defaults[$key])) {
            return [];
        }
        // Callback stored? Resolve to array on demand:
        if (is_callable($this->defaults[$key])) {
            $this->defaults[$key] = $this->defaults[$key]();
            if (!is_array($this->defaults[$key])) {
                throw new \Exception('Callback for ' . $key . ' must return array');
            }
        }
        // Send back array:
        return $this->defaults[$key];
    }

    /**
     * Set default configuration.
     *
     * @param string         $key    Key for configuration to set.
     * @param array|Callable $values Defaults to store (either an array, or a
     * Callable returning an array).
     *
     * @return void
     */
    public function setDefaults($key, $values)
    {
        if (!is_array($values) && !is_callable($values)) {
            throw new \Exception('$values must be array or Callable');
        }
        $this->defaults[$key] = $values;
    }

    /**
     * Extract data (usually from the record driver).
     *
     * @param RecordDriver $driver  Record driver
     * @param array        $options Incoming options
     *
     * @return mixed
     */
    protected function extractData(RecordDriver $driver, array $options)
    {
        // Static cache for persisting data.
        static $cache = [];

        // If $method is a bool, return it as-is; this allows us to force the
        // rendering (or non-rendering) of particular data independent of the
        // record driver.
        $method = $options['dataMethod'] ?? false;
        if ($method === true || $method === false) {
            return $method;
        }

        if ($useCache = ($options['useCache'] ?? false)) {
            $cacheKey = $driver->getUniqueID() . '|'
                . $driver->getSourceIdentifier() . '|' . $method;
            if (isset($cache[$cacheKey])) {
                return $cache[$cacheKey];
            }
        }

        // Default action: try to extract data from the record driver:
        $data = $driver->tryMethod($method);

        if ($useCache) {
            $cache[$cacheKey] = $data;
        }

        return $data;
    }

    /**
     * Render using the record view helper.
     *
     * @param RecordDriver $driver  Reoord driver object.
     * @param mixed        $data    Data to render
     * @param array        $options Rendering options.
     *
     * @return string
     */
    protected function renderRecordHelper(RecordDriver $driver, $data,
        array $options
    ) {
        $method = $options['helperMethod'] ?? null;
        $plugin = $this->getView()->plugin('record');
        if (empty($method) || !is_callable([$plugin, $method])) {
            throw new \Exception('Cannot call "' . $method . '" on helper.');
        }
        return $plugin($driver)->$method($data);
    }

    /**
     * Render a record driver template.
     *
     * @param RecordDriver $driver  Reoord driver object.
     * @param mixed        $data    Data to render
     * @param array        $options Rendering options.
     *
     * @return string
     */
    protected function renderRecordDriverTemplate(RecordDriver $driver, $data,
        array $options
    ) {
        if (!isset($options['template'])) {
            throw new \Exception('Template option missing.');
        }
        $helper = $this->getView()->plugin('record');
        $context = $options['context'] ?? [];
        $context['driver'] = $driver;
        $context['data'] = $data;
        return trim(
            $helper($driver)->renderTemplate($options['template'], $context)
        );
    }

    /**
     * Get a link associated with a value, or else return false if link does
     * not apply.
     *
     * @param string $value   Value associated with link.
     * @param array  $options Rendering options.
     *
     * @return string|bool
     */
    protected function getLink($value, $options)
    {
        if ($options['recordLink'] ?? false) {
            $helper = $this->getView()->plugin('record');
            return $helper->getLink($options['recordLink'], $value);
        }
        return false;
    }

    /**
     * Simple rendering method.
     *
     * @param RecordDriver $driver  Reoord driver object.
     * @param mixed        $data    Data to render
     * @param array        $options Rendering options.
     *
     * @return string
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function renderSimple(RecordDriver $driver, $data, array $options)
    {
        $view = $this->getView();
        $escaper = ($options['translate'] ?? false)
            ? $view->plugin('transEsc') : $view->plugin('escapeHtml');
        $transDomain = $options['translationTextDomain'] ?? '';
        $separator = $options['separator'] ?? '<br />';
        $retVal = '';
        $array = (array)$data;
        $remaining = count($array);
        foreach ($array as $line) {
            $remaining--;
            $text = $escaper($transDomain . $line);
            $retVal .= ($link = $this->getLink($line, $options))
                ? '<a href="' . $link . '">' . $text . '</a>' : $text;
            if ($remaining > 0) {
                $retVal .= $separator;
            }
        }
        return ($options['prefix'] ?? '')
            . $retVal
            . ($options['suffix'] ?? '');
    }
}
