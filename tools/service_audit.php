<?php
/** Read-only CLI audit. Structural checks are not proof of a successful live integration. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
define('ROOT_PATH', $root); define('APP_PATH', $root . '/app');
require APP_PATH . '/Config/runtime_requirements.php';
tourfecto_register_fallback_autoloader();
require_once APP_PATH . '/Core/Router.php';
$router = new Router;
require APP_PATH . '/routes/web.php'; require APP_PATH . '/routes/api.php';
$routes = $router->getRoutes();
$errors = []; $count = 0; $duplicates = []; $seen = [];
foreach ($routes as $method => $entries) {
    foreach ($entries as $i => $route) {
        $count++;
        try {
            if (!class_exists($route['controller']) || !method_exists($route['controller'], $route['action'])) {
                $errors[] = "$method {$route['path']}: missing controller/action";
            } elseif (!(new ReflectionMethod($route['controller'], $route['action']))->isPublic()) {
                $errors[] = "$method {$route['path']}: action is not public";
            }
        } catch (Throwable $e) { $errors[] = "$method {$route['path']}: controller loading failed"; }
        $key = $method . ' ' . $route['path'];
        if (isset($seen[$key])) { $duplicates[] = $key; } $seen[$key] = true;
        if (!str_contains($route['path'], '{')) {
            foreach (array_slice($entries, 0, $i) as $previous) {
                if (str_contains($previous['path'], '{') && preg_match($previous['pattern'], $route['path'])) {
                    $errors[] = "$key: shadowed by {$previous['path']}";
                }
            }
        }
    }
}
$modules = [
    'bookings' => ['Bookings & availability', ['BookingController'], ['bookings', 'inventory', 'booking_status_history']],
    'payments' => ['Payment links', ['PaymentLinkController'], ['bookings']],
    'website' => ['Booking website', ['WebsiteBuilderController'], ['websites', 'crm_products']],
    'chat' => ['AI chat & inbox', ['ChatController'], ['chat_messages']],
    'automation' => ['Automation', ['AutomationController'], ['jobs']],
    'reviews' => ['Reviews & reputation', ['ReputationController'], ['reviews', 'platform_connections']],
    'gbp' => ['Google Business Profile', ['GoogleBusinessContentController', 'GbpProfileController'], ['platform_connections']],
    'search_console' => ['Search Console', ['SearchConsoleController'], ['websites']],
    'analytics' => ['Google Analytics', ['GoogleAnalyticsController'], ['websites']],
    'seo' => ['Auto SEO & website optimization', ['AutoSeoController', 'WebsiteOptimizerController'], ['websites']],
    'content' => ['SEO content', ['SeoContentController'], ['websites']],
    'competitors' => ['Competitor intelligence', ['CompetitorIntelligenceController'], ['websites']],
    'social' => ['Social publishing', ['SocialMediaController'], ['social_posts']],
    'creative' => ['Creative Studio', ['CreativeStudioController'], ['jobs']],
    'ads' => ['Advertising', ['AdsController', 'AdAbTestController'], ['ad_campaigns']],
    'email' => ['Email marketing', ['EmailMarketingController'], ['email_campaigns']],
    'crm' => ['CRM', ['CrmController'], ['crm_contacts']],
    'revenue' => ['Revenue intelligence', ['RevenueController', 'RevenueIntelligenceController'], ['rev_revenue_records']],
    'agency' => ['Agency & teams', ['AgencyController', 'WorkspaceController'], ['agencies', 'users']],
    'billing' => ['Subscriptions & entitlements', ['SubscriptionController'], ['subscriptions', 'subscription_plans']],
];
$pdo = null; $dbRequested = in_array('--database', $argv, true); $dbError = null;
if ($dbRequested) {
    try {
        require_once $root . '/vendor/autoload.php';
        if (class_exists('Dotenv\\Dotenv')) { Dotenv\Dotenv::createImmutable($root)->safeLoad(); }
        $readEnv = static fn (string $key, string $fallback = ''): string => (string) ($_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) === false ? $fallback : getenv($key)));
        $dsn = 'mysql:host=' . $readEnv('DB_HOST', '127.0.0.1') . ';port=' . $readEnv('DB_PORT', '3306') . ';dbname=' . $readEnv('DB_NAME') . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $readEnv('DB_USER'), $readEnv('DB_PASS'), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>5]);
    } catch (Throwable $e) { $dbError = 'Database check unavailable; verify local .env and pdo_mysql. No credentials are included in this report.'; }
}
$tables = null;
if ($pdo) {
    try { $tables = $pdo->query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_COLUMN); }
    catch (Throwable $e) { $dbError = 'Unable to read database metadata.'; }
}
$moduleResults = [];
foreach ($modules as $key => [$title, $controllers, $requiredTables]) {
    $missingCode = [];
    foreach ($controllers as $controller) { if (!class_exists($controller)) { $missingCode[] = $controller; } }
    $moduleResults[$key] = ['title'=>$title, 'missing_controllers'=>$missingCode,
        'missing_core_tables'=>$tables === null ? null : array_values(array_diff($requiredTables, $tables)),
        'live_test'=>'not_run'];
}
$report = ['generated_at'=>date('c'), 'php_version'=>PHP_VERSION,
    'missing_extensions'=>array_keys(tourfecto_missing_runtime_extensions()),
    'route_count'=>$count, 'route_errors'=>$errors, 'duplicate_routes'=>$duplicates,
    'database_requested'=>$dbRequested, 'database_error'=>$dbError, 'modules'=>$moduleResults,
    'note'=>'Structural and optional read-only schema checks only. OAuth, publishing, payments and delivery require live tests.'];
if (in_array('--json', $argv, true)) { echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL; }
else {
    echo "Tourfecto service audit (read-only)\nRoutes: $count; route errors: " . count($errors) . "; duplicate registrations: " . count($duplicates) . "\n";
    foreach ($moduleResults as $module) {
        $local = $module['missing_controllers'] ? 'MISSING CODE' : 'STRUCTURE FOUND';
        $db = $module['missing_core_tables'] === null ? 'DB not checked' : ($module['missing_core_tables'] ? 'missing tables: ' . implode(', ', $module['missing_core_tables']) : 'core tables found');
        echo "[$local] {$module['title']} — $db — live test pending\n";
    }
    foreach ($errors as $error) { echo "ERROR $error\n"; }
    if ($dbError) { echo "$dbError\n"; }
}
$moduleFailed = false;
foreach ($moduleResults as $module) { if ($module['missing_controllers'] || $module['missing_core_tables']) { $moduleFailed = true; } }
exit($errors || $moduleFailed || $dbError || tourfecto_missing_runtime_extensions() ? 1 : 0);
