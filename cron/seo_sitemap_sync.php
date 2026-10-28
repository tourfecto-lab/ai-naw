<?php
require_once __DIR__.'/bootstrap.php';
try { $n=(new SitemapSubmissionService(Database::getInstance()))->daily();echo 'Sitemap connections checked: '.$n.PHP_EOL; }
catch(Throwable $e){Logger::warning('Sitemap sync requires Phase 6 migration');exit(1);}
