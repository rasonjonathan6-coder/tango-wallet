<?php
/**
 * Compatibilite SQL : l'application est ecrite pour MySQL. Ce module permet
 * de la faire tourner sur PostgreSQL (Supabase, Render, Neon, ...) sans
 * modifier les 200+ requetes reparties dans le code.
 *
 * Principe : la classe WalletPDO herite de PDO et traduit les rares
 * constructions specifiques a MySQL (INSERT IGNORE, ON DUPLICATE KEY UPDATE,
 * VALUES(col)) au moment du prepare()/query(). Le reste du code, qui consomme
 * l'objet $pdo normalement, n'a rien a changer.
 *
 * En MySQL (pilote par defaut) aucune traduction n'est faite : le code garde
 * exactement son comportement historique.
 */

class WalletPDO extends PDO
{
    private bool $translate = false;

    public function __construct(string $dsn, ?string $user = null, ?string $pass = null, array $options = [], bool $translate = false)
    {
        parent::__construct($dsn, $user, $pass, $options);
        $this->translate = $translate;
        if ($this->translate) {
            parent::exec("SET TIME ZONE 'UTC'");
        }
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if ($this->translate) {
            $query = wallet_sql_translate($query);
        }
        return parent::prepare($query, $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
    {
        if ($this->translate) {
            $query = wallet_sql_translate($query);
        }
        if ($fetchMode === null) {
            return parent::query($query);
        }
        return parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

/**
 * Traduit une requete MySQL vers PostgreSQL. Sans effet sur les requetes
 * portables (la tres grande majorite).
 */
function wallet_sql_translate(string $sql): string
{
    // Les CREATE TABLE en syntaxe MySQL (ENGINE=InnoDB, KEY, AUTO_INCREMENT...)
    // sont neutralises : sous PostgreSQL le schema est cree par schema_pg.sql
    // (importe automatiquement a la premiere connexion). Ces instructions
    // n'apportent donc rien et feraient echouer la requete.
    if (preg_match('/^\s*CREATE\s+TABLE\b/i', $sql)) {
        return 'SELECT 1';
    }

    // INSERT IGNORE INTO ... -> INSERT INTO ... ON CONFLICT DO NOTHING
    if (preg_match('/\bINSERT\s+IGNORE\s+INTO\b/i', $sql)) {
        $sql = preg_replace('/\bINSERT\s+IGNORE\s+INTO\b/i', 'INSERT INTO', $sql);
        return rtrim(rtrim($sql, " \t\n\r;")) . ' ON CONFLICT DO NOTHING';
    }

    // ... ON DUPLICATE KEY UPDATE col = VALUES(col) -> ... ON CONFLICT (cle) DO UPDATE SET col = EXCLUDED.col
    if (preg_match('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $sql)) {
        // PostgreSQL exige de preciser la contrainte : on utilise la premiere
        // colonne de l'INSERT, qui correspond a la cle primaire dans les UPSERT
        // de l'application (settings.setting_key, bep20_scan_state.adresse).
        $target = 'ON CONFLICT';
        if (preg_match('/\bINSERT\s+INTO\s+[a-zA-Z0-9_]+\s*\(\s*([a-zA-Z0-9_]+)/i', $sql, $m)) {
            $target = 'ON CONFLICT (' . $m[1] . ')';
        }
        $sql = preg_replace('/\bON\s+DUPLICATE\s+KEY\s+UPDATE\b/i', $target . ' DO UPDATE SET', $sql);
        $sql = preg_replace('/\bVALUES\s*\(\s*([a-zA-Z0-9_]+)\s*\)/i', 'EXCLUDED.$1', $sql);
        return $sql;
    }

    return $sql;
}

/**
 * Importe le schema PostgreSQL (schema_pg.sql) si la base est vide.
 * Rend le deploiement autonome : aucune etape manuelle d'import.
 */
function wallet_import_pg_schema_if_needed(PDO $pdo): void
{
    $existe = $pdo->query("SELECT to_regclass('public.users')")->fetchColumn();
    if ($existe) {
        return;
    }

    $fichier = __DIR__ . '/../schema_pg.sql';
    if (!is_file($fichier)) {
        return;
    }

    $pdo->exec(file_get_contents($fichier));
}

/**
 * Ouvre une connexion PDO. Le pilote est deduit de $driver ("mysql" par
 * defaut, "pgsql" pour PostgreSQL).
 */
function wallet_db_connect(string $driver, string $host, string $port, string $name, string $user, string $pass): PDO
{
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    if ($driver === 'pgsql') {
        $dsn = "pgsql:host=$host;port=$port;dbname=$name";
        $pdo = new WalletPDO($dsn, $user, $pass, $options, true);
        wallet_import_pg_schema_if_needed($pdo);
        return $pdo;
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
    return new WalletPDO($dsn, $user, $pass, $options, false);
}
