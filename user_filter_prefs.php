<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Xataface user_filter_prefs module
 * Copyright (C) 2026 plcats and contributors
 * @author plcats
 * @version 1.1.1
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * this program; if not, see https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 *
 * Activation in conf.ini:
 * [_modules]
 * modules_user_filter_prefs=modules/user_filter_prefs/user_filter_prefs.php
 *
 * Optional configuration:
 * [user_filter_prefs]
 * enabled=1
 * backend=db                ; db|session
 * table_name=dataface__filter_preferences
 * auto_create_table=1
 * use_session_cache=1
 * disabled_tables=logs,archive_table
 *
 * ; technical keys never persisted
 * exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg
 *
 * ; allowed filter keys with '-' prefix (non-technical)
 * include_keys=-search
 *
 * ; Note: '=' is treated as no filter (All) and is never persisted.
 * ; '==' remains persistable and represents exact-empty values.
 */
class modules_user_filter_prefs {

    const APPLY_MARKER = '-ufp-apply';
    const LEGACY_APPLY_MARKER = '-xf-filter-apply';

    private $conf = array();
    private $baseURL = null;
    private $unfilterActionRegisteredTables = array();

    public function __construct() {
        $app = Dataface_Application::getInstance();
        $this->conf = $this->loadConfig($app);
        if (!$this->conf['enabled']) {
            return;
        }

        if ($this->conf['backend'] === 'db' && $this->conf['auto_create_table']) {
            $this->ensureStorageTable();
        }

        $app->registerEventListener('beforeHandleRequest', array($this, 'beforeHandleRequest'));
    }

    public function beforeHandleRequest() {
        $auth = Dataface_AuthenticationTool::getInstance();
        $user = $auth->getLoggedInUser();
        if (!$user) {
            return;
        }

        $app = Dataface_Application::getInstance();
        $query =& $app->getQuery();
        $action = isset($query['-action']) ? (string)$query['-action'] : 'list';
        $table = isset($query['-table']) ? trim((string)$query['-table']) : '';
        $supportedActions = array('list', 'mobile_filter_dialog', 'ajax_count_results', 'xf_infinite_scroll');
        if (!in_array($action, $supportedActions, true) || $table === '') {
            return;
        }

        if (in_array($table, $this->conf['disabled_tables'], true)) {
            return;
        }

        $this->registerCoreUnfilterAction($table);

        // Load module JS via URL to avoid JavascriptTool include-path constraints.
        // Add a version token to avoid stale browser cache during iterative fixes.
        $moduleJsUrl = $this->getBaseURL() . '/user_filter_prefs.js';
        $moduleJsPath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'user_filter_prefs.js';
        if (file_exists($moduleJsPath)) {
            $moduleJsUrl .= '?v=' . (string)@filemtime($moduleJsPath);
        }
        xf_script($moduleJsUrl, false);

        // Nei contesti related non salviamo e non applichiamo preferenze filtri.
        if ($this->isRelatedContext($query)) {
            return;
        }

        $source = is_array($_GET) ? $_GET : array();

        // Elimina placeholder di filtri vuoti (es. '=') prima di qualsiasi persistenza/ripristino.
        $this->pruneNonPersistableFilterValues($query, $table);

        if ($this->conf['use_session_cache']) {
            if (!isset($_SESSION['userFilters']) || !is_array($_SESSION['userFilters'])) {
                $_SESSION['userFilters'] = array();
            }
        }

        // Unfilter: solo in action list, cancella preferenze senza redirect.
        if ($action === 'list' && isset($query['-qf']) && $query['-qf'] === 'unfilter') {
            if ($this->conf['use_session_cache'] && isset($_SESSION['userFilters'][$table])) {
                unset($_SESSION['userFilters'][$table]);
            }
            $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
            return;
        }

        $explicitFilters = array();
        $isFilterApplyRequest = false;
        $needsCanonicalRedirect = false;
        if ($action === 'list') {
            $explicitFilters = $this->extractPersistableFilters($query, $table);
            $isFilterApplyRequest = $this->isFilterApplyRequest($source) || $this->isFilterApplyRequest($query);
            if ($isFilterApplyRequest) {
                $needsCanonicalRedirect = $this->sourceHasNoFilterPlaceholderValues($source, $table);
            }
            if ($isFilterApplyRequest) {
                $this->clearApplyMarkers($query);
            }
            // Apply esplicito con form vuoto: azzera preferenze salvate per questa tabella.
            if ($isFilterApplyRequest && empty($explicitFilters)) {
                if ($this->conf['use_session_cache'] && isset($_SESSION['userFilters'][$table])) {
                    unset($_SESSION['userFilters'][$table]);
                }
                $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
                $this->maybeRedirectCanonicalList($needsCanonicalRedirect, $query);
                return;
            }
        }

        $storedFilters = array();
        if ($this->conf['use_session_cache']) {
            if (!array_key_exists($table, $_SESSION['userFilters'])) {
                $_SESSION['userFilters'][$table] = $this->loadFiltersFromStorage($auth->getLoggedInUsername(), $table);
            }
            $storedFilters = $_SESSION['userFilters'][$table];
        } else {
            $storedFilters = $this->loadFiltersFromStorage($auth->getLoggedInUsername(), $table);
        }

        // Normalizza eventuali filtri legacy gia' presenti in sessione.
        $storedFilters = $this->sanitizePersistableFilterMap($storedFilters, $table);
        if ($this->conf['use_session_cache']) {
            $_SESSION['userFilters'][$table] = $storedFilters;
        }

        // Request list con input filtri dal form: aggiorna set salvato includendo clear espliciti.
        if ($action === 'list') {
            if ($this->hasPersistableFilterInputInSource($source)) {
                $updatedFilters = $storedFilters;
                $clearedKeys = $this->extractClearedPersistableFilterKeysFromSource($source, $table);
                foreach ($clearedKeys as $key) {
                    if (isset($updatedFilters[$key])) {
                        unset($updatedFilters[$key]);
                    }
                    if (isset($query[$key])) {
                        unset($query[$key]);
                    }
                    if (isset($_GET[$key])) {
                        unset($_GET[$key]);
                    }
                    if (isset($_REQUEST[$key])) {
                        unset($_REQUEST[$key]);
                    }
                }

                $sourceFilters = $this->extractPersistableFiltersFromSource($query, $source, $table);
                foreach ($sourceFilters as $key => $val) {
                    $updatedFilters[$key] = $val;
                }

                $updatedFilters = $this->sanitizePersistableFilterMap($updatedFilters, $table);
                if ($this->conf['use_session_cache']) {
                    $_SESSION['userFilters'][$table] = $updatedFilters;
                }

                if (empty($updatedFilters)) {
                    $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
                } else {
                    $this->saveFiltersToStorage($auth->getLoggedInUsername(), $table, $updatedFilters);
                }

                $this->maybeRedirectCanonicalList($needsCanonicalRedirect, $query);
                return;
            }
        }

        // Persistiamo nuovi filtri solo in action list.
        if ($action === 'list') {
            if (!empty($explicitFilters)) {
                // L'utente ha applicato filtri espliciti: persisti.
                if ($this->conf['use_session_cache']) {
                    $_SESSION['userFilters'][$table] = $explicitFilters;
                }
                $this->saveFiltersToStorage($auth->getLoggedInUsername(), $table, $explicitFilters);
                $this->maybeRedirectCanonicalList($needsCanonicalRedirect, $query);
                return;
            }
        }

        // Nessun filtro esplicito: applica preferenze salvate.
        foreach ($storedFilters as $key => $val) {
            if (!isset($query[$key]) || $query[$key] === '') {
                $query[$key] = $val;
                $_GET[$key] = $val;
                $_REQUEST[$key] = $val;
            }
        }
    }

    public function syncCurrentListQuery($query = null, $source = null) {
        if (!$this->conf['enabled']) {
            return;
        }

        $auth = Dataface_AuthenticationTool::getInstance();
        $user = $auth->getLoggedInUser();
        if (!$user) {
            return;
        }

        $app = Dataface_Application::getInstance();
        if (!is_array($query)) {
            $query =& $app->getQuery();
        }
        if (!is_array($source)) {
            $source = $_GET;
        }

        $action = isset($query['-action']) ? (string)$query['-action'] : 'list';
        $table = isset($query['-table']) ? trim((string)$query['-table']) : '';
        if ($action !== 'list' || $table === '') {
            return;
        }
        if (in_array($table, $this->conf['disabled_tables'], true)) {
            return;
        }
        if ($this->isRelatedContext($query)) {
            return;
        }

        $this->pruneNonPersistableFilterValues($query, $table);

        if ($this->conf['use_session_cache'] && (!isset($_SESSION['userFilters']) || !is_array($_SESSION['userFilters']))) {
            $_SESSION['userFilters'] = array();
        }

        if (isset($source['-qf']) && $source['-qf'] === 'unfilter') {
            if ($this->conf['use_session_cache'] && isset($_SESSION['userFilters'][$table])) {
                unset($_SESSION['userFilters'][$table]);
            }
            $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
            return;
        }

        $isFilterApplyRequest = $this->isFilterApplyRequest($source);
        if ($isFilterApplyRequest) {
            $this->clearApplyMarkers($query);
            if (is_array($source)) {
                $this->clearApplyMarkers($source);
            }
        }

        if ($this->hasPersistableFilterInputInSource($source)) {
            $storedFilters = array();
            if ($this->conf['use_session_cache']) {
                if (!array_key_exists($table, $_SESSION['userFilters'])) {
                    $_SESSION['userFilters'][$table] = $this->loadFiltersFromStorage($auth->getLoggedInUsername(), $table);
                }
                $storedFilters = $_SESSION['userFilters'][$table];
            } else {
                $storedFilters = $this->loadFiltersFromStorage($auth->getLoggedInUsername(), $table);
            }

            $storedFilters = $this->sanitizePersistableFilterMap($storedFilters, $table);
            $updatedFilters = $storedFilters;

            $clearedKeys = $this->extractClearedPersistableFilterKeysFromSource($source, $table);
            foreach ($clearedKeys as $key) {
                if (isset($updatedFilters[$key])) {
                    unset($updatedFilters[$key]);
                }
                if (isset($query[$key])) {
                    unset($query[$key]);
                }
                if (isset($_GET[$key])) {
                    unset($_GET[$key]);
                }
                if (isset($_REQUEST[$key])) {
                    unset($_REQUEST[$key]);
                }
            }

            $sourceFilters = $this->extractPersistableFiltersFromSource($query, $source, $table);
            foreach ($sourceFilters as $key => $val) {
                $updatedFilters[$key] = $val;
            }

            $updatedFilters = $this->sanitizePersistableFilterMap($updatedFilters, $table);
            if ($this->conf['use_session_cache']) {
                $_SESSION['userFilters'][$table] = $updatedFilters;
            }

            if (empty($updatedFilters)) {
                $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
            } else {
                $this->saveFiltersToStorage($auth->getLoggedInUsername(), $table, $updatedFilters);
            }
            return;
        }

        $explicitFilters = $this->extractPersistableFiltersFromSource($query, $source, $table);
        if ($isFilterApplyRequest && empty($explicitFilters)) {
            if ($this->conf['use_session_cache'] && isset($_SESSION['userFilters'][$table])) {
                unset($_SESSION['userFilters'][$table]);
            }
            $this->clearFiltersFromStorage($auth->getLoggedInUsername(), $table);
            return;
        }
        if (!empty($explicitFilters)) {
            if ($this->conf['use_session_cache']) {
                $_SESSION['userFilters'][$table] = $explicitFilters;
            }
            $this->saveFiltersToStorage($auth->getLoggedInUsername(), $table, $explicitFilters);
        }
    }

    private function getBaseURL() {
        if (!isset($this->baseURL)) {
            $this->baseURL = Dataface_ModuleTool::getInstance()->getModuleURL(__FILE__);
        }
        return $this->baseURL;
    }

    private function sourceHasNoFilterPlaceholderValues($source, $table = '') {
        if (!is_array($source)) {
            return false;
        }

        foreach ($source as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }

            $val = trim((string)$value);
            if ($val !== '' && !$this->isPersistableValue($val, $key, $table)) {
                return true;
            }
        }

        return false;
    }

    private function maybeRedirectCanonicalList($enabled, $query) {
        if (!$enabled || !is_array($query)) {
            return;
        }

        $app = Dataface_Application::getInstance();
        $url = $app->url($query, false);
        if (!$url) {
            return;
        }

        if (!headers_sent()) {
            header('Location: ' . $url, true, 302);
        }
    }

    private function isFilterApplyRequest($source) {
        if (!is_array($source)) {
            return false;
        }

        foreach ($this->getApplyMarkerNames() as $marker) {
            if (isset($source[$marker]) && (string)$source[$marker] === '1') {
                return true;
            }
        }

        return false;
    }

    private function getApplyMarkerNames() {
        return array(self::APPLY_MARKER, self::LEGACY_APPLY_MARKER);
    }

    private function clearApplyMarkers(&$target) {
        if (!is_array($target)) {
            return;
        }

        foreach ($this->getApplyMarkerNames() as $marker) {
            if (isset($target[$marker])) {
                unset($target[$marker]);
            }
            if (isset($_GET[$marker])) {
                unset($_GET[$marker]);
            }
            if (isset($_REQUEST[$marker])) {
                unset($_REQUEST[$marker]);
            }
        }
    }

    private function registerCoreUnfilterAction($table) {
        $table = trim((string)$table);
        if ($table === '') {
            return;
        }

        if (in_array($table, $this->unfilterActionRegisteredTables, true)) {
            return;
        }

        $at = Dataface_ActionTool::getInstance();
        $existing = $at->getActions(array('category' => 'list_settings', 'table' => $table));
        foreach ($existing as $action) {
            if (isset($action['name']) && (string)$action['name'] === 'ufp_unfilter') {
                $this->unfilterActionRegisteredTables[] = $table;
                return;
            }
            if (isset($action['url']) && strpos((string)$action['url'], '-qf=unfilter') !== false) {
                $this->unfilterActionRegisteredTables[] = $table;
                return;
            }
        }

        $app = Dataface_Application::getInstance();
        $url = $app->url(
            array(
                '-table' => $table,
                '-action' => 'list',
                '-qf' => 'unfilter'
            ),
            false
        );

        $at->addAction('ufp_unfilter', array(
            'name' => 'ufp_unfilter',
            'id' => 'ufp_unfilter',
            'category' => 'list_settings',
            'table' => $table,
            'materialIcon' => 'delete_sweep',
            'label' => df_translate('actions.ufp_unfilter.label', 'Clear Filters'),
            'description' => df_translate('actions.ufp_unfilter.description', 'Clear all active filters'),
            'permission' => 'list',
            'url' => $url,
            'order' => 98
        ));

        $this->unfilterActionRegisteredTables[] = $table;
    }

    private function loadConfig($app) {
        $raw = isset($app->_conf['user_filter_prefs']) && is_array($app->_conf['user_filter_prefs'])
            ? $app->_conf['user_filter_prefs']
            : array();

        $exclude = array(
            'skip',
            '-skip',
            '-limit',
            '-sort',
            '-action',
            '-table',
            '-relationship',
            '-qf',
            '-cursor',
            '--msg'
        );

        if (isset($raw['exclude_keys']) && trim((string)$raw['exclude_keys']) !== '') {
            $parts = $this->parseCsvList($raw['exclude_keys']);
            if (!empty($parts)) {
                $exclude = array_values(array_unique(array_merge($exclude, $parts)));
            }
        }

        $include = array('-search');
        if (isset($raw['include_keys']) && trim((string)$raw['include_keys']) !== '') {
            $parts = $this->parseCsvList($raw['include_keys']);
            if (!empty($parts)) {
                $include = array_values(array_unique(array_merge($include, $parts)));
            }
        }

        $disabledTables = array();
        if (isset($raw['disabled_tables']) && trim((string)$raw['disabled_tables']) !== '') {
            foreach ($this->parseCsvList($raw['disabled_tables']) as $tableName) {
                $safeName = $this->safeIdentifier($tableName);
                if ($safeName !== '') {
                    $disabledTables[] = $safeName;
                }
            }
            $disabledTables = array_values(array_unique($disabledTables));
        }

        $backend = isset($raw['backend']) ? strtolower(trim((string)$raw['backend'])) : 'db';
        if ($backend !== 'db' && $backend !== 'session') {
            $backend = 'db';
        }

        return array(
            'enabled' => !isset($raw['enabled']) || (string)$raw['enabled'] !== '0',
            'backend' => $backend,
            'table_name' => isset($raw['table_name']) && trim((string)$raw['table_name']) !== ''
                ? trim((string)$raw['table_name'])
                : 'dataface__filter_preferences',
            'auto_create_table' => !isset($raw['auto_create_table']) || (string)$raw['auto_create_table'] !== '0',
            'use_session_cache' => !isset($raw['use_session_cache']) || (string)$raw['use_session_cache'] !== '0',
            'disabled_tables' => $disabledTables,
            'exclude_keys' => $exclude,
            'include_keys' => $include
        );
    }

    private function parseCsvList($value) {
        $value = trim((string)$value);
        if ($value === '') {
            return array();
        }

        $parts = preg_split('/\s*,\s*/', $value);
        if (!is_array($parts)) {
            return array();
        }

        $out = array();
        foreach ($parts as $part) {
            $part = trim((string)$part);
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return array_values(array_unique($out));
    }

    private function pruneNonPersistableFilterValues(&$query, $table = '') {
        if (!is_array($query)) {
            return;
        }

        foreach ($query as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }

            $val = trim((string)$value);
            if ($this->isPersistableValue($val, $key, $table)) {
                continue;
            }

            unset($query[$key]);
            if (isset($_GET[$key])) {
                unset($_GET[$key]);
            }
            if (isset($_REQUEST[$key])) {
                unset($_REQUEST[$key]);
            }
        }
    }

    private function extractPersistableFilters($query, $table = '') {
        $out = array();
        foreach ($query as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $val = trim((string)$value);
            if (!$this->isPersistableValue($val, $key, $table)) {
                continue;
            }
            $out[$key] = $val;
        }
        return $out;
    }

    private function sanitizePersistableFilterMap($filters, $table = '') {
        if (!is_array($filters)) {
            return array();
        }

        $out = array();
        foreach ($filters as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $val = trim((string)$value);
            if (!$this->isPersistableValue($val, $key, $table)) {
                continue;
            }
            $out[$key] = $val;
        }

        return $out;
    }

    private function extractPersistableFiltersFromSource($query, $source, $table = '') {
        if (!is_array($source)) {
            return array();
        }

        $out = array();
        foreach ($source as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }

            // Use raw source value first so cleared selects (e.g. "All") remain empty
            // and do not get re-normalized to '=' by query transformations.
            $effectiveValue = $value;
            if (is_array($effectiveValue)) {
                continue;
            }

            $effectiveValue = trim((string)$effectiveValue);
            if (!$this->isPersistableValue($effectiveValue, $key, $table)) {
                continue;
            }
            $out[$key] = $effectiveValue;
        }

        return $out;
    }

    private function hasPersistableFilterInputInSource($source) {
        if (!is_array($source)) {
            return false;
        }

        foreach ($source as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            return true;
        }

        return false;
    }

    private function extractClearedPersistableFilterKeysFromSource($source, $table = '') {
        if (!is_array($source)) {
            return array();
        }

        $out = array();
        foreach ($source as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }

            $val = trim((string)$value);
            if ($val === '') {
                $out[] = $key;
                continue;
            }

            // In mobile filter dialog, '=' is the "All" placeholder.
            // Treat it as a clear signal so stale stored filters are removed.
            if (!$this->isPersistableValue($val, $key, $table)) {
                $out[] = $key;
            }
        }

        return array_values(array_unique($out));
    }

    private function isPersistableValue($value, $key = '', $table = '') {
        if ($value === '') {
            return false;
        }

        // '=' is the "All"/no-filter placeholder in list filters: never persist it.
        if ($value === '=') {
            return false;
        }

        return true;
    }

    private function isPersistableKey($key) {
        $key = (string)$key;
        if ($key === '') {
            return false;
        }
        if (strpos($key, '-related:') === 0) {
            return false;
        }
        if (in_array($key, $this->conf['exclude_keys'], true)) {
            return false;
        }

        if (in_array($key, $this->conf['include_keys'], true)) {
            return true;
        }

        if (substr($key, 0, 1) === '-') {
            return false;
        }

        return (bool)preg_match('/^[a-zA-Z0-9_\-]+$/', $key);
    }

    private function isRelatedContext($query) {
        if (!is_array($query)) {
            return false;
        }
        if (!empty($query['-relationship'])) {
            return true;
        }
        foreach ($query as $key => $value) {
            $k = (string)$key;
            if (strpos($k, '-related:') === 0) {
                return true;
            }
        }
        return false;
    }

    private function ensureStorageTable() {
        if ($this->conf['backend'] !== 'db') {
            return;
        }

        $link = $this->getDbConnection();
        if (!$link) {
            return;
        }
        $table = $this->safeIdentifier($this->conf['table_name']);
        if ($table === '') {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS `{$table}` (\n"
             . "  `id` int unsigned NOT NULL AUTO_INCREMENT,\n"
             . "  `username` varchar(128) NOT NULL,\n"
             . "  `table` varchar(128) NOT NULL,\n"
             . "  `key` varchar(128) NOT NULL,\n"
             . "  `value` text NOT NULL,\n"
             . "  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
             . "  PRIMARY KEY (`id`),\n"
             . "  UNIQUE KEY `uniq_user_table_key` (`username`,`table`,`key`),\n"
             . "  KEY `idx_user_table` (`username`,`table`)\n"
             . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        @mysqli_query($link, $sql);
    }

    private function loadFiltersFromStorage($username, $table) {
        if ($this->conf['backend'] !== 'db') {
            return array();
        }

        $link = $this->getDbConnection();
        if (!$link) {
            return array();
        }
        $storageTable = $this->safeIdentifier($this->conf['table_name']);
        if ($storageTable === '') {
            return array();
        }

        $stmt = mysqli_prepare(
            $link,
            "SELECT `key`, `value` FROM `{$storageTable}` WHERE `table`=? AND `username`=?"
        );
        if (!$stmt) {
            return array();
        }

        mysqli_stmt_bind_param($stmt, 'ss', $table, $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $filters = array();
        while ($row = mysqli_fetch_assoc($result)) {
            $key = isset($row['key']) ? (string)$row['key'] : '';
            $val = isset($row['value']) ? (string)$row['value'] : '';
            if ($this->isPersistableKey($key) && $val !== '') {
                $filters[$key] = $val;
            }
        }
        mysqli_stmt_close($stmt);

        return $filters;
    }

    private function saveFiltersToStorage($username, $table, $filters) {
        if ($this->conf['backend'] !== 'db') {
            return;
        }

        $link = $this->getDbConnection();
        if (!$link) {
            return;
        }
        $storageTable = $this->safeIdentifier($this->conf['table_name']);
        if ($storageTable === '') {
            return;
        }

        $stmtDel = mysqli_prepare(
            $link,
            "DELETE FROM `{$storageTable}` WHERE `table`=? AND `username`=?"
        );
        if (!$stmtDel) {
            return;
        }
        mysqli_stmt_bind_param($stmtDel, 'ss', $table, $username);
        mysqli_stmt_execute($stmtDel);
        mysqli_stmt_close($stmtDel);

        if (empty($filters)) {
            return;
        }

        $stmtIns = mysqli_prepare(
            $link,
            "INSERT INTO `{$storageTable}` (`username`,`table`,`key`,`value`) VALUES(?,?,?,?)"
        );
        if (!$stmtIns) {
            return;
        }

        foreach ($filters as $key => $val) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            $value = (string)$val;
            mysqli_stmt_bind_param($stmtIns, 'ssss', $username, $table, $key, $value);
            mysqli_stmt_execute($stmtIns);
        }
        mysqli_stmt_close($stmtIns);
    }

    private function clearFiltersFromStorage($username, $table) {
        if ($this->conf['backend'] !== 'db') {
            return;
        }

        $link = $this->getDbConnection();
        if (!$link) {
            return;
        }
        $storageTable = $this->safeIdentifier($this->conf['table_name']);
        if ($storageTable === '') {
            return;
        }

        $stmt = mysqli_prepare(
            $link,
            "DELETE FROM `{$storageTable}` WHERE `table`=? AND `username`=?"
        );
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $table, $username);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }

    private function safeIdentifier($name) {
        $name = trim((string)$name);
        if ($name === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            return '';
        }
        return $name;
    }

    private function getDbConnection() {
        // Prefer the native Xataface connection when available.
        if (function_exists('df_db')) {
            $native = @df_db();
            if ($native) {
                return $native;
            }
        }

        // Fallback to direct mysqli connection for standalone distribution.
        $app = Dataface_Application::getInstance();
        $dbConf = isset($app->_conf['_database']) && is_array($app->_conf['_database'])
            ? $app->_conf['_database']
            : array();

        $host = isset($dbConf['host']) ? (string)$dbConf['host'] : 'localhost';
        $name = isset($dbConf['name']) ? (string)$dbConf['name'] : '';
        $user = isset($dbConf['user']) ? (string)$dbConf['user'] : '';
        $pass = isset($dbConf['password']) ? (string)$dbConf['password'] : '';
        $port = isset($dbConf['port']) ? (int)$dbConf['port'] : 3306;

        if ($name === '' || $user === '') {
            return null;
        }

        $link = @mysqli_connect($host, $user, $pass, $name, $port);
        if (!$link) {
            return null;
        }
        @mysqli_set_charset($link, 'utf8mb4');
        return $link;
    }
}
