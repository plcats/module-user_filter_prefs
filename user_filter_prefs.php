<?php
/**
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * Xataface user_filter_prefs module
 * Copyright (C) 2026 user_filter_prefs contributors
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
 *
 * ; technical keys never persisted
 * exclude_keys=skip,-skip,-limit,-sort,-action,-table,-relationship,-qf,-cursor,--msg
 *
 * ; allowed filter keys with '-' prefix (non-technical)
 * include_keys=-search
 */
class modules_user_filter_prefs {

    private $conf = array();

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

        // Nei contesti related non salviamo e non applichiamo preferenze filtri.
        if ($this->isRelatedContext($query)) {
            return;
        }

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
            unset($query['-qf']);
            if (isset($_GET['-qf'])) {
                unset($_GET['-qf']);
            }
            return;
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

        // Persistiamo nuovi filtri solo in action list.
        if ($action === 'list') {
            $explicitFilters = $this->extractPersistableFilters($query);
            if (!empty($explicitFilters)) {
                // L'utente ha applicato filtri espliciti: persisti.
                if ($this->conf['use_session_cache']) {
                    $_SESSION['userFilters'][$table] = $explicitFilters;
                }
                $this->saveFiltersToStorage($auth->getLoggedInUsername(), $table, $explicitFilters);
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
            $parts = preg_split('/\s*,\s*/', trim((string)$raw['exclude_keys']));
            if (is_array($parts)) {
                $exclude = array_values(array_unique(array_merge($exclude, $parts)));
            }
        }

        $include = array('-search');
        if (isset($raw['include_keys']) && trim((string)$raw['include_keys']) !== '') {
            $parts = preg_split('/\s*,\s*/', trim((string)$raw['include_keys']));
            if (is_array($parts)) {
                $include = array_values(array_unique(array_merge($include, $parts)));
            }
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
            'exclude_keys' => $exclude,
            'include_keys' => $include
        );
    }

    private function extractPersistableFilters($query) {
        $out = array();
        foreach ($query as $key => $value) {
            if (!$this->isPersistableKey($key)) {
                continue;
            }
            if (is_array($value)) {
                continue;
            }
            $val = trim((string)$value);
            if ($val === '' || $val === '=') {
                continue;
            }
            $out[$key] = $val;
        }
        return $out;
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
            if ($this->isPersistableKey($key) && $val !== '' && $val !== '=') {
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
        // Generic DB connection for standalone distribution.
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
