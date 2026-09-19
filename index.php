<?php
// index.php — No-Code Engine (v1.6)
// PHP 8.0+ / Zero Dependencies / SQLite или MySQL через PDO.
//
// Запуск:
//   php -S localhost:8000 index.php
//   HTML: http://localhost:8000/?action=home
//   JSON: http://localhost:8000/?action=home&format=json
//
// Тесты:
//   CLI:  php index.php --run-ci
//   Web:  http://localhost:8000/?run_ci=1   (только APP_ENV=dev)

declare(strict_types=1);

// =========================================================================
// CLI-server guard
// =========================================================================
if (php_sapi_name() === 'cli-server') {
    $__uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
    if ($__uri !== '/' && strpos($__uri, '..') === false) {
        $__file = __DIR__ . rawurldecode($__uri);
        if (is_file($__file)) return false;
    }
}

// =========================================================================
// Окружение и сессия
// =========================================================================
define('APP_ENV', getenv('APP_ENV') ?: 'dev');
define('IS_CLI', php_sapi_name() === 'cli');
define('ENGINE_VERSION', '1.7.0');
const TEST_REDIRECT_PREFIX = '__REDIRECT__:';
const APP_SESSION_VERSION  = 'nocode-v1';

if (IS_CLI) {
    if (!isset($_SESSION)) $_SESSION = [];
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (($_SESSION['__v'] ?? null) !== APP_SESSION_VERSION) {
        $_SESSION = ['__v' => APP_SESSION_VERSION];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }
}

$CONFIG = [
    'db_path' => getenv('DB_DSN') ?: (__DIR__ . '/nocode.db'),
];

// =========================================================================
// Preflight
// =========================================================================
(static function (): void {
    $required = [
        '_layout', 'error', 'home',
        'entity_list', 'entity_item_edit', 'login', 'register', 'lookup',
        'content_type_list', 'content_type_edit',
        'list_index', 'list_edit',
    ];
    $missing = [];
    foreach ($required as $name) {
        if (!is_file(__DIR__ . '/views/' . $name . '.phtml')) {
            $missing[] = "views/{$name}.phtml";
        }
    }
    if ($missing) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo "Не найдены шаблоны:\n - " . implode("\n - ", $missing) . "\n";
        echo "Ожидаемая папка: " . __DIR__ . "/views\n";
        exit(1);
    }
})();

// =========================================================================
// 1. ИНФРАСТРУКТУРА
// =========================================================================

final class Db
{
    public PDO $pdo;

    public function __construct(string $dsn)
    {
        $realDsn = str_starts_with($dsn, 'mysql:') ? $dsn : 'sqlite:' . $dsn;
        $this->pdo = new PDO($realDsn);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        if (str_starts_with($realDsn, 'sqlite:')) {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }
        $this->migrate();
    }

    public static function inMemory(): self
    {
        return new self(':memory:');
    }

    public function driver(): string
    {
        return (string)$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function migrate(): void
    {
        $idType = 'VARCHAR(191)';

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id             {$idType} PRIMARY KEY,
            email          {$idType} NOT NULL UNIQUE,
            name           {$idType} NOT NULL,
            password_hash  VARCHAR(255) NOT NULL,
            created_at     TEXT NOT NULL
        )");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS content_types (
            id           {$idType} PRIMARY KEY,
            name         {$idType} NOT NULL,
            schema_json  TEXT NOT NULL,
            created_at   TEXT NOT NULL
        )");

        // ---- Списки: сущность, привязанная к типу контента ----
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS entity_lists (
            id               {$idType} PRIMARY KEY,
            name             {$idType} NOT NULL,
            description      TEXT,
            content_type_id  {$idType} NOT NULL,
            created_at       TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entity_lists_ct ON entity_lists(content_type_id)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS entity_items (
            id               {$idType} PRIMARY KEY,
            list_id          {$idType} NOT NULL,
            content_type_id  {$idType} NOT NULL,
            data_json        TEXT NOT NULL,
            created_at       TEXT NOT NULL,
            updated_at       TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entity_items_list ON entity_items(list_id)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS entity_links (
            id            {$idType} PRIMARY KEY,
            from_item_id  {$idType} NOT NULL,
            to_item_id    {$idType} NOT NULL,
            field_name    {$idType} NOT NULL,
            created_at    TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entity_links_from ON entity_links(from_item_id, field_name)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_entity_links_to   ON entity_links(to_item_id)");

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS entity_indexes (
            id           {$idType} PRIMARY KEY,
            list_id      {$idType} NOT NULL,
            field_name   {$idType} NOT NULL,
            created_at   TEXT NOT NULL
        )");

        // ---- Бэкфилл: для существующих list_id создаём заглушки в entity_lists ----
        $orphans = $this->pdo->query("
            SELECT e.list_id,
                   MIN(e.created_at) AS first_at,
                   (SELECT content_type_id FROM entity_items
                    WHERE list_id = e.list_id ORDER BY created_at ASC LIMIT 1) AS ct
            FROM entity_items e
            WHERE NOT EXISTS (SELECT 1 FROM entity_lists l WHERE l.id = e.list_id)
            GROUP BY e.list_id
        ")->fetchAll();

        if ($orphans) {
            $ins = $this->pdo->prepare("INSERT INTO entity_lists
                (id, name, description, content_type_id, created_at)
                VALUES (?, ?, '', ?, ?)");
            foreach ($orphans as $o) {
                if ($o['ct'] === null) continue;
                $ins->execute([
                    $o['list_id'],
                    $o['list_id'],
                    $o['ct'],
                    $o['first_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
        }
    }
}

final class Engine
{
    public static function view(string $name, array $data = []): string
    {
        $__path = __DIR__ . '/views/' . $name . '.phtml';
        if (!is_file($__path)) {
            throw new RuntimeException("View template not found: {$__path}");
        }
        $__level = ob_get_level();
        extract($data, EXTR_SKIP);
        ob_start();
        try {
            include $__path;
            return (string)ob_get_clean();
        } finally {
            while (ob_get_level() > $__level) ob_end_clean();
        }
    }
}

final class Layout
{
    public static function render(string $title, string $contentHtml): string
    {
        return Engine::view('_layout', [
            'title'       => $title,
            'contentHtml' => $contentHtml,
            'csrf'        => Csrf::token(),
            'user'        => Auth::user(),
        ]);
    }

    public static function error(int $status, string $title, string $message): string
    {
        if (!headers_sent()) http_response_code($status);
        try {
            $inner = Engine::view('error', [
                'title'   => $title,
                'message' => $message,
                'csrf'    => Csrf::token(),
                'user'    => Auth::user(),
            ]);
        } catch (Throwable $e) {
            $t = htmlspecialchars($title, ENT_QUOTES);
            $m = htmlspecialchars($message, ENT_QUOTES);
            return "<!DOCTYPE html><html><head><meta charset='utf-8'><title>{$t}</title></head>"
                 . "<body><h1>{$t}</h1><p>{$m}</p></body></html>";
        }
        return self::render("Ошибка {$status}", $inner);
    }
}

final class Json
{
    public static function render(mixed $data, int $status = 200): string
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function error(string $message, int $status = 400): string
    {
        return self::render(['error' => $message], $status);
    }
}

final class DomainResult
{
    private function __construct(
        private bool $success,
        private mixed $data = null,
        private string $error = ''
    ) {}
    public static function success(mixed $data = null): self { return new self(true, $data); }
    public static function failure(string $error, mixed $data = null): self { return new self(false, $data, $error); }
    public function isSuccess(): bool { return $this->success; }
    public function isFailure(): bool { return !$this->success; }
    public function getData(): mixed { return $this->data; }
    public function getError(): string { return $this->error; }
}

final class Csrf
{
    private static ?string $mock = null;
    public static function setMock(?string $token): void { self::$mock = $token; }

    public static function token(): string
    {
        if (self::$mock !== null) return self::$mock;
        if (!isset($_SESSION)) return 'no-session';
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf_token'];
    }

    public static function verify(?string $token): bool
    {
        if (self::$mock !== null) {
            return $token !== null && hash_equals(self::$mock, $token);
        }
        if (empty($_SESSION['csrf_token'])) return false;
        return $token !== null && hash_equals((string)$_SESSION['csrf_token'], $token);
    }
}

final class Auth
{
    /** @var array<string,mixed>|null */
    private static ?array $mockSession = null;

    public static function setMockSession(?array $data): void { self::$mockSession = $data; }

    public static function login(array $user): void
    {
        if (self::$mockSession !== null) { self::$mockSession['user'] = $user; return; }
        $_SESSION['user'] = $user;
    }

    public static function logout(): void
    {
        if (self::$mockSession !== null) { self::$mockSession['user'] = null; return; }
        unset($_SESSION['user']);
    }

    public static function user(): ?array
    {
        if (self::$mockSession !== null) return self::$mockSession['user'] ?? null;
        return $_SESSION['user'] ?? null;
    }

    public static function check(): bool { return self::user() !== null; }

    public static function id(): ?string
    {
        $u = self::user();
        return $u['id'] ?? null;
    }
}

// =========================================================================
// 2. NO-CODE ПОМОЩНИК
// =========================================================================

final class NoCode
{
    public static function validate(array $payload, array $schema): array
    {
        $errors = [];
        foreach ($schema as $field => $rules) {
            $exists = array_key_exists($field, $payload)
                   && $payload[$field] !== null
                   && $payload[$field] !== '';

            if (!empty($rules['required']) && !$exists) {
                $errors[] = "Поле '{$field}' обязательно.";
                continue;
            }
            if (!$exists) continue;

            $val  = $payload[$field];
            $type = $rules['type'] ?? 'string';

            switch ($type) {
                case 'string':
                    if (!is_string($val) && !is_numeric($val)) {
                        $errors[] = "Поле '{$field}' должно быть строкой.";
                        break;
                    }
                    $s = (string)$val;
                    if (isset($rules['min_length']) && mb_strlen($s) < (int)$rules['min_length']) {
                        $errors[] = "Поле '{$field}': минимум {$rules['min_length']} символов.";
                    }
                    if (isset($rules['max_length']) && mb_strlen($s) > (int)$rules['max_length']) {
                        $errors[] = "Поле '{$field}': максимум {$rules['max_length']} символов.";
                    }
                    break;

                case 'number':
                    if (!is_numeric($val)) {
                        $errors[] = "Поле '{$field}' должно быть числом.";
                        break;
                    }
                    $n = (float)$val;
                    if (isset($rules['min']) && $n < (float)$rules['min']) {
                        $errors[] = "Поле '{$field}': минимум {$rules['min']}.";
                    }
                    if (isset($rules['max']) && $n > (float)$rules['max']) {
                        $errors[] = "Поле '{$field}': максимум {$rules['max']}.";
                    }
                    break;

                case 'boolean':
                    $ok = is_bool($val) || in_array($val, [0, 1, '0', '1', 'true', 'false'], true);
                    if (!$ok) $errors[] = "Поле '{$field}' должно быть логическим (true/false).";
                    break;
            }

            if (!empty($rules['enum'])) {
                $allowed = array_map('strval', (array)$rules['enum']);
                if (!in_array((string)$val, $allowed, true)) {
                    $errors[] = "Поле '{$field}': недопустимое значение.";
                }
            }
        }
        return $errors;
    }

    public static function cleanField(string $field): string
    {
        return (string)preg_replace('/[^a-zA-Z0-9_]/', '', $field);
    }

    public static function jsonExtractSql(Db $db, string $field): string
    {
        $clean = self::cleanField($field);
        if ($db->driver() === 'mysql') {
            return "JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.{$clean}'))";
        }
        return "CAST(JSON_EXTRACT(data_json, '$.{$clean}') AS TEXT)";
    }

    public static function createFieldIndex(Db $db, string $listId, string $field): void
    {
        $cleanField = self::cleanField($field);
        $safeList   = (string)preg_replace('/[^a-zA-Z0-9_]/', '', $listId);

        if ($cleanField === '' || $safeList === '') {
            throw new InvalidArgumentException('Недопустимое имя list_id или field_name.');
        }

        $idxName = "idx_ent_{$safeList}_{$cleanField}";

        if ($db->driver() === 'mysql') {
            $expr = "CAST(JSON_UNQUOTE(JSON_EXTRACT(data_json, '$.{$cleanField}')) AS CHAR(255))";
            $sql  = "CREATE INDEX {$idxName} ON entity_items(list_id, ({$expr}))";
        } else {
            $expr = "JSON_EXTRACT(data_json, '$.{$cleanField}')";
            $sql  = "CREATE INDEX IF NOT EXISTS {$idxName} ON entity_items(list_id, {$expr})";
        }

        $db->pdo->exec($sql);

        $stmt = $db->pdo->prepare("INSERT INTO entity_indexes (id, list_id, field_name, created_at)
            VALUES (?, ?, ?, ?)");
        $stmt->execute([
            'idx_' . bin2hex(random_bytes(8)),
            $listId,
            $field,
            date('Y-m-d H:i:s'),
        ]);
    }
}

// =========================================================================
// 3. АБСТРАКТНЫЙ КОНВЕЙЕР ADR
// =========================================================================

abstract class BaseAdrSlice
{
    final public function __invoke(Db $db, array $config, array $request): string
    {
        try {
            if (($request['METHOD'] ?? 'GET') === 'POST' && $this->requiresCsrf()) {
                $token = $request['POST']['csrf_token']
                      ?? $request['HEADERS']['x-csrf-token']
                      ?? null;
                if (!Csrf::verify(is_string($token) ? $token : null)) {
                    $msg = 'Ошибка безопасности: неверный или отсутствующий CSRF-токен.';
                    return self::wantsJson($request)
                        ? Json::error($msg, 403)
                        : Layout::error(403, 'Доступ запрещён', $msg);
                }
            }

            return $this->response(
                $this->domain($db, $config, $request),
                $config,
                $request
            );
        } catch (Throwable $e) {
            $msg = APP_ENV === 'dev'
                ? $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine()
                : 'Внутренняя ошибка.';
            return self::wantsJson($request)
                ? Json::error($msg, 500)
                : Layout::error(500, 'Критический сбой', $msg);
        }
    }

    abstract public function domain(Db $db, array $config, array $request): DomainResult;
    abstract public function response(DomainResult $result, array $config, array $request): string;
    abstract public function runTests(Db $db, array $config): void;

    protected function requiresCsrf(): bool { return true; }

    protected static function wantsJson(array $request): bool
    {
        return ($request['GET']['format'] ?? '') === 'json';
    }

    final protected function renderView(string $view, array $data = []): string
    {
        $data['csrf'] = $data['csrf'] ?? Csrf::token();
        $data['user'] = $data['user'] ?? Auth::user();
        return Engine::view($view, $data);
    }

    final protected function redirect(string $url): string
    {
        if (defined('TEST_MODE') && TEST_MODE) {
            return TEST_REDIRECT_PREFIX . $url;
        }
        if (!headers_sent()) header("Location: {$url}");
        exit;
    }
}

// =========================================================================
// 4. ВЕРТИКАЛЬНЫЕ СРЕЗЫ
// =========================================================================

$features = [

    // ---------------------------------------------------------------------
    // HOME
    // ---------------------------------------------------------------------
    'home' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $types = $db->pdo->query("SELECT id, name, schema_json FROM content_types ORDER BY name")
                ->fetchAll();
            foreach ($types as &$t) {
                $t['schema'] = json_decode((string)$t['schema_json'], true) ?: [];
                unset($t['schema_json']);
            }
            unset($t);

            $lists = $db->pdo->query("
                SELECT l.id, l.name, l.content_type_id, ct.name AS content_type_name,
                       (SELECT COUNT(*) FROM entity_items e WHERE e.list_id = l.id) AS item_count
                FROM entity_lists l
                LEFT JOIN content_types ct ON ct.id = l.content_type_id
                ORDER BY l.name
            ")->fetchAll();

            $indexes = $db->pdo->query("SELECT list_id, field_name FROM entity_indexes ORDER BY list_id, field_name")
                ->fetchAll();

            return DomainResult::success([
                'content_types' => $types,
                'lists'         => $lists,
                'indexes'       => $indexes,
                'user'          => Auth::user(),
            ]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            $d = $result->getData();
            if (self::wantsJson($request)) return Json::render($d);

            $content = $this->renderView('home', [
                'contentTypes' => $d['content_types'],
                'lists'        => $d['lists'],
                'indexes'      => $d['indexes'],
            ]);
            return Layout::render('No-Code Engine', $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $ok = $this->domain($t, $config, ['METHOD' => 'GET']);
            if ($ok->isFailure() || $ok->getData()['content_types'] !== []) {
                throw new RuntimeException('home: пустая БД должна возвращать пустые списки.');
            }

            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('contract', 'Договор', '{\"amount\":{\"type\":\"number\"}}', '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('legal', 'Юридические', '', 'contract', '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'legal', 'contract', '{\"amount\":100}', '2026-01-01', '2026-01-01')");

            $ok = $this->domain($t, $config, ['METHOD' => 'GET']);
            $d  = $ok->getData();
            if (count($d['content_types']) !== 1) {
                throw new RuntimeException('home: неверное число типов.');
            }
            if (count($d['lists']) !== 1 || $d['lists'][0]['item_count'] != 1) {
                throw new RuntimeException('home: неверные данные по спискам.');
            }

            $json = $this->response($ok, $config, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $dec  = json_decode($json, true);
            if (!isset($dec['content_types'][0]['schema']['amount'])) {
                throw new RuntimeException('home: JSON-ответ не содержит схему типа.');
            }

            $html = $this->response($ok, $config, ['METHOD' => 'GET', 'GET' => []]);
            if (strpos($html, 'No-Code Engine') === false) {
                throw new RuntimeException('home: HTML-ответ не содержит заголовок.');
            }

            echo "[PASS] home\n";
        }
    },

    // ---------------------------------------------------------------------
    // REGISTER
    // ---------------------------------------------------------------------
    'register' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (Auth::check()) return DomainResult::success(['status' => 'already_logged_in']);
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['status' => 'show_form']);
            }

            $email    = trim((string)($request['POST']['email'] ?? ''));
            $name     = trim((string)($request['POST']['name'] ?? ''));
            $password = (string)($request['POST']['password'] ?? '');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return DomainResult::failure('Некорректный email.');
            }
            if (mb_strlen($name) < 2) {
                return DomainResult::failure('Имя минимум 2 символа.');
            }
            if (strlen($password) < 6) {
                return DomainResult::failure('Пароль минимум 6 символов.');
            }

            $stmt = $db->pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                return DomainResult::failure('Пользователь с таким email уже зарегистрирован.');
            }

            $id   = 'usr_' . bin2hex(random_bytes(8));
            $now  = date('Y-m-d H:i:s');
            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $db->pdo->prepare("INSERT INTO users (id, email, name, password_hash, created_at)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $email, $name, $hash, $now]);

            Auth::login(['id' => $id, 'email' => $email, 'name' => $name]);
            return DomainResult::success(['status' => 'created', 'id' => $id]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            $status = $result->getData()['status'] ?? '';
            if ($status === 'created' || $status === 'already_logged_in') {
                return $this->redirect('?action=home');
            }
            $content = $this->renderView('register', [
                'error' => $result->isFailure() ? $result->getError() : null,
            ]);
            return Layout::render('Регистрация', $content);
        }

        public function runTests(Db $db, array $config): void
        {
            Auth::setMockSession([]);
            try {
                $t = Db::inMemory();

                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'not-email', 'name' => 'X', 'password' => '123456',
                ]]);
                if ($r->isSuccess()) throw new RuntimeException('register: битый email прошёл.');

                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'a@b.com', 'name' => 'Иван', 'password' => '123',
                ]]);
                if ($r->isSuccess()) throw new RuntimeException('register: короткий пароль прошёл.');

                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'a@b.com', 'name' => 'Иван', 'password' => 'secret1',
                ]]);
                if ($r->isFailure()) throw new RuntimeException('register: валидные данные отклонены: ' . $r->getError());
                if (!Auth::check()) throw new RuntimeException('register: не залогинил после регистрации.');

                Auth::logout();
                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'a@b.com', 'name' => 'Иван', 'password' => 'secret2',
                ]]);
                if ($r->isSuccess()) throw new RuntimeException('register: дубликат email прошёл.');

                Csrf::setMock('fixed_test_token');
                $html = $this->response(
                    DomainResult::success(['status' => 'show_form']),
                    $config,
                    ['METHOD' => 'GET', 'GET' => []]
                );
                if (strpos($html, 'name="csrf_token" value="fixed_test_token"') === false) {
                    throw new RuntimeException('register: HTML-форма не содержит csrf-токен.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMock(null);
            }
            echo "[PASS] register\n";
        }
    },

    // ---------------------------------------------------------------------
    // LOGIN
    // ---------------------------------------------------------------------
    'login' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (Auth::check()) return DomainResult::success(['status' => 'already_logged_in']);
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::success(['status' => 'show_form']);
            }

            $email    = trim((string)($request['POST']['email'] ?? ''));
            $password = (string)($request['POST']['password'] ?? '');

            $stmt = $db->pdo->prepare("SELECT id, email, name, password_hash FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $row = $stmt->fetch();

            if (!$row || !password_verify($password, (string)$row['password_hash'])) {
                return DomainResult::failure('Неверная пара email/пароль.');
            }

            Auth::login(['id' => $row['id'], 'email' => $row['email'], 'name' => $row['name']]);
            return DomainResult::success(['status' => 'logged_in']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 401)
                    : Json::render($result->getData());
            }
            $status = $result->getData()['status'] ?? '';
            if ($status === 'logged_in' || $status === 'already_logged_in') {
                return $this->redirect('?action=home');
            }
            $content = $this->renderView('login', [
                'error' => $result->isFailure() ? $result->getError() : null,
            ]);
            return Layout::render('Вход', $content);
        }

        public function runTests(Db $db, array $config): void
        {
            Auth::setMockSession([]);
            try {
                $t = Db::inMemory();
                $hash = password_hash('secret1', PASSWORD_DEFAULT);
                $t->pdo->exec("INSERT INTO users (id, email, name, password_hash, created_at)
                    VALUES ('u1', 'a@b.com', 'Иван', '{$hash}', '2026-01-01')");

                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'a@b.com', 'password' => 'wrong',
                ]]);
                if ($r->isSuccess()) throw new RuntimeException('login: неверный пароль прошёл.');

                $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                    'email' => 'a@b.com', 'password' => 'secret1',
                ]]);
                if ($r->isFailure() || !Auth::check()) {
                    throw new RuntimeException('login: валидные данные отклонены.');
                }
                if (Auth::id() !== 'u1') throw new RuntimeException('login: неверный пользователь в сессии.');

                $out = $this->response($r, $config, ['METHOD' => 'POST']);
                if (strpos($out, TEST_REDIRECT_PREFIX . '?action=home') === false) {
                    throw new RuntimeException('login: нет редиректа после успеха.');
                }

                Auth::logout();
                Csrf::setMock('fixed_test_token');
                $html = $this->response(
                    DomainResult::success(['status' => 'show_form']),
                    $config,
                    ['METHOD' => 'GET', 'GET' => []]
                );
                if (strpos($html, 'name="csrf_token" value="fixed_test_token"') === false) {
                    throw new RuntimeException('login: HTML-форма не содержит csrf-токен.');
                }
            } finally {
                Auth::setMockSession(null);
                Csrf::setMock(null);
            }
            echo "[PASS] login\n";
        }
    },

    // ---------------------------------------------------------------------
    // LOGOUT
    // ---------------------------------------------------------------------
    'logout' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            Auth::logout();
            return DomainResult::success();
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if ($result->isFailure()) {
                return Layout::error(405, 'Метод', $result->getError());
            }
            return $this->redirect('?action=home');
        }

        public function runTests(Db $db, array $config): void
        {
            Auth::setMockSession(['user' => ['id' => 'u1', 'name' => 'Иван']]);
            Csrf::setMock('t');
            try {
                $t = Db::inMemory();
                $out = $this($t, $config, ['METHOD' => 'POST', 'POST' => ['csrf_token' => 't']]);
                if (strpos($out, TEST_REDIRECT_PREFIX . '?action=home') === false) {
                    throw new RuntimeException('logout: нет редиректа.');
                }
                if (Auth::check()) throw new RuntimeException('logout: сессия не очищена.');
            } finally {
                Auth::setMockSession(null);
                Csrf::setMock(null);
            }
            echo "[PASS] logout\n";
        }
    },

    // ---------------------------------------------------------------------
    // CONTENT_TYPE_SAVE (API, raw JSON)
    // ---------------------------------------------------------------------
    'content_type_save' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }

            $p    = $request['POST'];
            $id   = trim((string)($p['id'] ?? ''));
            $name = trim((string)($p['name'] ?? ''));
            $schemaRaw = trim((string)($p['schema'] ?? ''));

            if ($id === '' || !preg_match('/^[a-z0-9_\-]{2,64}$/i', $id)) {
                return DomainResult::failure('id должен содержать 2-64 символа (латиница, цифры, _ -).');
            }
            if ($name === '') {
                return DomainResult::failure('Название обязательно.');
            }

            $schema = json_decode($schemaRaw, true);
            if (!is_array($schema)) {
                return DomainResult::failure('Схема должна быть валидным JSON-объектом.');
            }
            foreach ($schema as $field => $rules) {
                if (!is_array($rules) || !isset($rules['type'])) {
                    return DomainResult::failure("Поле '{$field}': должен быть объект с ключом type.");
                }
                if (!in_array($rules['type'], ['string', 'number', 'boolean'], true)) {
                    return DomainResult::failure("Поле '{$field}': недопустимый тип '{$rules['type']}'.");
                }
            }

            $now  = date('Y-m-d H:i:s');
            $stmt = $db->pdo->prepare("SELECT id FROM content_types WHERE id = ?");
            $stmt->execute([$id]);
            $exists = (bool)$stmt->fetchColumn();
            $canonicalSchema = json_encode($schema, JSON_UNESCAPED_UNICODE);

            if ($exists) {
                $stmt = $db->pdo->prepare("UPDATE content_types SET name = ?, schema_json = ? WHERE id = ?");
                $stmt->execute([$name, $canonicalSchema, $id]);
            } else {
                $stmt = $db->pdo->prepare("INSERT INTO content_types (id, name, schema_json, created_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $name, $canonicalSchema, $now]);
            }

            return DomainResult::success(['id' => $id, 'status' => $exists ? 'updated' : 'created']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(400, 'Ошибка валидации типа контента', $result->getError());
            }
            return $this->redirect('?action=home');
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'a', 'name' => 'X', 'schema' => '{}']]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_save: короткий id прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'ok', 'name' => 'X', 'schema' => '{not json}']]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_save: битый JSON прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'ok', 'name' => 'X', 'schema' => '{"f":{"type":"whatever"}}',
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_save: неверный type прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор',
                'schema' => '{"amount":{"type":"number","required":true,"min":0}}',
            ]]);
            if ($r->isFailure()) throw new RuntimeException('content_type_save: валидный тип отклонён.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор v2', 'schema' => '{"amount":{"type":"number"}}',
            ]]);
            if ($r->getData()['status'] !== 'updated') {
                throw new RuntimeException('content_type_save: повторная запись не обновила.');
            }

            echo "[PASS] content_type_save\n";
        }
    },

    // ---------------------------------------------------------------------
    // CONTENT_TYPE_LIST
    // ---------------------------------------------------------------------
    'content_type_list' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $types = $db->pdo->query("SELECT id, name, schema_json, created_at
                                      FROM content_types ORDER BY name")->fetchAll();

            $usageStmt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE content_type_id = ?");

            foreach ($types as &$t) {
                $schema = json_decode((string)$t['schema_json'], true) ?: [];
                $t['field_count']    = count($schema);
                $t['required_count'] = count(array_filter($schema, fn($r) => !empty($r['required'])));
                $usageStmt->execute([$t['id']]);
                $t['usage_count'] = (int)$usageStmt->fetchColumn();
                unset($t['schema_json']);
            }
            unset($t);

            return DomainResult::success(['types' => $types]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if ($result->isFailure()) {
                return self::wantsJson($request)
                    ? Json::error($result->getError(), 400)
                    : Layout::error(400, 'Ошибка', $result->getError());
            }
            $d = $result->getData();
            if (self::wantsJson($request)) return Json::render($d);

            $content = $this->renderView('content_type_list', ['types' => $d['types']]);
            return Layout::render('Типы контента', $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isFailure() || $r->getData()['types'] !== []) {
                throw new RuntimeException('content_type_list: пустая БД должна вернуть пустой список.');
            }

            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('contract', 'Договор',
                        '{\"amount\":{\"type\":\"number\",\"required\":true},\"client\":{\"type\":\"string\"}}',
                        '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'legal', 'contract', '{}', 'now', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            $types = $r->getData()['types'];
            if (count($types) !== 1) throw new RuntimeException('content_type_list: неверное число типов.');
            if ($types[0]['field_count'] !== 2) throw new RuntimeException('content_type_list: field_count неверный.');
            if ($types[0]['required_count'] !== 1) throw new RuntimeException('content_type_list: required_count неверный.');
            if ($types[0]['usage_count'] !== 1) throw new RuntimeException('content_type_list: usage_count неверный.');

            $json = $this->response($r, $config, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $dec = json_decode($json, true);
            if (!isset($dec['types'][0]['field_count'])) {
                throw new RuntimeException('content_type_list: JSON потерял метрики.');
            }

            echo "[PASS] content_type_list\n";
        }
    },

    // ---------------------------------------------------------------------
    // CONTENT_TYPE_EDIT
    // ---------------------------------------------------------------------
    'content_type_edit' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $method = $request['METHOD'] ?? 'GET';

            if ($method !== 'POST') {
                $id = trim((string)($request['GET']['id'] ?? ''));

                if ($id === '') {
                    return DomainResult::success([
                        'mode'   => 'create',
                        'id'     => '',
                        'name'   => '',
                        'schema' => [],
                    ]);
                }

                $stmt = $db->pdo->prepare("SELECT id, name, schema_json FROM content_types WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return DomainResult::failure('Тип контента не найден.');

                return DomainResult::success([
                    'mode'   => 'edit',
                    'id'     => $row['id'],
                    'name'   => $row['name'],
                    'schema' => json_decode((string)$row['schema_json'], true) ?: [],
                ]);
            }

            $p      = $request['POST'];
            $id     = trim((string)($p['id'] ?? ''));
            $name   = trim((string)($p['name'] ?? ''));
            $fields = $p['fields'] ?? [];
            if (!is_array($fields)) $fields = [];

            if ($id === '' || !preg_match('/^[a-z0-9_\-]{2,64}$/i', $id)) {
                return DomainResult::failure('ID: 2-64 символа, только латиница, цифры, дефис, подчёркивание.');
            }
            if ($name === '') {
                return DomainResult::failure('Название обязательно.');
            }

            $schema = [];
            foreach ($fields as $f) {
                if (!is_array($f)) continue;
                $fname = trim((string)($f['name'] ?? ''));
                if ($fname === '') continue;

                if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/i', $fname)) {
                    return DomainResult::failure("Некорректное имя поля '{$fname}': латиница, цифры, _; начинается с буквы.");
                }
                if (isset($schema[$fname])) {
                    return DomainResult::failure("Дубликат поля '{$fname}'.");
                }

                $type = (string)($f['type'] ?? 'string');
                if (!in_array($type, ['string', 'number', 'boolean'], true)) {
                    return DomainResult::failure("Недопустимый тип поля '{$fname}': {$type}.");
                }

                $rules = ['type' => $type];
                if (!empty($f['required'])) $rules['required'] = true;

                if ($type === 'number') {
                    if (isset($f['min']) && $f['min'] !== '') $rules['min'] = (float)$f['min'];
                    if (isset($f['max']) && $f['max'] !== '') $rules['max'] = (float)$f['max'];
                    if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
                        return DomainResult::failure("Поле '{$fname}': min больше max.");
                    }
                }

                if ($type === 'string') {
                    if (isset($f['min_length']) && $f['min_length'] !== '') $rules['min_length'] = (int)$f['min_length'];
                    if (isset($f['max_length']) && $f['max_length'] !== '') $rules['max_length'] = (int)$f['max_length'];
                    if (isset($rules['min_length'], $rules['max_length']) && $rules['min_length'] > $rules['max_length']) {
                        return DomainResult::failure("Поле '{$fname}': min_length больше max_length.");
                    }
                    if (isset($f['enum']) && trim((string)$f['enum']) !== '') {
                        $enum = array_map('trim', explode(',', (string)$f['enum']));
                        $enum = array_values(array_filter($enum, fn($v) => $v !== ''));
                        if ($enum) $rules['enum'] = $enum;
                    }
                }

                $schema[$fname] = $rules;
            }

            $now = date('Y-m-d H:i:s');
            $stmt = $db->pdo->prepare("SELECT id FROM content_types WHERE id = ?");
            $stmt->execute([$id]);
            $exists = (bool)$stmt->fetchColumn();
            $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE);

            if ($exists) {
                $stmt = $db->pdo->prepare("UPDATE content_types SET name = ?, schema_json = ? WHERE id = ?");
                $stmt->execute([$name, $schemaJson, $id]);
            } else {
                $stmt = $db->pdo->prepare("INSERT INTO content_types (id, name, schema_json, created_at) VALUES (?, ?, ?, ?)");
                $stmt->execute([$id, $name, $schemaJson, $now]);
            }

            return DomainResult::success([
                'id'     => $id,
                'status' => $exists ? 'updated' : 'created',
            ]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }

            if (($request['METHOD'] ?? 'GET') === 'POST') {
                if ($result->isFailure()) {
                    $id     = trim((string)($request['POST']['id'] ?? ''));
                    $name   = trim((string)($request['POST']['name'] ?? ''));
                    $fields = $request['POST']['fields'] ?? [];

                    $schema = [];
                    foreach ((array)$fields as $f) {
                        if (!is_array($f)) continue;
                        $fn = trim((string)($f['name'] ?? ''));
                        if ($fn === '') continue;
                        $rules = ['type' => (string)($f['type'] ?? 'string')];
                        if (!empty($f['required'])) $rules['required'] = true;
                        foreach (['min','max'] as $k) if (isset($f[$k]) && $f[$k] !== '') $rules[$k] = $f[$k];
                        foreach (['min_length','max_length'] as $k) if (isset($f[$k]) && $f[$k] !== '') $rules[$k] = $f[$k];
                        if (!empty($f['enum'])) {
                            $rules['enum'] = array_values(array_filter(
                                array_map('trim', explode(',', (string)$f['enum'])),
                                fn($v) => $v !== ''
                            ));
                        }
                        $schema[$fn] = $rules;
                    }

                    $content = $this->renderView('content_type_edit', [
                        'mode'   => 'edit',
                        'id'     => $id,
                        'name'   => $name,
                        'schema' => $schema,
                        'error'  => $result->getError(),
                    ]);
                    return Layout::render('Редактирование типа', $content);
                }
                return $this->redirect('?action=content_type_list');
            }

            if ($result->isFailure()) {
                return Layout::error(404, 'Не найдено', $result->getError());
            }
            $d = $result->getData();
            $content = $this->renderView('content_type_edit', [
                'mode'   => $d['mode'],
                'id'     => $d['id'],
                'name'   => $d['name'],
                'schema' => $d['schema'],
                'error'  => null,
            ]);
            $title = $d['mode'] === 'edit' ? 'Редактирование типа' : 'Новый тип контента';
            return Layout::render($title, $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isFailure() || $r->getData()['mode'] !== 'create') {
                throw new RuntimeException('content_type_edit: GET без id не вернул create-режим.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'A', 'name' => 'X', 'fields' => [],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_edit: короткий id прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => '', 'fields' => [],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_edit: пустое имя прошло.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор',
                'fields' => [['name' => '2bad', 'type' => 'string']],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_edit: невалидное имя поля прошло.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор',
                'fields' => [['name' => 'amount', 'type' => 'number', 'min' => 100, 'max' => 10]],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_edit: min>max прошло.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор',
                'fields' => [
                    ['name' => 'amount', 'type' => 'number', 'required' => '1', 'min' => 0],
                    ['name' => 'client', 'type' => 'string', 'required' => '1', 'min_length' => 3],
                    ['name' => 'status', 'type' => 'string', 'enum' => 'draft,signed,cancelled'],
                    ['name' => '', 'type' => 'string'],
                ],
            ]]);
            if ($r->isFailure()) throw new RuntimeException('content_type_edit: валидные данные отклонены: ' . $r->getError());
            if ($r->getData()['status'] !== 'created') {
                throw new RuntimeException('content_type_edit: статус не created.');
            }

            $savedJson = $t->pdo->query("SELECT schema_json FROM content_types WHERE id = 'contract'")->fetchColumn();
            $saved = json_decode((string)$savedJson, true);
            if (!isset($saved['amount']['min']) || $saved['amount']['min'] != 0) {
                throw new RuntimeException('content_type_edit: min не сохранился.');
            }
            if (($saved['status']['enum'] ?? []) !== ['draft', 'signed', 'cancelled']) {
                throw new RuntimeException('content_type_edit: enum не разобран корректно.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['id' => 'contract']]);
            if ($r->isFailure() || $r->getData()['mode'] !== 'edit') {
                throw new RuntimeException('content_type_edit: GET с id не вернул edit-режим.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'contract', 'name' => 'Договор v2',
                'fields' => [['name' => 'amount', 'type' => 'number']],
            ]]);
            if ($r->getData()['status'] !== 'updated') {
                throw new RuntimeException('content_type_edit: не обновил существующий.');
            }

            echo "[PASS] content_type_edit\n";
        }
    },

    // ---------------------------------------------------------------------
    // CONTENT_TYPE_DELETE
    // ---------------------------------------------------------------------
    'content_type_delete' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            $id = trim((string)($request['POST']['id'] ?? ''));
            if ($id === '') return DomainResult::failure('Не указан id.');

            $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE content_type_id = ?");
            $stmt->execute([$id]);
            $usage = (int)$stmt->fetchColumn();
            if ($usage > 0) {
                return DomainResult::failure("Нельзя удалить: {$usage} элементов используют этот тип.");
            }

            // Проверяем, что нет списков, привязанных к этому типу
            $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_lists WHERE content_type_id = ?");
            $stmt->execute([$id]);
            $listUsage = (int)$stmt->fetchColumn();
            if ($listUsage > 0) {
                return DomainResult::failure("Нельзя удалить: {$listUsage} списков используют этот тип.");
            }

            $stmt = $db->pdo->prepare("DELETE FROM content_types WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                return DomainResult::failure('Тип контента не найден.');
            }

            return DomainResult::success(['id' => $id, 'status' => 'deleted']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(400, 'Нельзя удалить тип', $result->getError());
            }
            return $this->redirect('?action=content_type_list');
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('empty', 'Пустой', '{}', 'now'),
                       ('used', 'Используемый', '{}', 'now'),
                       ('inlist', 'В списке', '{}', 'now')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'legal', 'used', '{}', 'now', 'now')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('legal2', 'Список', '', 'inlist', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'used']]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_delete: удалил используемый тип.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'inlist']]);
            if ($r->isSuccess()) throw new RuntimeException('content_type_delete: удалил тип, привязанный к списку.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'empty']]);
            if ($r->isFailure()) throw new RuntimeException('content_type_delete: не удалил пустой тип.');

            echo "[PASS] content_type_delete\n";
        }
    },

    // ---------------------------------------------------------------------
    // LIST_INDEX
    // ---------------------------------------------------------------------
    'list_index' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $lists = $db->pdo->query("
                SELECT l.id, l.name, l.description, l.content_type_id, l.created_at,
                       ct.name AS content_type_name
                FROM entity_lists l
                LEFT JOIN content_types ct ON ct.id = l.content_type_id
                ORDER BY l.name
            ")->fetchAll();

            $cntStmt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE list_id = ?");
            foreach ($lists as &$l) {
                $cntStmt->execute([$l['id']]);
                $l['item_count'] = (int)$cntStmt->fetchColumn();
            }
            unset($l);

            return DomainResult::success(['lists' => $lists]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if ($result->isFailure()) {
                return self::wantsJson($request)
                    ? Json::error($result->getError(), 400)
                    : Layout::error(400, 'Ошибка', $result->getError());
            }
            $d = $result->getData();
            if (self::wantsJson($request)) return Json::render($d);

            $content = $this->renderView('list_index', ['lists' => $d['lists']]);
            return Layout::render('Списки', $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isFailure() || $r->getData()['lists'] !== []) {
                throw new RuntimeException('list_index: пустая БД должна вернуть пустой список.');
            }

            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('contract', 'Договор', '{}', '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('legal', 'Юридические', 'Описание', 'contract', '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'legal', 'contract', '{}', 'now', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isFailure()) throw new RuntimeException('list_index: не отработал.');
            $lists = $r->getData()['lists'];
            if (count($lists) !== 1) throw new RuntimeException('list_index: неверное число списков.');
            if ($lists[0]['item_count'] !== 1) throw new RuntimeException('list_index: item_count неверный.');
            if ($lists[0]['content_type_name'] !== 'Договор') {
                throw new RuntimeException('list_index: не подтянулось имя типа контента.');
            }

            $json = $this->response($r, $config, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $dec = json_decode($json, true);
            if (!isset($dec['lists'][0]['item_count'])) {
                throw new RuntimeException('list_index: JSON потерял метрики.');
            }

            echo "[PASS] list_index\n";
        }
    },

    // ---------------------------------------------------------------------
    // LIST_EDIT
    // ---------------------------------------------------------------------
    'list_edit' => new class extends BaseAdrSlice {
        /** @var array<int,array<string,mixed>> */
        private array $contentTypesCache = [];

        public function domain(Db $db, array $config, array $request): DomainResult
        {
            // Кэшируем типы контента для повторного рендера при ошибке POST
            $this->contentTypesCache = $db->pdo->query(
                "SELECT id, name FROM content_types ORDER BY name"
            )->fetchAll();

            $method = $request['METHOD'] ?? 'GET';

            // ---- GET ----
            if ($method !== 'POST') {
                $id = trim((string)($request['GET']['id'] ?? ''));

                if ($id === '') {
                    return DomainResult::success([
                        'mode'            => 'create',
                        'id'              => '',
                        'name'            => '',
                        'description'     => '',
                        'content_type_id' => '',
                        'contentTypes'    => $this->contentTypesCache,
                        'hasItems'        => false,
                    ]);
                }

                $stmt = $db->pdo->prepare("SELECT id, name, description, content_type_id FROM entity_lists WHERE id = ?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if (!$row) return DomainResult::failure('Список не найден.');

                $cnt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE list_id = ?");
                $cnt->execute([$id]);

                return DomainResult::success([
                    'mode'            => 'edit',
                    'id'              => $row['id'],
                    'name'            => $row['name'],
                    'description'     => (string)$row['description'],
                    'content_type_id' => $row['content_type_id'],
                    'contentTypes'    => $this->contentTypesCache,
                    'hasItems'        => (int)$cnt->fetchColumn() > 0,
                ]);
            }

            // ---- POST ----
            $p           = $request['POST'];
            $id          = trim((string)($p['id'] ?? ''));
            $name        = trim((string)($p['name'] ?? ''));
            $description = trim((string)($p['description'] ?? ''));
            $ctId        = trim((string)($p['content_type_id'] ?? ''));

            if ($id === '' || !preg_match('/^[a-z0-9_\-]{2,64}$/i', $id)) {
                return DomainResult::failure('ID списка: 2-64 символа (латиница, цифры, _ -).');
            }
            if ($name === '') {
                return DomainResult::failure('Название обязательно.');
            }
            if ($ctId === '') {
                return DomainResult::failure('Выберите тип контента.');
            }

            $stmt = $db->pdo->prepare("SELECT id FROM content_types WHERE id = ?");
            $stmt->execute([$ctId]);
            if (!$stmt->fetchColumn()) {
                return DomainResult::failure("Тип контента '{$ctId}' не зарегистрирован.");
            }

            $stmt = $db->pdo->prepare("SELECT content_type_id FROM entity_lists WHERE id = ?");
            $stmt->execute([$id]);
            $existingCt = $stmt->fetchColumn();
            $exists = ($existingCt !== false);

            if ($exists && $existingCt !== $ctId) {
                $cnt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE list_id = ?");
                $cnt->execute([$id]);
                if ((int)$cnt->fetchColumn() > 0) {
                    return DomainResult::failure('Нельзя сменить тип контента: в списке есть элементы.');
                }
            }

            $now = date('Y-m-d H:i:s');
            if ($exists) {
                $stmt = $db->pdo->prepare("UPDATE entity_lists SET name = ?, description = ?, content_type_id = ? WHERE id = ?");
                $stmt->execute([$name, $description, $ctId, $id]);
            } else {
                $stmt = $db->pdo->prepare("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                    VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id, $name, $description, $ctId, $now]);
            }

            return DomainResult::success(['id' => $id, 'status' => $exists ? 'updated' : 'created']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }

            // POST
            if (($request['METHOD'] ?? 'GET') === 'POST') {
                if ($result->isFailure()) {
                    $p = $request['POST'];
                    $content = $this->renderView('list_edit', [
                        'mode'            => 'edit',
                        'id'              => (string)($p['id'] ?? ''),
                        'name'            => (string)($p['name'] ?? ''),
                        'description'     => (string)($p['description'] ?? ''),
                        'content_type_id' => (string)($p['content_type_id'] ?? ''),
                        'contentTypes'    => $this->contentTypesCache,
                        'hasItems'        => false,
                        'error'           => $result->getError(),
                    ]);
                    return Layout::render('Редактирование списка', $content);
                }
                return $this->redirect('?action=list_index');
            }

            // GET
            if ($result->isFailure()) {
                return Layout::error(404, 'Не найдено', $result->getError());
            }
            $d = $result->getData();
            $content = $this->renderView('list_edit', [
                'mode'            => $d['mode'],
                'id'              => $d['id'],
                'name'            => $d['name'],
                'description'     => $d['description'],
                'content_type_id' => $d['content_type_id'],
                'contentTypes'    => $d['contentTypes'],
                'hasItems'        => $d['hasItems'],
                'error'           => null,
            ]);
            $title = $d['mode'] === 'edit' ? 'Редактирование списка' : 'Новый список';
            return Layout::render($title, $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('contract', 'Договор', '{}', 'now'),
                       ('task', 'Задача', '{}', 'now')");

            // GET create
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isFailure() || $r->getData()['mode'] !== 'create') {
                throw new RuntimeException('list_edit: create-режим не работает.');
            }
            if (count($r->getData()['contentTypes']) !== 2) {
                throw new RuntimeException('list_edit: не подгрузились типы контента.');
            }

            // Валидация
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'A', 'name' => 'X', 'content_type_id' => 'contract']]);
            if ($r->isSuccess()) throw new RuntimeException('list_edit: короткий id прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'ok', 'name' => '', 'content_type_id' => 'contract']]);
            if ($r->isSuccess()) throw new RuntimeException('list_edit: пустое имя прошло.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'ok', 'name' => 'X', 'content_type_id' => 'nonexistent']]);
            if ($r->isSuccess()) throw new RuntimeException('list_edit: несуществующий тип прошёл.');

            // Happy path
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'legal', 'name' => 'Юридические', 'description' => '', 'content_type_id' => 'contract',
            ]]);
            if ($r->isFailure() || $r->getData()['status'] !== 'created') {
                throw new RuntimeException('list_edit: создание не удалось: ' . $r->getError());
            }

            // Смена типа на пустом списке — разрешена
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'legal', 'name' => 'Юридические', 'description' => '', 'content_type_id' => 'task',
            ]]);
            if ($r->isFailure()) throw new RuntimeException('list_edit: смена типа на пустом списке не удалась.');

            // Смена типа на непустом — запрещена
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'legal', 'task', '{}', 'now', 'now')");
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => 'legal', 'name' => 'Юридические', 'description' => '', 'content_type_id' => 'contract',
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('list_edit: смена типа на непустом списке прошла.');

            // GET edit с существующим id
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['id' => 'legal']]);
            if ($r->isFailure() || $r->getData()['mode'] !== 'edit' || !$r->getData()['hasItems']) {
                throw new RuntimeException('list_edit: GET edit не отработал корректно.');
            }

            echo "[PASS] list_edit\n";
        }
    },

    // ---------------------------------------------------------------------
    // LIST_DELETE
    // ---------------------------------------------------------------------
    'list_delete' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            $id = trim((string)($request['POST']['id'] ?? ''));
            if ($id === '') return DomainResult::failure('Не указан id.');

            $cnt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE list_id = ?");
            $cnt->execute([$id]);
            $n = (int)$cnt->fetchColumn();
            if ($n > 0) {
                return DomainResult::failure("Нельзя удалить: в списке {$n} элементов.");
            }

            $stmt = $db->pdo->prepare("DELETE FROM entity_lists WHERE id = ?");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                return DomainResult::failure('Список не найден.');
            }

            return DomainResult::success(['id' => $id, 'status' => 'deleted']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(400, 'Нельзя удалить список', $result->getError());
            }
            return $this->redirect('?action=list_index');
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('c', 'C', '{}', 'now')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('empty', 'Пустой', '', 'c', 'now'),
                       ('used',  'С данными', '', 'c', 'now')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'used', 'c', '{}', 'now', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'used']]);
            if ($r->isSuccess()) throw new RuntimeException('list_delete: удалил непустой список.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'empty']]);
            if ($r->isFailure()) throw new RuntimeException('list_delete: не удалил пустой список.');

            $cnt = $t->pdo->query("SELECT COUNT(*) FROM entity_lists")->fetchColumn();
            if ((int)$cnt !== 1) throw new RuntimeException('list_delete: неверное число списков.');

            echo "[PASS] list_delete\n";
        }
    },

    // ---------------------------------------------------------------------
    // SAVE_ENTITY_ITEM
    // ---------------------------------------------------------------------
    'save_entity_item' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }

            $p      = $request['POST'];
            $itemId = trim((string)($p['id'] ?? ''));
            $listId = trim((string)($p['list_id'] ?? ''));
            $fields = $p['fields'] ?? [];

            if (!is_array($fields)) {
                return DomainResult::failure('Поле fields должно быть ассоциативным массивом.');
            }
            if ($listId === '') {
                return DomainResult::failure('list_id обязателен.');
            }

            // Тип контента берётся из списка, а не из POST
            $stmt = $db->pdo->prepare("SELECT content_type_id FROM entity_lists WHERE id = ?");
            $stmt->execute([$listId]);
            $contentTypeId = $stmt->fetchColumn();
            if ($contentTypeId === false) {
                return DomainResult::failure("Список '{$listId}' не найден. Создайте его через list_edit.");
            }

            $stmt = $db->pdo->prepare("SELECT schema_json FROM content_types WHERE id = ?");
            $stmt->execute([$contentTypeId]);
            $schemaRaw = $stmt->fetchColumn();
            if ($schemaRaw === false) {
                return DomainResult::failure("Тип контента '{$contentTypeId}' не зарегистрирован.");
            }
            $schema = json_decode((string)$schemaRaw, true) ?: [];

            $errors = NoCode::validate($fields, $schema);
            if ($errors) return DomainResult::failure(implode(' ', $errors), ['fields' => $fields, 'list_id' => $listId, 'item_id' => $itemId]);

            $now   = date('Y-m-d H:i:s');
            $isNew = ($itemId === '');
            $json  = json_encode($fields, JSON_UNESCAPED_UNICODE);

            if ($isNew) {
                $itemId = 'itm_' . bin2hex(random_bytes(8));
                $stmt = $db->pdo->prepare("INSERT INTO entity_items
                    (id, list_id, content_type_id, data_json, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$itemId, $listId, $contentTypeId, $json, $now, $now]);
            } else {
                $check = $db->pdo->prepare("SELECT id FROM entity_items WHERE id = ? AND list_id = ?");
                $check->execute([$itemId, $listId]);
                if (!$check->fetchColumn()) {
                    return DomainResult::failure('Элемент не найден в указанном списке.', ['fields' => $fields, 'list_id' => $listId, 'item_id' => $itemId]);
                }
                $stmt = $db->pdo->prepare("UPDATE entity_items
                    SET data_json = ?, content_type_id = ?, updated_at = ?
                    WHERE id = ? AND list_id = ?");
                $stmt->execute([$json, $contentTypeId, $now, $itemId, $listId]);
            }

            return DomainResult::success(['id' => $itemId, 'status' => $isNew ? 'created' : 'updated']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                // При ошибке валидации возвращаем форму с данными
                $data = $result->getData();
                $fields = $data['fields'] ?? [];
                $listId = $data['list_id'] ?? '';
                $itemId = $data['item_id'] ?? '';
                return $this->redirect('?action=entity_item_edit&list_id=' . urlencode($listId) . '&item_id=' . urlencode($itemId) . '&error=' . urlencode($result->getError()));
            }
            $listId = (string)($request['POST']['list_id'] ?? '');
            return $this->redirect('?action=entity_list&list_id=' . urlencode($listId));
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('contract', 'Договор',
                        '{\"amount\":{\"type\":\"number\",\"required\":true,\"min\":0},\"client\":{\"type\":\"string\",\"required\":true}}',
                        '2026-01-01')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('legal', 'Юридические', '', 'contract', '2026-01-01')");

            // Несуществующий список
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'nonexistent',
                'fields'  => ['amount' => 100, 'client' => 'X'],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('save_entity_item: несуществующий список прошёл.');

            // Пропущено обязательное поле
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'legal',
                'fields'  => ['client' => 'ООО Тест'],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('save_entity_item: пропущено обязательное amount.');

            // Отрицательное число
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'legal',
                'fields'  => ['amount' => -1, 'client' => 'ООО Тест'],
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('save_entity_item: отрицательное amount прошло.');

            // Happy path
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'legal',
                'fields'  => ['amount' => '50000', 'client' => 'ООО Ромашка'],
            ]]);
            if ($r->isFailure()) throw new RuntimeException('save_entity_item: валидный элемент отклонён: ' . $r->getError());
            $id = $r->getData()['id'];

            $row = $t->pdo->query("SELECT data_json, content_type_id FROM entity_items WHERE id = '{$id}'")->fetch();
            $decoded = json_decode((string)$row['data_json'], true);
            if (($decoded['client'] ?? null) !== 'ООО Ромашка') {
                throw new RuntimeException('save_entity_item: JSON повреждён.');
            }
            if ($row['content_type_id'] !== 'contract') {
                throw new RuntimeException('save_entity_item: content_type_id не взят из списка.');
            }

            // Обновление
            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'id' => $id, 'list_id' => 'legal',
                'fields' => ['amount' => 60000, 'client' => 'ООО Ромашка'],
            ]]);
            if ($r->isFailure() || $r->getData()['status'] !== 'updated') {
                throw new RuntimeException('save_entity_item: обновление не удалось.');
            }

            echo "[PASS] save_entity_item\n";
        }
    },

    // ---------------------------------------------------------------------
    // ENTITY_LIST
    // ---------------------------------------------------------------------
    'entity_list' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $g = $request['GET'];
            $listId      = trim((string)($g['list_id'] ?? ''));
            $filterField = trim((string)($g['filter_field'] ?? ''));
            $filterValue = trim((string)($g['filter_value'] ?? ''));
            $limit       = max(1, min(200, (int)($g['limit'] ?? 50)));
            $offset      = max(0, (int)($g['offset'] ?? 0));

            if ($listId === '') return DomainResult::failure('Параметр list_id обязателен.');

            // Метаданные списка
            $stmt = $db->pdo->prepare("
                SELECT l.id, l.name, l.description, l.content_type_id,
                       ct.name AS content_type_name, ct.schema_json
                FROM entity_lists l
                LEFT JOIN content_types ct ON ct.id = l.content_type_id
                WHERE l.id = ?
            ");
            $stmt->execute([$listId]);
            $list = $stmt->fetch();
            if (!$list) {
                return DomainResult::failure("Список '{$listId}' не найден.");
            }
            $list['schema'] = $list['schema_json'] ? (json_decode((string)$list['schema_json'], true) ?: []) : [];
            unset($list['schema_json']);

            // Элементы
            $sql = "SELECT id, content_type_id, data_json, created_at, updated_at
                    FROM entity_items WHERE list_id = :list_id";
            $params = [':list_id' => $listId];

            if ($filterField !== '' && $filterValue !== '') {
                $expr = NoCode::jsonExtractSql($db, $filterField);
                $sql .= " AND {$expr} = :filter_value";
                $params[':filter_value'] = $filterValue;
            }

            $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";

            $stmt = $db->pdo->prepare($sql);
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();

            $items = [];
            while ($row = $stmt->fetch()) {
                $row['fields'] = json_decode((string)$row['data_json'], true) ?: [];
                unset($row['data_json']);
                $items[] = $row;
            }

            return DomainResult::success([
                'list'         => $list,
                'list_id'      => $listId,
                'items'        => $items,
                'filter_field' => $filterField,
                'filter_value' => $filterValue,
                'limit'        => $limit,
                'offset'       => $offset,
            ]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if ($result->isFailure()) {
                return self::wantsJson($request)
                    ? Json::error($result->getError(), 404)
                    : Layout::error(404, 'Список не найден', $result->getError());
            }
            $d = $result->getData();
            if (self::wantsJson($request)) return Json::render($d);

            $content = $this->renderView('entity_list', [
                'list'        => $d['list'],
                'listId'      => $d['list_id'],
                'items'       => $d['items'],
                'filterField' => $d['filter_field'],
                'filterValue' => $d['filter_value'],
            ]);
            return Layout::render($d['list']['name'], $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('todo', 'Задача', '{}', 'now')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('tasks', 'Задачи', 'Мои задачи', 'todo', 'now'),
                       ('other_list', 'Другое', '', 'todo', 'now')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES
                ('i1', 'tasks', 'todo', '{\"status\":\"new\",\"title\":\"Task 1\"}',  '2026-01-01 10:00:00', '2026-01-01 10:00:00'),
                ('i2', 'tasks', 'todo', '{\"status\":\"done\",\"title\":\"Task 2\"}', '2026-01-02 10:00:00', '2026-01-02 10:00:00'),
                ('i3', 'other_list', 'todo', '{\"status\":\"new\",\"title\":\"Other\"}', '2026-01-03 10:00:00', '2026-01-03 10:00:00')");

            // Несуществующий список
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'nonexistent']]);
            if ($r->isSuccess()) throw new RuntimeException('entity_list: несуществующий список прошёл.');

            // Обычный запрос
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'tasks']]);
            if ($r->isFailure() || count($r->getData()['items']) !== 2) {
                throw new RuntimeException('entity_list: неверное количество элементов.');
            }
            if ($r->getData()['list']['name'] !== 'Задачи') {
                throw new RuntimeException('entity_list: не подтянулось имя списка.');
            }

            // Фильтр по JSON-полю
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => [
                'list_id' => 'tasks', 'filter_field' => 'status', 'filter_value' => 'done',
            ]]);
            $items = $r->getData()['items'];
            if (count($items) !== 1 || $items[0]['id'] !== 'i2') {
                throw new RuntimeException('entity_list: фильтр по JSON-полю не сработал.');
            }

            // JSON
            $json = $this->response($r, $config, ['GET' => ['format' => 'json'], 'METHOD' => 'GET']);
            $dec  = json_decode($json, true);
            if (!isset($dec['items'][0]['fields']['title']) || !isset($dec['list']['name'])) {
                throw new RuntimeException('entity_list: JSON-ответ потерял данные.');
            }

            // Пагинация
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => [
                'list_id' => 'tasks', 'limit' => 1, 'offset' => 1,
            ]]);
            if (count($r->getData()['items']) !== 1) {
                throw new RuntimeException('entity_list: пагинация не сработала.');
            }

            // SQL-инъекция через filter_field
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => [
                'list_id' => 'tasks',
                'filter_field' => "status') OR 1=1 --",
                'filter_value' => 'done',
            ]]);
            if (count($r->getData()['items']) !== 0) {
                throw new RuntimeException('entity_list: потенциальная инъекция через filter_field.');
            }

            // csrf в форме удаления
            Csrf::setMock('fixed_test_token');
            $r2 = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'tasks']]);
            $html = $this->response($r2, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'tasks']]);
            if (strpos($html, 'name="csrf_token" value="fixed_test_token"') === false) {
                throw new RuntimeException('entity_list: форма удаления не содержит csrf-токен.');
            }
            Csrf::setMock(null);

            echo "[PASS] entity_list\n";
        }
    },

    // ---------------------------------------------------------------------
    // DELETE_ENTITY_ITEM
    // ---------------------------------------------------------------------
    'delete_entity_item' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            $id = trim((string)($request['POST']['id'] ?? ''));
            if ($id === '') return DomainResult::failure('Не указан id.');

            $stmt = $db->pdo->prepare("SELECT list_id FROM entity_items WHERE id = ?");
            $stmt->execute([$id]);
            $listId = $stmt->fetchColumn();
            if ($listId === false) return DomainResult::failure('Элемент не найден.');

            $db->pdo->prepare("DELETE FROM entity_links WHERE from_item_id = ? OR to_item_id = ?")
                ->execute([$id, $id]);
            $db->pdo->prepare("DELETE FROM entity_items WHERE id = ?")->execute([$id]);

            return DomainResult::success(['id' => $id, 'list_id' => $listId, 'status' => 'deleted']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 404)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(404, 'Не найдено', $result->getError());
            }
            $listId = (string)$result->getData()['list_id'];
            return $this->redirect('?action=entity_list&list_id=' . urlencode($listId));
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('x1', 'tasks', 'todo', '{}', 'now', 'now')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('y1', 'tasks', 'todo', '{}', 'now', 'now')");
            $t->pdo->exec("INSERT INTO entity_links (id, from_item_id, to_item_id, field_name, created_at)
                VALUES ('l1', 'x1', 'y1', 'parent', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'x1']]);
            if ($r->isFailure()) throw new RuntimeException('delete: удаление существующего не удалось.');
            if ($t->pdo->query("SELECT COUNT(*) FROM entity_links")->fetchColumn() != 0) {
                throw new RuntimeException('delete: связи не очищены каскадно.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => ['id' => 'nope']]);
            if ($r->isSuccess()) throw new RuntimeException('delete: удаление несуществующего прошло.');

            echo "[PASS] delete_entity_item\n";
        }
    },

    // ---------------------------------------------------------------------
    // ENTITY_ITEM_EDIT
    // ---------------------------------------------------------------------
    'entity_item_edit' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $method = $request['METHOD'] ?? 'GET';
            $g      = $request['GET'] ?? [];
            $p      = $request['POST'] ?? [];

            $listId  = trim((string)($g['list_id'] ?? $p['list_id'] ?? ''));
            $itemId  = trim((string)($g['item_id'] ?? $p['item_id'] ?? ''));
            $error   = (string)($g['error'] ?? '');

            if ($listId === '') {
                return DomainResult::failure('Параметр list_id обязателен.');
            }

            // Метаданные списка и схема
            $stmt = $db->pdo->prepare("
                SELECT l.id, l.name AS list_name, l.description, l.content_type_id,
                       ct.name AS content_type_name, ct.schema_json
                FROM entity_lists l
                LEFT JOIN content_types ct ON ct.id = l.content_type_id
                WHERE l.id = ?
            ");
            $stmt->execute([$listId]);
            $list = $stmt->fetch();
            if (!$list) {
                return DomainResult::failure("Список '{$listId}' не найден.");
            }
            $schema = $list['schema_json'] ? (json_decode((string)$list['schema_json'], true) ?: []) : [];
            unset($list['schema_json']);

            // Данные элемента (если редактирование)
            $fields = [];
            $isEdit = false;
            if ($itemId !== '') {
                $stmt = $db->pdo->prepare("SELECT data_json FROM entity_items WHERE id = ? AND list_id = ?");
                $stmt->execute([$itemId, $listId]);
                $row = $stmt->fetch();
                if ($row) {
                    $isEdit = true;
                    $fields = json_decode((string)$row['data_json'], true) ?: [];
                }
            }

            // Если POST — данные из формы (для возврата при ошибке валидации через save_entity_item)
            if ($method === 'POST' && isset($p['fields']) && is_array($p['fields'])) {
                $fields = $p['fields'];
            }

            return DomainResult::success([
                'list'          => $list,
                'list_id'       => $listId,
                'item_id'       => $itemId,
                'is_edit'       => $isEdit,
                'fields'        => $fields,
                'schema'        => $schema,
                'error'         => $error,
            ]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 404)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(404, 'Не найдено', $result->getError());
            }
            $d = $result->getData();
            $content = $this->renderView('entity_item_edit', [
                'list'     => $d['list'],
                'listId'   => $d['list_id'],
                'itemId'   => $d['item_id'],
                'isEdit'   => $d['is_edit'],
                'fields'   => $d['fields'],
                'schema'   => $d['schema'],
                'error'    => $d['error'],
                'csrf'     => Csrf::token(),
            ]);
            $title = ($d['is_edit'] ? 'Изменить элемент' : 'Создать элемент') . ' · ' . ($d['list']['name'] ?? $d['list_id']);
            return Layout::render($title, $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO content_types (id, name, schema_json, created_at)
                VALUES ('todo', 'Задача', '{\"title\":{\"type\":\"string\",\"required\":true},\"done\":{\"type\":\"boolean\"}}', 'now')");
            $t->pdo->exec("INSERT INTO entity_lists (id, name, description, content_type_id, created_at)
                VALUES ('tasks', 'Задачи', 'Мои задачи', 'todo', 'now')");
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('i1', 'tasks', 'todo', '{\"title\":\"Task 1\",\"done\":false}', 'now', 'now')");

            // Создание: GET форма
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'tasks']]);
            if ($r->isFailure()) throw new RuntimeException('entity_item_edit: GET для создания не удалось.');
            if ($r->getData()['is_edit'] !== false) throw new RuntimeException('entity_item_edit: is_edit должен быть false.');

            // Редактирование: GET форма
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'tasks', 'item_id' => 'i1']]);
            if ($r->isFailure()) throw new RuntimeException('entity_item_edit: GET для редактирования не удалось.');
            if ($r->getData()['is_edit'] !== true) throw new RuntimeException('entity_item_edit: is_edit должен быть true.');
            if (($r->getData()['fields']['title'] ?? null) !== 'Task 1') throw new RuntimeException('entity_item_edit: данные элемента не подтянулись.');

            // Несуществующий список
            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['list_id' => 'nonexistent']]);
            if ($r->isSuccess()) throw new RuntimeException('entity_item_edit: несуществующий список прошёл.');

            echo "[PASS] entity_item_edit\n";
        }
    },

    // ---------------------------------------------------------------------
    // CREATE_FIELD_INDEX
    // ---------------------------------------------------------------------
    'create_field_index' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            $p      = $request['POST'];
            $listId = trim((string)($p['list_id'] ?? ''));
            $field  = trim((string)($p['field_name'] ?? ''));

            if ($listId === '' || $field === '') {
                return DomainResult::failure('list_id и field_name обязательны.');
            }
            if (NoCode::cleanField($field) === '') {
                return DomainResult::failure('field_name содержит только недопустимые символы.');
            }

            $stmt = $db->pdo->prepare("SELECT id FROM entity_indexes WHERE list_id = ? AND field_name = ?");
            $stmt->execute([$listId, $field]);
            if ($stmt->fetchColumn()) {
                return DomainResult::success(['status' => 'already_exists', 'field' => $field]);
            }

            try {
                NoCode::createFieldIndex($db, $listId, $field);
            } catch (Throwable $e) {
                return DomainResult::failure('Не удалось создать индекс: ' . $e->getMessage());
            }

            return DomainResult::success(['status' => 'created', 'list_id' => $listId, 'field' => $field]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(400, 'Ошибка создания индекса', $result->getError());
            }
            return $this->redirect('?action=home');
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'tasks', 'field_name' => '!!!!',
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('create_field_index: невалидное имя поля прошло.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'tasks', 'field_name' => 'status',
            ]]);
            if ($r->isFailure() || $r->getData()['status'] !== 'created') {
                throw new RuntimeException('create_field_index: создание не удалось: ' . $r->getError());
            }

            $cnt = $t->pdo->query("SELECT COUNT(*) FROM entity_indexes WHERE list_id = 'tasks'")->fetchColumn();
            if ((int)$cnt !== 1) throw new RuntimeException('create_field_index: реестр не обновлён.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'list_id' => 'tasks', 'field_name' => 'status',
            ]]);
            if ($r->getData()['status'] !== 'already_exists') {
                throw new RuntimeException('create_field_index: дубликат не распознан.');
            }

            echo "[PASS] create_field_index\n";
        }
    },

    // ---------------------------------------------------------------------
    // ENTITY_LINK_SAVE
    // ---------------------------------------------------------------------
    'entity_link_save' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            if (($request['METHOD'] ?? 'GET') !== 'POST') {
                return DomainResult::failure('Допустим только POST.');
            }
            $p         = $request['POST'];
            $action    = (string)($p['op'] ?? 'create');
            $fromId    = trim((string)($p['from_item_id'] ?? ''));
            $toId      = trim((string)($p['to_item_id']   ?? ''));
            $fieldName = trim((string)($p['field_name']   ?? ''));

            if ($fromId === '' || $toId === '' || $fieldName === '') {
                return DomainResult::failure('from_item_id, to_item_id, field_name обязательны.');
            }
            if ($fromId === $toId) {
                return DomainResult::failure('Нельзя связать элемент с самим собой.');
            }

            $stmt = $db->pdo->prepare("SELECT COUNT(*) FROM entity_items WHERE id IN (?, ?)");
            $stmt->execute([$fromId, $toId]);
            if ((int)$stmt->fetchColumn() !== 2) {
                return DomainResult::failure('Один из элементов не найден.');
            }

            if ($action === 'delete') {
                $stmt = $db->pdo->prepare("DELETE FROM entity_links
                    WHERE from_item_id = ? AND to_item_id = ? AND field_name = ?");
                $stmt->execute([$fromId, $toId, $fieldName]);
                return DomainResult::success(['status' => 'deleted']);
            }

            $stmt = $db->pdo->prepare("SELECT id FROM entity_links
                WHERE from_item_id = ? AND to_item_id = ? AND field_name = ?");
            $stmt->execute([$fromId, $toId, $fieldName]);
            if ($stmt->fetchColumn()) {
                return DomainResult::success(['status' => 'already_exists']);
            }

            $id  = 'lnk_' . bin2hex(random_bytes(8));
            $now = date('Y-m-d H:i:s');
            $stmt = $db->pdo->prepare("INSERT INTO entity_links (id, from_item_id, to_item_id, field_name, created_at)
                VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$id, $fromId, $toId, $fieldName, $now]);

            return DomainResult::success(['id' => $id, 'status' => 'created']);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if (self::wantsJson($request)) {
                return $result->isFailure()
                    ? Json::error($result->getError(), 400)
                    : Json::render($result->getData());
            }
            if ($result->isFailure()) {
                return Layout::error(400, 'Ошибка связи', $result->getError());
            }
            $fromId = (string)($request['POST']['from_item_id'] ?? '');
            $field  = (string)($request['POST']['field_name'] ?? '');
            return $this->redirect('?action=entity_lookup&from_id=' . urlencode($fromId)
                                 . '&field_name=' . urlencode($field));
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('a', 'users', 'u', '{\"name\":\"Иван\"}', 'now', 'now'),
                       ('b', 'contracts', 'c', '{\"amount\":100}', 'now', 'now')");

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'from_item_id' => 'a', 'to_item_id' => 'b', 'field_name' => 'contracts',
            ]]);
            if ($r->isFailure() || $r->getData()['status'] !== 'created') {
                throw new RuntimeException('entity_link_save: создание не удалось.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'from_item_id' => 'a', 'to_item_id' => 'b', 'field_name' => 'contracts',
            ]]);
            if ($r->getData()['status'] !== 'already_exists') {
                throw new RuntimeException('entity_link_save: дубликат не распознан.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'from_item_id' => 'a', 'to_item_id' => 'a', 'field_name' => 'x',
            ]]);
            if ($r->isSuccess()) throw new RuntimeException('entity_link_save: self-link прошёл.');

            $r = $this->domain($t, $config, ['METHOD' => 'POST', 'POST' => [
                'op' => 'delete', 'from_item_id' => 'a', 'to_item_id' => 'b', 'field_name' => 'contracts',
            ]]);
            if ($r->isFailure()) throw new RuntimeException('entity_link_save: удаление не удалось.');
            $cnt = $t->pdo->query("SELECT COUNT(*) FROM entity_links")->fetchColumn();
            if ((int)$cnt !== 0) throw new RuntimeException('entity_link_save: связь не удалена.');

            echo "[PASS] entity_link_save\n";
        }
    },

    // ---------------------------------------------------------------------
    // ENTITY_LOOKUP
    // ---------------------------------------------------------------------
    'entity_lookup' => new class extends BaseAdrSlice {
        public function domain(Db $db, array $config, array $request): DomainResult
        {
            $g      = $request['GET'];
            $fromId = trim((string)($g['from_id'] ?? ''));
            $field  = trim((string)($g['field_name'] ?? ''));

            if ($fromId === '') return DomainResult::failure('Параметр from_id обязателен.');

            $sql = "SELECT i.id, i.list_id, i.content_type_id, i.data_json, i.created_at, i.updated_at,
                           l.field_name, l.id AS link_id
                    FROM entity_links l
                    JOIN entity_items i ON i.id = l.to_item_id
                    WHERE l.from_item_id = :from_id";
            $params = [':from_id' => $fromId];

            if ($field !== '') {
                $sql .= " AND l.field_name = :field";
                $params[':field'] = $field;
            }
            $sql .= " ORDER BY l.created_at DESC";

            $stmt = $db->pdo->prepare($sql);
            $stmt->execute($params);

            $items = [];
            while ($row = $stmt->fetch()) {
                $row['fields'] = json_decode((string)$row['data_json'], true) ?: [];
                unset($row['data_json']);
                $items[] = $row;
            }

            return DomainResult::success([
                'from_id'    => $fromId,
                'field_name' => $field,
                'items'      => $items,
            ]);
        }

        public function response(DomainResult $result, array $config, array $request): string
        {
            if ($result->isFailure()) {
                return self::wantsJson($request)
                    ? Json::error($result->getError(), 400)
                    : Layout::error(400, 'Ошибка поиска связей', $result->getError());
            }
            $d = $result->getData();
            if (self::wantsJson($request)) return Json::render($d);

            $content = $this->renderView('lookup', [
                'fromId'    => $d['from_id'],
                'fieldName' => $d['field_name'],
                'items'     => $d['items'],
            ]);
            return Layout::render('Связи: ' . $d['from_id'], $content);
        }

        public function runTests(Db $db, array $config): void
        {
            $t = Db::inMemory();
            $t->pdo->exec("INSERT INTO entity_items (id, list_id, content_type_id, data_json, created_at, updated_at)
                VALUES ('a', 'users', 'u', '{\"name\":\"Иван\"}', 'now', 'now'),
                       ('b', 'contracts', 'c', '{\"amount\":100}', 'now', 'now'),
                       ('c', 'contracts', 'c', '{\"amount\":200}', 'now', 'now')");
            $t->pdo->exec("INSERT INTO entity_links (id, from_item_id, to_item_id, field_name, created_at)
                VALUES ('l1', 'a', 'b', 'contracts', '2026-01-01 10:00:00'),
                       ('l2', 'a', 'c', 'contracts', '2026-01-02 10:00:00'),
                       ('l3', 'a', 'b', 'other',     '2026-01-03 10:00:00')");

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['from_id' => 'a']]);
            if ($r->isFailure() || count($r->getData()['items']) !== 3) {
                throw new RuntimeException('entity_lookup: неверное число связей.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['from_id' => 'a', 'field_name' => 'contracts']]);
            $items = $r->getData()['items'];
            if (count($items) !== 2) {
                throw new RuntimeException('entity_lookup: фильтр по field_name не сработал.');
            }
            if ($items[0]['id'] !== 'c') {
                throw new RuntimeException('entity_lookup: сортировка по дате связи нарушена.');
            }

            $r = $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => []]);
            if ($r->isSuccess()) throw new RuntimeException('entity_lookup: отсутствие from_id прошло.');

            $json = $this->response(
                $this->domain($t, $config, ['METHOD' => 'GET', 'GET' => ['from_id' => 'a']]),
                $config,
                ['GET' => ['format' => 'json'], 'METHOD' => 'GET']
            );
            $dec = json_decode($json, true);
            if (!isset($dec['items'][0]['fields'])) {
                throw new RuntimeException('entity_lookup: JSON потерял fields.');
            }

            echo "[PASS] entity_lookup\n";
        }
    },
];

// =========================================================================
// 5. РАНТАЙМ
// =========================================================================

try {
    $db = new Db((string)$CONFIG['db_path']);
} catch (Throwable $e) {
    http_response_code(500);
    exit('DB init failed: ' . $e->getMessage());
}

$runCi = (IS_CLI && in_array('--run-ci', $argv ?? [], true))
      || (!IS_CLI && isset($_GET['run_ci']));

if ($runCi) {
    if (!IS_CLI && APP_ENV !== 'dev') {
        http_response_code(403);
        exit('Тесты доступны только при APP_ENV=dev.');
    }
    if (!defined('TEST_MODE')) define('TEST_MODE', true);
    if (!IS_CLI) header('Content-Type: text/plain; charset=utf-8');

    echo "=== CI: NO-CODE ENGINE v" . ENGINE_VERSION . " ===\n";
    $failed = 0;
    foreach ($features as $name => $feature) {
        try {
            $feature->runTests($db, $CONFIG);
        } catch (Throwable $e) {
            $failed++;
            echo "[FAIL] {$name}: " . $e->getMessage() . "\n";
        }
    }
    echo $failed === 0
        ? "=== ВСЕ ТЕСТЫ ПРОЙДЕНЫ ===\n"
        : "=== ПРОВАЛЕНО: {$failed} ===\n";
    exit($failed === 0 ? 0 : 1);
}

if (IS_CLI) {
    fwrite(STDERR, "CLI: используйте --run-ci для запуска тестов.\n");
    exit(1);
}

$action = $_GET['action'] ?? 'home';
if (!isset($features[$action])) {
    $wantsJson = ($_GET['format'] ?? '') === 'json';
    echo $wantsJson
        ? Json::error('Страница не найдена', 404)
        : Layout::error(404, 'Страница не найдена', 'Проверьте action.');
    exit;
}

$request = [
    'METHOD'  => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'GET'     => $_GET,
    'POST'    => $_POST,
    'SERVER'  => $_SERVER,
    'HEADERS' => ['x-csrf-token' => $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null],
];

echo $features[$action]($db, $CONFIG, $request);