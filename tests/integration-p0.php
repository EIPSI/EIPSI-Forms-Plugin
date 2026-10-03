<?php
/** Opt-in database regressions using disposable tables, never live participant tables. */
if (PHP_SAPI !== 'cli') { exit; }
class P0LiveDatabase {
    public $prefix;
    public $insert_id = 0;
    public $last_error = '';
    public $connection;
    public function __construct($connection, $prefix) { $this->connection = $connection; $this->prefix = $prefix; }
    public function quote($value) { return $value === null ? 'NULL' : "'" . $this->connection->real_escape_string((string) $value) . "'"; }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $sql = preg_replace_callback('/%[ds]/', function ($m) use ($arg) {
                return $m[0] === '%d' ? (string) (int) $arg : $this->quote($arg);
            }, $sql, 1);
        }
        return $sql;
    }
    public function query($sql) {
        $result = $this->connection->query($sql);
        return $result === false ? false : $this->connection->affected_rows;
    }
    public function insert($table, $data) {
        $keys = implode('`, `', array_keys($data));
        $values = implode(', ', array_map(array($this, 'quote'), array_values($data)));
        $result = $this->query("INSERT INTO `$table` (`$keys`) VALUES ($values)");
        $this->insert_id = $this->connection->insert_id;
        return $result;
    }
    public function get_var($sql) {
        $result = $this->connection->query($sql);
        $row = $result->fetch_row();
        return $row ? $row[0] : null;
    }
    public function get_row($sql) { return $this->connection->query($sql)->fetch_object(); }
    public function update($table, $data, $where, ...$args) {
        $sets = array(); $conditions = array();
        foreach ($data as $key => $value) { $sets[] = "`$key` = " . $this->quote($value); }
        foreach ($where as $key => $value) { $conditions[] = "`$key` = " . $this->quote($value); }
        return $this->query("UPDATE `$table` SET " . implode(', ', $sets) . ' WHERE ' . implode(' AND ', $conditions));
    }
}
function p0_live_fixture($callback) {
    $host = getenv('WORDPRESS_DB_HOST');
    $database = getenv('WORDPRESS_DB_NAME');
    if (!$host || !$database) { throw new RuntimeException('Integration requires Docker WordPress database environment'); }
    $parts = explode(':', $host, 2);
    $connection = new mysqli($parts[0], getenv('WORDPRESS_DB_USER'), getenv('WORDPRESS_DB_PASSWORD'), $database, isset($parts[1]) ? (int) $parts[1] : 3306);
    $prefix = 'eipsi_p0_test_' . bin2hex(random_bytes(6)) . '_';
    $GLOBALS['wpdb'] = $db = new P0LiveDatabase($connection, $prefix);
    try {
        $connection->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES'");
        $connection->query("CREATE TABLE {$prefix}survey_participants (id BIGINT PRIMARY KEY, survey_id BIGINT, is_active INT, consent_decision VARCHAR(20), consent_decided_at DATETIME, consent_ip_address VARCHAR(45), consent_user_agent VARCHAR(500), consent_context VARCHAR(50))");
        $connection->query("INSERT INTO {$prefix}survey_participants (id, survey_id, is_active) VALUES (7,3,1),(8,3,1)");
        $connection->query("CREATE TABLE {$prefix}eipsi_partial_responses (id BIGINT PRIMARY KEY, form_id VARCHAR(64), participant_id VARCHAR(255), session_id VARCHAR(255), page_index INT, responses_json LONGTEXT, completed INT, created_at DATETIME, updated_at DATETIME)");
        $db->insert($prefix . 'eipsi_partial_responses', array('id' => 1, 'form_id' => 'f', 'participant_id' => '7', 'session_id' => 's', 'page_index' => 2, 'responses_json' => '{"phq_score":4}', 'completed' => 0, 'created_at' => current_time('mysql'), 'updated_at' => current_time('mysql')));
        $callback($db);
    } finally {
        foreach (array('eipsi_emergency_submissions', 'eipsi_partial_responses', 'survey_participants') as $table) {
            $connection->query("DROP TABLE IF EXISTS `{$prefix}{$table}`");
        }
        $connection->close();
    }
}
$tests['DB real: emergency local persiste JSON y POST crudo'] = function () use ($submission) {
    p0_live_fixture(function ($db) use ($submission) {
        $_POST = array('answer' => 4);
        $result = eipsi_safety_emergency_save($submission, 'normal insert failed');
        p0_assert($result['success'] && $result['storage'] === 'emergency_table_wp', 'Local INSERT failed');
        $row = $db->get_row("SELECT * FROM {$db->prefix}eipsi_emergency_submissions WHERE id = " . (int) $result['emergency_id']);
        p0_assert($row->form_responses === $submission['form_responses'] && $row->raw_post_data === '{"answer":4}', 'Persisted payload differs');
    });
};
$tests['DB real: emergency INSERT rechazado nunca confirma éxito'] = function () use ($submission) {
    p0_live_fixture(function ($db) use ($submission) {
        $submission['form_id'] = str_repeat('x', 60); // Exceeds emergency VARCHAR(50), strict SQL rejection.
        $result = eipsi_safety_emergency_save($submission, 'normal insert failed');
        p0_assert(!$result['success'] && $result['storage'] === null, 'False success after actual DB rejection');
        p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_emergency_submissions") === 0, 'Unexpected emergency row');
    });
};
$tests['DB real: emergency externo informa destino correcto'] = function () use ($submission) {
    p0_live_fixture(function ($db) use ($submission) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
        $helper = new EIPSI_External_Database();
        $helper->save_credentials(getenv('WORDPRESS_DB_HOST'), getenv('WORDPRESS_DB_USER'), getenv('WORDPRESS_DB_PASSWORD'), getenv('WORDPRESS_DB_NAME'));
        $result = eipsi_safety_emergency_save($submission, 'normal insert failed');
        p0_assert($result['success'] && $result['storage'] === 'emergency_table_external', 'External destination incorrect');
        p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_emergency_submissions") === 1, 'External row absent');
    });
};
$tests['DB real: excepción externa permite fallback local con destino real'] = function () use ($submission) {
    p0_live_fixture(function ($db) use ($submission) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
        $helper = new EIPSI_External_Database();
        $helper->save_credentials('127.0.0.1:1', 'test', '', 'test');
        $result = eipsi_safety_emergency_save($submission, 'normal insert failed');
        p0_assert($result['success'] && $result['storage'] === 'emergency_table_wp', 'Fallback destination incorrect');
        p0_assert(strpos($GLOBALS['p0_mail'][0]['body'], 'WordPress DB') !== false, 'Alert reports configured rather than real destination');
        p0_assert((int) $db->get_var("SELECT COUNT(*) FROM {$db->prefix}eipsi_emergency_submissions") === 1, 'Fallback row absent');
    });
};
$tests['DB real: fallo externo y local nunca confirma éxito'] = function () use ($submission) {
    p0_live_fixture(function ($db) use ($submission) {
        require_once EIPSI_FORMS_PLUGIN_DIR . 'admin/database.php';
        $helper = new EIPSI_External_Database();
        $helper->save_credentials('127.0.0.1:1', 'test', '', 'test');
        $submission['form_id'] = str_repeat('x', 60);
        $result = eipsi_safety_emergency_save($submission, 'normal insert failed');
        p0_assert(!$result['success'] && $result['storage'] === null && $result['emergency_id'] === null, 'False success after both failures');
        p0_assert(strpos($result['error'], 'external') !== false && strpos($result['error'], 'wordpress') !== false, 'Missing destination diagnostics');
    });
};
$tests['DB real: partial debug deniega público y permite administrador'] = function () use ($debug_request) {
    p0_live_fixture(function ($db) use ($debug_request) {
        $_POST = $debug_request;
        p0_assert(!p0_ajax('eipsi_debug_partial_response_handler')->success, 'Public diagnostic allowed');
        $GLOBALS['p0_admin'] = true;
        $response = p0_ajax('eipsi_debug_partial_response_handler');
        p0_assert($response->success && $response->data['raw_responses']['phq_score'] === 4, 'Authorized live diagnostic failed');
    });
};
$tests['DB real: retiro ajeno bloqueado y propio persistido'] = function () use ($withdraw_request) {
    p0_live_fixture(function ($db) use ($withdraw_request) {
        $_POST = $withdraw_request; $_POST['participant_id'] = '8';
        p0_assert(!p0_ajax('eipsi_abandon_study_handler')->success, 'Foreign withdrawal allowed');
        p0_assert((int) $db->get_var("SELECT is_active FROM {$db->prefix}survey_participants WHERE id = 8") === 1, 'Victim changed');
        $_POST = $withdraw_request;
        p0_assert(p0_ajax('eipsi_abandon_study_handler')->success, 'Self withdrawal rejected');
        p0_assert($db->get_var("SELECT consent_decision FROM {$db->prefix}survey_participants WHERE id = 7") === 'withdrawn', 'Self withdrawal not persisted');
        p0_assert((int) $db->get_var("SELECT is_active FROM {$db->prefix}survey_participants WHERE id = 8") === 1, 'Other participant changed');
    });
};
