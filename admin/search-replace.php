<?php
$SEO = [
    'title' => 'Search & Replace — Admin',
    'description' => 'Regex and keyword search/replace across files and database with preview.',
    'type' => 'website'
];
require_once __DIR__ . '/../required/header.php';
require_admin_or_redirect(path_with_base('/admin/'));

// Increase limits for deep scans (still sane caps)
@set_time_limit(300);
@ini_set('memory_limit', '1024M');

// Helpers
function sr_highlight_diff_segments(string $old, string $new): array {
    if ($old === $new) {
        return [
            'old' => htmlspecialchars($old),
            'new' => htmlspecialchars($new)
        ];
    }
    $lenOld = strlen($old);
    $lenNew = strlen($new);
    $min = min($lenOld, $lenNew);
    $i = 0;
    while ($i < $min && $old[$i] === $new[$i]) { $i++; }
    $j = 0;
    while ($j < ($min - $i) && $old[$lenOld - 1 - $j] === $new[$lenNew - 1 - $j]) { $j++; }
    $oldPrefix = substr($old, 0, $i);
    $oldDiff   = substr($old, $i, $lenOld - $i - $j);
    $oldSuffix = substr($old, $lenOld - $j);
    $newPrefix = substr($new, 0, $i);
    $newDiff   = substr($new, $i, $lenNew - $i - $j);
    $newSuffix = substr($new, $lenNew - $j);
    $oldHtml = htmlspecialchars($oldPrefix) . '<mark class="diff-del">' . htmlspecialchars($oldDiff) . '</mark>' . htmlspecialchars($oldSuffix);
    $newHtml = htmlspecialchars($newPrefix) . '<mark class="diff-add">' . htmlspecialchars($newDiff) . '</mark>' . htmlspecialchars($newSuffix);
    return [ 'old' => $oldHtml, 'new' => $newHtml ];
}
function sr_path_root(): string {
    // Root of this application (product directory)
    return realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');
}

function sr_is_text_extension(string $ext, array $allowedExts): bool {
    $ext = strtolower(ltrim($ext, '.'));
    if (empty($allowedExts)) return true;
    return in_array($ext, $allowedExts, true);
}

function sr_should_exclude_dir(string $dirName, array $excludes): bool {
    $dirName = strtolower($dirName);
    foreach ($excludes as $ex) {
        if ($dirName === strtolower($ex)) return true;
    }
    return false;
}

function sr_collect_files(string $baseDir, array $excludeDirs, array $allowedExts, int $maxFileSizeBytes = 2_000_000): array {
    $files = [];
    $baseDirReal = realpath($baseDir) ?: $baseDir;
    $iter = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($baseDirReal, FilesystemIterator::SKIP_DOTS),
            function ($current, $key, $iterator) use ($excludeDirs) {
                if ($current->isDir()) {
                    $name = $current->getFilename();
                    return !sr_should_exclude_dir($name, $excludeDirs);
                }
                return true;
            }
        ),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $file) {
        if (!$file->isFile()) continue;
        $ext = pathinfo($file->getFilename(), PATHINFO_EXTENSION);
        if (!sr_is_text_extension($ext, $allowedExts)) continue;
        $size = $file->getSize();
        if ($size > $maxFileSizeBytes) continue;
        // Avoid scanning our own backups
        if (strpos($file->getPathname(), DIRECTORY_SEPARATOR . 'backups' . DIRECTORY_SEPARATOR . 'search-replace' . DIRECTORY_SEPARATOR) !== false) continue;
        $files[] = $file->getPathname();
    }
    return $files;
}

function sr_build_regex(string $pattern, bool $caseSensitive, bool $singleLine, bool $multiLine): ?string {
    // If user supplied delimiters (/.../flags), use as-is; otherwise wrap with /
    $delimited = preg_match('/^\s*[\/~%#].+[\/~%#][a-zA-Z]*\s*$/', $pattern) === 1;
    if ($delimited) {
        // Append flags if needed (we won't modify user flags)
        return $pattern;
    }
    $flags = '';
    if (!$caseSensitive) $flags .= 'i';
    if ($singleLine) $flags .= 's';
    if ($multiLine) $flags .= 'm';
    // Always use UTF-8 safe
    $flags .= 'u';
    return '/' . str_replace('/', '\/', $pattern) . '/' . $flags;
}

function sr_preview_line_replace(string $line, array $opts): array {
    $mode = $opts['mode']; // 'string'|'regex'
    $search = $opts['search'] ?? '';
    $replace = $opts['replace'] ?? '';
    $caseSensitive = !empty($opts['case_sensitive']);
    $singleLine = !empty($opts['single_line']);
    $multiLine = !empty($opts['multi_line']);
    $occurrences = 0;
    $new = $line;
    if ($mode === 'regex') {
        $pattern = sr_build_regex($search, $caseSensitive, $singleLine, $multiLine);
        if ($pattern) {
            $new = @preg_replace($pattern, $replace, $line, -1, $occurrences);
            if ($new === null) { $new = $line; $occurrences = 0; }
        }
    } else {
        if ($caseSensitive) {
            $occurrences = substr_count($line, $search);
            $new = str_replace($search, $replace, $line);
        } else {
            // Case-insensitive replace
            $lower = strtolower($line);
            $needle = strtolower($search);
            if ($needle !== '') {
                $pos = 0; $buf = '';
                while (($idx = strpos($lower, $needle, $pos)) !== false) {
                    $occurrences++;
                    $buf .= substr($line, $pos, $idx - $pos) . $replace;
                    $pos = $idx + strlen($search);
                }
                $buf .= substr($line, $pos);
                $new = $buf;
            }
        }
    }
    return [$new, $occurrences];
}

function sr_backup_file(string $absPath): bool {
    $root = sr_path_root();
    $rel = ltrim(str_replace($root, '', $absPath), DIRECTORY_SEPARATOR);
    $backupDir = __DIR__ . '/backups/search-replace/' . date('Ymd_His');
    $backupPath = $backupDir . '/' . $rel;
    $dir = dirname($backupPath);
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (!is_dir($dir)) return false;
    if (!is_file($backupPath)) {
        @copy($absPath, $backupPath);
    }
    return true;
}

function sr_extract_urls(string $text): array {
    $urls = [];
    if (preg_match_all('#https?://[^\s"\'<>]+#i', $text, $m)) {
        foreach ($m[0] as $u) { $urls[] = $u; }
    }
    if (preg_match_all('#(?<![A-Za-z0-9_])/(?:[A-Za-z0-9_\-./?&=%]+)#', $text, $m2)) {
        foreach ($m2[0] as $p) { $urls[] = $p; }
    }
    // Dedup
    $urls = array_values(array_unique($urls));
    return $urls;
}

function sr_make_absolute_url(string $u): string {
    if (preg_match('#^https?://#i', $u)) return $u;
    if (strpos($u, '/') === 0) return site_origin() . $u;
    return $u;
}

function sr_is_internal_url(string $u): bool {
    $d = parse_url($u, PHP_URL_HOST);
    if (!$d) return true; // treat root-relative as internal
    $host = strtolower($d);
    return $host === strtolower(site_domain());
}

function sr_http_check_pass(string $u, int $timeoutSec = 4): bool {
    $ch = curl_init($u);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    $ok = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($ok === false || !$code) return false;
    if ($code === 405) {
        // Fallback GET for servers disallowing HEAD
        $ch = curl_init($u);
        curl_setopt_array($ch, [CURLOPT_NOBODY=>false, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>$timeoutSec]);
        $ok2 = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($ok2 === false || !$code) return false;
    }
    // Accept 2xx/3xx and auth-protected 401/403 as pass; fail 404/410
    if ($code >= 200 && $code < 400) return true;
    if ($code === 401 || $code === 403) return true;
    return false;
}

function sr_db_primary_key(PDO $pdo, string $table): string {
    try {
        $stmt = $pdo->prepare('SELECT k.COLUMN_NAME FROM information_schema.table_constraints t JOIN information_schema.key_column_usage k ON t.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND t.TABLE_SCHEMA = k.TABLE_SCHEMA AND t.TABLE_NAME = k.TABLE_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = ? AND t.CONSTRAINT_TYPE = "PRIMARY KEY" LIMIT 1');
        $stmt->execute([$table]);
        $col = $stmt->fetchColumn();
        if ($col) return (string)$col;
    } catch (Throwable $e) {}
    return 'id';
}

function sr_db_text_columns(PDO $pdo, string $table): array {
    $stmt = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    $cols = [];
    foreach ($stmt as $row) {
        $t = strtolower((string)$row['DATA_TYPE']);
        if (in_array($t, ['char','varchar','tinytext','text','mediumtext','longtext','json'], true)) {
            $cols[] = (string)$row['COLUMN_NAME'];
        }
    }
    return $cols;
}

function sr_db_tables(PDO $pdo): array {
    $out = [];
    $stmt = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
    foreach ($stmt as $row) { $out[] = (string)$row['TABLE_NAME']; }
    return $out;
}

function sr_log_admin_action(PDO $pdo, string $type, string $desc): void {
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_history (admin_id, action_type, action_description, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([
            current_admin_id() ?: current_user_id(),
            $type,
            substr($desc, 0, 1000),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255)
        ]);
    } catch (Throwable $e) { /* ignore */ }
}

// Initialize defaults and read form submission
$state = [
    'mode' => $_POST['mode'] ?? 'string', // string|regex
    'search' => $_POST['search'] ?? '',
    'replace' => $_POST['replace'] ?? '',
    'case_sensitive' => isset($_POST['case_sensitive']),
    'single_line' => isset($_POST['single_line']),
    'multi_line' => !empty($_POST['multi_line']),
    'scope_files' => isset($_POST['scope_files']) || (!isset($_POST['scope_files']) && !isset($_POST['scope_db'])),
    'scope_db' => isset($_POST['scope_db']),
    'file_exts' => $_POST['file_exts'] ?? 'php,js,css,html,sql,md,json,xml,txt',
    'exclude_dirs' => $_POST['exclude_dirs'] ?? 'vendor,node_modules,.git,backups,assets,.idea,.vscode',
    'max_matches_per_file' => max(1, (int)($_POST['max_matches_per_file'] ?? 200)),
    'db_strategy' => $_POST['db_strategy'] ?? 'sql', // sql|php
    'db_limit_per_table' => max(10, (int)($_POST['db_limit_per_table'] ?? 200)),
];

$action = $_POST['action'] ?? '';

// Preset for dev → prod URLs
if ($action === 'preset_dev_to_prod') {
    $state['mode'] = 'string';
    $state['search'] = 'https://product.example/';
    $state['replace'] = 'https://product.example/';
}

$results = [ 'files' => [], 'db' => [] ];
$summary = null; $errors = [];

if ($action === 'scan' || $action === 'apply') {
    if (trim($state['search']) === '' && $state['mode'] === 'string') {
        $errors[] = 'Search text is required.';
    }
    if ($state['mode'] === 'regex') {
        $patternTest = sr_build_regex($state['search'], !$state['case_sensitive'] ? false : true, !empty($state['single_line']), !empty($state['multi_line']));
        if ($patternTest === null) $errors[] = 'Invalid regex pattern.';
        else {
            set_error_handler(function(){ /* suppress regex warnings */ });
            $ok = @preg_match($patternTest, '');
            restore_error_handler();
            if ($ok === false) $errors[] = 'Invalid regex pattern.';
        }
    }
}

// Storage for scan results across form submits
start_session();
if (!isset($_SESSION['sr_jobs'])) $_SESSION['sr_jobs'] = [];

$jobId = $_POST['job_id'] ?? null;
if (($action === 'scan' || $action === 'audit_prod_urls') && empty($errors)) {
    $jobId = 'sr_' . bin2hex(random_bytes(6));
    $root = sr_path_root();
    $isAudit = ($action === 'audit_prod_urls' && !is_dev_base_forced());
    if ($isAudit) {
        // Production audit: eliminate dev absolute URLs and stray / prefixes
        $auditPatterns = [
            [ 'mode' => 'string', 'search' => 'https://product.example/', 'replace' => 'https://product.example/' ],
            [ 'mode' => 'regex', 'search' => '/(?<![A-Za-z0-9_])\/product\//u', 'replace' => '/' ],
        ];
    } else {
        $auditPatterns = [ [ 'mode' => $state['mode'], 'search' => $state['search'], 'replace' => $state['replace'] ] ];
    }

    if ($state['scope_files']) {
        $allowedExts = array_values(array_filter(array_map('trim', explode(',', $state['file_exts']))));
        $excludeDirs = array_values(array_filter(array_map('trim', explode(',', $state['exclude_dirs']))));
        $files = sr_collect_files($root, $excludeDirs, $allowedExts);
        foreach ($files as $abs) {
            $rel = ltrim(str_replace($root, '', $abs), DIRECTORY_SEPARATOR);
            $lines = @file($abs, FILE_IGNORE_NEW_LINES);
            if ($lines === false) continue;
            $fileMatches = [];
            $fileTotal = 0;
            foreach ($lines as $idx => $line) {
                $orig = $line . "\n"; $current = $orig; $totalOcc = 0;
                foreach ($auditPatterns as $pat) {
                    [$next, $occ] = sr_preview_line_replace($current, [
                        'mode' => $pat['mode'],
                        'search' => $pat['search'],
                        'replace' => $pat['replace'],
                        'case_sensitive' => $state['case_sensitive'],
                        'single_line' => $state['single_line'],
                        'multi_line' => $state['multi_line'],
                    ]);
                    $current = $next; $totalOcc += $occ;
                }
                if ($totalOcc > 0 && $current !== $orig) {
                    $fileTotal += $totalOcc;
                    $fileMatches[] = [
                        'line_no' => $idx + 1,
                        'old' => $orig,
                        'new' => $current,
                        'occ' => $totalOcc,
                        'selected' => true,
                    ];
                    if (count($fileMatches) >= $state['max_matches_per_file']) break;
                }
            }
            if (!empty($fileMatches)) {
                $results['files'][] = [
                    'path' => $rel,
                    'matches' => $fileMatches,
                    'total_occurrences' => $fileTotal,
                ];
            }
        }
    }

    if ($state['scope_db']) {
        try {
            $pdo = db();
            $tables = sr_db_tables($pdo);
            foreach ($tables as $table) {
                $pk = sr_db_primary_key($pdo, $table);
                $cols = sr_db_text_columns($pdo, $table);
                if (empty($cols)) continue;
                $tablePreview = [];
                foreach ($cols as $col) {
                    try {
                        $rows = [];
                        if ($isAudit) {
                            // Probe dev absolute and / occurrences
                            $q1 = $pdo->prepare("SELECT `$pk` AS pk, `$col` AS val FROM `$table` WHERE `$col` LIKE ? LIMIT " . intval($state['db_limit_per_table']));
                            $q1->execute(['%https://product.example/%']);
                            $rows = array_merge($rows, $q1->fetchAll());
                            $q2 = $pdo->prepare("SELECT `$pk` AS pk, `$col` AS val FROM `$table` WHERE `$col` LIKE ? LIMIT " . intval($state['db_limit_per_table']));
                            $q2->execute(['%/%']);
                            $rows = array_merge($rows, $q2->fetchAll());
                        } else {
                            if ($state['mode'] === 'string') {
                                $needle = $state['case_sensitive'] ? $state['search'] : strtolower($state['search']);
                                $like = '%' . $needle . '%';
                                if ($state['case_sensitive']) {
                                    $q = $pdo->prepare("SELECT `$pk` AS pk, `$col` AS val FROM `$table` WHERE `$col` LIKE ? LIMIT " . intval($state['db_limit_per_table']));
                                    $q->execute([$like]);
                                } else {
                                    // Case-insensitive LIKE by lowering both sides
                                    $q = $pdo->prepare("SELECT `$pk` AS pk, `$col` AS val FROM `$table` WHERE LOWER(`$col`) LIKE ? LIMIT " . intval($state['db_limit_per_table']));
                                    $q->execute([$like]);
                                }
                                $rows = $q->fetchAll();
                            } else {
                                // Regex preview (MySQL REGEXP)
                                $q = $pdo->prepare("SELECT `$pk` AS pk, `$col` AS val FROM `$table` WHERE `$col` REGEXP ? LIMIT " . intval($state['db_limit_per_table']));
                                $q->execute([$state['search']]);
                                $rows = $q->fetchAll();
                            }
                        }

                        foreach ($rows as $r) {
                            $old = (string)($r['val'] ?? '');
                            $current = $old; $totalOcc = 0;
                            foreach ($auditPatterns as $pat) {
                                [$next, $occ] = sr_preview_line_replace($current, [
                                    'mode' => $pat['mode'],
                                    'search' => $pat['search'],
                                    'replace' => $pat['replace'],
                                    'case_sensitive' => $state['case_sensitive'],
                                    'single_line' => true,
                                    'multi_line' => false,
                                ]);
                                $current = $next; $totalOcc += $occ;
                            }
                            $new = $current; $occ = $totalOcc;
                            if ($occ > 0 && $new !== $old) {
                                $tablePreview[] = [
                                    'pk' => $r['pk'],
                                    'column' => $col,
                                    'old' => $old,
                                    'new' => $new,
                                ];
                            }
                        }
                    } catch (Throwable $e) { /* ignore problematic columns */ }
                }
                if (!empty($tablePreview)) {
                    $results['db'][] = [ 'table' => $table, 'pk' => $pk, 'rows' => $tablePreview ];
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Database scan failed: ' . $e->getMessage();
        }
    }

    $_SESSION['sr_jobs'][$jobId] = [
        'state' => $state,
        'results' => $results,
        'root' => $root,
        'is_audit' => $isAudit ?? false,
        'created_at' => date('c')
    ];
}

if ($action === 'apply' && $jobId && isset($_SESSION['sr_jobs'][$jobId]) && empty($errors)) {
    $job = $_SESSION['sr_jobs'][$jobId];
    $state = $job['state'];
    $results = $job['results'];
    $root = $job['root'];
    $filesChanged = 0; $linesChanged = 0; $dbRowsChanged = 0;
    $applyFiles = isset($_POST['apply_files']);
    $applyDb = isset($_POST['apply_db']);
    $doValidate = isset($_POST['validate_http']);
    $internalOnly = isset($_POST['validate_internal_only']);
    $timeoutSec = max(2, min(10, (int)($_POST['validate_timeout'] ?? 4)));
    $validationFailures = [];

    if ($applyFiles && !empty($results['files'])) {
        foreach ($results['files'] as $idx => $fileInfo) {
            $rel = $fileInfo['path'];
            $abs = $root . DIRECTORY_SEPARATOR . $rel;
            $lines = @file($abs, FILE_IGNORE_NEW_LINES);
            if ($lines === false) continue;
            $changedThisFile = 0;
            foreach ($fileInfo['matches'] as $m) {
                $ln = (int)$m['line_no'];
                if (!isset($_POST['sel'][$idx][$ln])) continue; // not selected
                $posted = $_POST['line_new'][$idx][$ln] ?? null;
                if ($posted === null) continue;
                $newLine = $posted;
                // Ensure line endings preserved: if original line had trailing newline, add it back
                $hasNl = substr($m['old'], -1) === "\n";
                if ($hasNl && substr($newLine, -1) !== "\n") $newLine .= "\n";
                $orig = $lines[$ln - 1] . "\n"; // synthesize with newline for comparison
                if ($orig !== $newLine) {
                    // Optional link validation for the NEW content
                    if ($doValidate) {
                        $urls = sr_extract_urls($newLine);
                        foreach ($urls as $u) {
                            $abs = sr_make_absolute_url($u);
                            if ($internalOnly && !sr_is_internal_url($abs)) continue;
                            if (!sr_http_check_pass($abs, $timeoutSec)) {
                                $validationFailures[] = ['type'=>'file','file'=>$rel,'line'=>$ln,'url'=>$abs];
                            }
                        }
                    }
                    // Backup once per file
                    if ($changedThisFile === 0) sr_backup_file($abs);
                    // Overwrite stored line (strip newline for storage as file() used FILE_IGNORE_NEW_LINES)
                    $lines[$ln - 1] = rtrim($newLine, "\n");
                    $changedThisFile++;
                    $linesChanged++;
                }
            }
            if ($changedThisFile > 0) {
                @file_put_contents($abs, implode("\n", $lines) . "\n");
                $filesChanged++;
            }
        }
    }

    if ($applyDb && !empty($results['db'])) {
        try {
            $pdo = db();
            foreach ($results['db'] as $tIdx => $tInfo) {
                $table = $tInfo['table'];
                $pk = $tInfo['pk'];
                foreach ($tInfo['rows'] as $r) {
                    $lnKey = $table . '|' . $pk . '|' . $r['pk'] . '|' . $r['column'];
                    if (empty($_POST['sel_db'][$lnKey])) continue;
                    $newVal = $_POST['db_new'][$lnKey] ?? $r['new'];
                    if ($state['db_strategy'] === 'sql' && $state['mode'] === 'string') {
                        // Use SQL REPLACE for string mode. Update only matching column for selected row.
                        $q = $pdo->prepare("UPDATE `$table` SET `{$r['column']}` = REPLACE(`{$r['column']}`, ?, ?) WHERE `$pk` = ? LIMIT 1");
                        $q->execute([$state['search'], $state['replace'], $r['pk']]);
                        $dbRowsChanged += $q->rowCount();
                    } else {
                        // PHP strategy or regex: set explicit new value we previewed/edited
                        // Optional validation: sample URLs in $newVal
                        if ($doValidate) {
                            $urls = sr_extract_urls($newVal);
                            foreach ($urls as $u) {
                                $abs = sr_make_absolute_url($u);
                                if ($internalOnly && !sr_is_internal_url($abs)) continue;
                                if (!sr_http_check_pass($abs, $timeoutSec)) {
                                    $validationFailures[] = ['type'=>'db','table'=>$table,'pk'=>$r['pk'],'column'=>$r['column'],'url'=>$abs];
                                }
                            }
                        }
                        $q = $pdo->prepare("UPDATE `$table` SET `{$r['column']}` = ? WHERE `$pk` = ? LIMIT 1");
                        $q->execute([$newVal, $r['pk']]);
                        $dbRowsChanged += $q->rowCount();
                    }
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Database apply failed: ' . $e->getMessage();
        }
    }

    $summary = [
        'files_changed' => $filesChanged,
        'lines_changed' => $linesChanged,
        'db_rows_changed' => $dbRowsChanged,
        'validation_failures' => $validationFailures,
    ];

    // Log action
    try {
        $pdo = db();
        sr_log_admin_action($pdo, 'search_replace', json_encode([
            'mode' => $state['mode'], 'files_changed' => $filesChanged, 'lines_changed' => $linesChanged, 'db_rows_changed' => $dbRowsChanged
        ], JSON_UNESCAPED_SLASHES));
    } catch (Throwable $e) {}
}
?>

<div class="container">
    <?php include __DIR__ . '/admin-menu.php'; ?>

    <div class="reference-card" style="background:white; border-radius:12px; box-shadow:0 4px 16px rgba(0,0,0,0.1);">
        <div class="card-header" style="background:linear-gradient(135deg,#111827,#1f2937); color:white; padding:20px 24px; border-radius:12px 12px 0 0;">
            <h1 style="margin:0;">🔎 Search & Replace (Files + DB)</h1>
            <p style="margin:8px 0 0 0; opacity:.9;">Regex and keyword search/replace across codebase and MySQL with preview, per-line edits, and selective apply.</p>
        </div>
        <div class="card-body" style="padding:24px;">
            <style>
                mark.diff-add { background: #d1fae5; color: #065f46; }
                mark.diff-del { background: #fee2e2; color: #7f1d1d; text-decoration: line-through; }
            </style>
            <?php if (!empty($errors)): ?>
                <div class="card" role="alert" style="border-color:#ffb3b3;background:#fff5f5;color:#8a1f1f; margin-bottom:12px;">
                    <strong>Errors:</strong> <?php echo sanitize(implode(' • ', $errors)); ?>
                </div>
            <?php endif; ?>

            <?php if ($summary): ?>
                <div class="card" role="status" style="border-color:#b7e4c7;background:#f1fff6;color:#084c2e; margin-bottom:12px;">
                    Applied changes: <?php echo intval($summary['files_changed']); ?> files (<?php echo intval($summary['lines_changed']); ?> lines), <?php echo intval($summary['db_rows_changed']); ?> DB rows.
                    <?php if (!empty($summary['validation_failures'])): ?>
                        <div style="margin-top:6px; color:#7f1d1d;">Validation: <?php echo count($summary['validation_failures']); ?> potential 404s or invalid URLs detected (see below).</div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($summary['validation_failures'])): ?>
                    <div class="card" style="background:#fff5f5; border-color:#fecaca; color:#7f1d1d; margin-bottom:12px;">
                        <div style="font-weight:600; margin-bottom:6px;">Potential URL Issues</div>
                        <div style="max-height:220px; overflow:auto;">
                            <table class="bulk-table" style="width:100%; font-size:12px;">
                                <thead><tr><th>Type</th><th>Location</th><th>URL</th></tr></thead>
                                <tbody>
                                <?php foreach ($summary['validation_failures'] as $vf): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($vf['type']); ?></td>
                                        <td>
                                            <?php if ($vf['type']==='file'): ?>
                                                <code><?php echo htmlspecialchars(($vf['file'] ?? '') . ':' . ($vf['line'] ?? '')); ?></code>
                                            <?php else: ?>
                                                <code><?php echo htmlspecialchars(($vf['table'] ?? '') . '[' . ($vf['pk'] ?? '') . '].' . ($vf['column'] ?? '')); ?></code>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:520px;" title="<?php echo htmlspecialchars($vf['url']); ?>">
                                            <a href="<?php echo htmlspecialchars($vf['url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($vf['url']); ?></a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" style="display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; align-items:end;">
                <input type="hidden" name="action" value="scan">

                <label style="grid-column: span 4;">
                    <span style="display:block; font-size:12px; color:#6b7280;">Mode</span>
                    <div style="display:flex; gap:12px;">
                        <label><input type="radio" name="mode" value="string" <?php echo $state['mode']==='string'?'checked':''; ?>> String</label>
                        <label><input type="radio" name="mode" value="regex" <?php echo $state['mode']==='regex'?'checked':''; ?>> Regex</label>
                        <label><input type="checkbox" name="case_sensitive" <?php echo $state['case_sensitive']?'checked':''; ?>> Case sensitive</label>
                        <label title="Regex single-line (dotall)"><input type="checkbox" name="single_line" <?php echo $state['single_line']?'checked':''; ?>> Dotall</label>
                        <label title="Regex multiline (anchors ^$ by line)"><input type="checkbox" name="multi_line" <?php echo $state['multi_line']?'checked':''; ?>> Multiline</label>
                    </div>
                </label>

                <label style="grid-column: span 2;">
                    <span style="display:block; font-size:12px; color:#6b7280;">Search</span>
                    <input name="search" value="<?php echo htmlspecialchars($state['search']); ?>" autocomplete="off">
                </label>
                <label style="grid-column: span 2;">
                    <span style="display:block; font-size:12px; color:#6b7280;">Replace</span>
                    <input name="replace" value="<?php echo htmlspecialchars($state['replace']); ?>" autocomplete="off">
                </label>

                <div style="grid-column: span 4; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <button class="btn" type="submit">Scan</button>
                    <button class="btn secondary" name="action" value="preset_dev_to_prod">Preset: dev → prod URLs</button>
                    <?php if (!is_dev_base_forced()): ?>
                        <button class="btn secondary" name="action" value="audit_prod_urls" title="Find stray / prefixes and dev URLs when production is active">Audit Production URLs</button>
                    <?php endif; ?>
                    <div class="muted" style="margin-left:auto;">
                        Active Base URL: <code><?php echo sanitize(site_base_url()); ?></code>
                        • Domain: <strong><?php echo sanitize(site_domain()); ?></strong>
                    </div>
                </div>

                <fieldset style="grid-column: span 4; border:1px solid #e5e7eb; border-radius:8px; padding:12px;">
                    <legend style="padding:0 6px; color:#374151;">Scope</legend>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
                        <div>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:600;"><input type="checkbox" name="scope_files" <?php echo $state['scope_files']?'checked':''; ?>> Files</label>
                            <label><span style="display:block; font-size:12px; color:#6b7280;">Extensions (comma)</span><input name="file_exts" value="<?php echo htmlspecialchars($state['file_exts']); ?>"></label>
                            <label><span style="display:block; font-size:12px; color:#6b7280;">Exclude Dirs (comma)</span><input name="exclude_dirs" value="<?php echo htmlspecialchars($state['exclude_dirs']); ?>"></label>
                            <label><span style="display:block; font-size:12px; color:#6b7280;">Max matches per file</span><input type="number" name="max_matches_per_file" value="<?php echo intval($state['max_matches_per_file']); ?>" min="1" max="5000"></label>
                        </div>
                        <div>
                            <label style="display:flex; align-items:center; gap:8px; font-weight:600;"><input type="checkbox" name="scope_db" <?php echo $state['scope_db']?'checked':''; ?>> Database</label>
                            <label><span style="display:block; font-size:12px; color:#6b7280;">Strategy</span>
                                <select name="db_strategy">
                                    <option value="sql" <?php echo $state['db_strategy']==='sql'?'selected':''; ?>>SQL REPLACE (string only, fast)</option>
                                    <option value="php" <?php echo $state['db_strategy']==='php'?'selected':''; ?>>PHP apply (regex allowed, slower)</option>
                                </select>
                            </label>
                            <label><span style="display:block; font-size:12px; color:#6b7280;">Preview limit per table</span><input type="number" name="db_limit_per_table" value="<?php echo intval($state['db_limit_per_table']); ?>" min="10" max="2000"></label>
                        </div>
                    </div>
                </fieldset>
            </form>

            <?php if ($jobId && isset($_SESSION['sr_jobs'][$jobId])): $job = $_SESSION['sr_jobs'][$jobId]; $results = $job['results']; ?>
                <hr style="margin:20px 0; border:none; border-top:1px solid #e5e7eb;">
                <form method="post">
                    <input type="hidden" name="action" value="apply">
                    <input type="hidden" name="job_id" value="<?php echo sanitize($jobId); ?>">
                    <input type="hidden" name="mode" value="<?php echo sanitize($state['mode']); ?>">
                    <input type="hidden" name="search" value="<?php echo htmlspecialchars($state['search']); ?>">
                    <input type="hidden" name="replace" value="<?php echo htmlspecialchars($state['replace']); ?>">
                    <input type="hidden" name="case_sensitive" value="<?php echo $state['case_sensitive'] ? '1' : ''; ?>">
                    <input type="hidden" name="single_line" value="<?php echo $state['single_line'] ? '1' : ''; ?>">
                    <input type="hidden" name="multi_line" value="<?php echo $state['multi_line'] ? '1' : ''; ?>">
                    <input type="hidden" name="db_strategy" value="<?php echo sanitize($state['db_strategy']); ?>">

                    <div style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="apply_files" checked> Apply to Files</label>
                        <label style="display:flex; align-items:center; gap:8px;"><input type="checkbox" name="apply_db"> Apply to Database</label>
                        <?php if (!empty($job['is_audit'])): ?>
                            <span class="adm-pill" style="background:#fef3c7;border:1px solid #f59e0b;">Production URL Audit</span>
                        <?php endif; ?>
                        <button class="btn" type="submit">Apply Selected Changes</button>
                        <a class="btn secondary" href="<?php echo path_with_base('/admin/search-replace.php'); ?>">Reset</a>
                        <div style="margin-left:auto; display:flex; align-items:center; gap:8px;">
                            <label style="display:flex; align-items:center; gap:6px;" title="HEAD/GET check new URLs for 404 before apply">
                                <input type="checkbox" name="validate_http" checked> Validate URLs (404 guard)
                            </label>
                            <label style="display:flex; align-items:center; gap:6px;" title="Only check internal links on this domain to avoid slow external checks">
                                <input type="checkbox" name="validate_internal_only" checked> Internal only
                            </label>
                            <label style="display:flex; align-items:center; gap:6px;">
                                Timeout <input type="number" name="validate_timeout" min="2" max="10" value="4" style="width:60px;"> s
                            </label>
                        </div>
                    </div>

                    <?php if (!empty($results['files'])): ?>
                        <h3 style="margin:12px 0;">Files Preview</h3>
                        <div class="muted" style="margin-bottom:8px;">Edit per-line as needed. Only checked lines will be applied.</div>
                        <?php foreach ($results['files'] as $fIdx => $fi): ?>
                            <div class="card" style="margin-bottom:12px; padding:12px; border:1px solid #e5e7eb; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div><strong><?php echo htmlspecialchars($fi['path']); ?></strong> <span class="muted">(<?php echo intval($fi['total_occurrences']); ?> matches)</span></div>
                                    <div>
                                        <label style="font-size:12px; color:#6b7280;">
                                            <input type="checkbox" onclick="(function(cb){ const box = cb.closest('.card'); box.querySelectorAll('input[type=checkbox][data-sel]')?.forEach(x=>x.checked=cb.checked); })(this)"> Select all
                                        </label>
                                    </div>
                                </div>
                                <div style="margin-top:8px;">
                                    <?php foreach ($fi['matches'] as $m): $ln = $m['line_no']; ?>
                                        <div style="display:grid; grid-template-columns: 60px 1fr; gap:8px; align-items:start; padding:6px 0; border-bottom:1px solid #f3f4f6;">
                                            <div>
                                                <label style="display:flex; align-items:center; gap:6px;">
                                                    <input data-sel type="checkbox" name="sel[<?php echo $fIdx; ?>][<?php echo $ln; ?>]" <?php echo $m['selected']?'checked':''; ?>>
                                                    <span style="font-size:12px; color:#6b7280;">#<?php echo $ln; ?></span>
                                                </label>
                                            </div>
                                            <div>
                                                <?php $hl = sr_highlight_diff_segments($m['old'], $m['new']); ?>
                                                <div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px; white-space:pre-wrap; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:6px; margin-bottom:6px; overflow:auto;">
                                                    <div style="color:#6b7280; font-size:11px; margin-bottom:4px;">Old</div>
                                                    <div><?php echo $hl['old']; ?></div>
                                                </div>
                                                <textarea name="line_new[<?php echo $fIdx; ?>][<?php echo $ln; ?>]" rows="2" style="width:100%; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px;"><?php echo htmlspecialchars($m['new']); ?></textarea>
                                                <div class="muted" style="font-size:11px; margin-top:4px;">New preview highlights added content; you can edit before applying.</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="muted">No file matches found.</div>
                    <?php endif; ?>

                    <?php if (!empty($results['db'])): ?>
                        <h3 style="margin:16px 0 8px;">Database Preview</h3>
                        <div class="muted" style="margin-bottom:8px;">Select rows to update. Strategy: <strong><?php echo $state['db_strategy']==='sql' ? 'SQL REPLACE (fast, string only)' : 'PHP per-row (regex allowed)'; ?></strong></div>
                        <?php foreach ($results['db'] as $t): ?>
                            <div class="card" style="margin-bottom:12px; padding:12px; border:1px solid #e5e7eb; border-radius:8px;">
                                <div style="display:flex; justify-content:space-between;">
                                    <div><strong><?php echo htmlspecialchars($t['table']); ?></strong> <span class="muted">(PK: <?php echo htmlspecialchars($t['pk']); ?>)</span></div>
                                    <label style="font-size:12px; color:#6b7280;">
                                        <input type="checkbox" onclick="(function(cb){ const card = cb.closest('.card'); card.querySelectorAll('input[type=checkbox][data-sel-db]')?.forEach(x=>x.checked=cb.checked); })(this)"> Select all rows
                                    </label>
                                </div>
                                <div style="margin-top:8px;">
                                    <?php foreach ($t['rows'] as $r): $key = $t['table'].'|'.$t['pk'].'|'.$r['pk'].'|'.$r['column']; ?>
                                        <div style="display:grid; grid-template-columns: 220px 1fr; gap:8px; align-items:start; padding:6px 0; border-bottom:1px solid #f3f4f6;">
                                            <div>
                                                <label style="display:flex; align-items:center; gap:6px;">
                                                    <input data-sel-db type="checkbox" name="sel_db[<?php echo htmlspecialchars($key); ?>]">
                                                    <span style="font-size:12px; color:#6b7280;">Row #<?php echo htmlspecialchars((string)$r['pk']); ?> • <code><?php echo htmlspecialchars($r['column']); ?></code></span>
                                                </label>
                                            </div>
                                            <div>
                                                <?php $hlDb = sr_highlight_diff_segments($r['old'], $r['new']); ?>
                                                <div style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px; white-space:pre-wrap; background:#f9fafb; border:1px solid #e5e7eb; border-radius:6px; padding:6px; margin-bottom:6px; overflow:auto; max-height:180px;">
                                                    <div style="color:#6b7280; font-size:11px; margin-bottom:4px;">Old</div>
                                                    <div><?php echo $hlDb['old']; ?></div>
                                                </div>
                                                <textarea name="db_new[<?php echo htmlspecialchars($key); ?>]" rows="3" style="width:100%; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size:12px;"><?php echo htmlspecialchars($r['new']); ?></textarea>
                                                <div class="muted" style="font-size:11px; margin-top:4px;">New preview highlights added content; you can edit before applying.</div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php if ($state['scope_db']): ?>
                            <div class="muted">No database matches found in preview limit.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../required/footer.php'; ?>


