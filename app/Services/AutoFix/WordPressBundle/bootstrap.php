<?php
if (!defined('ABSPATH')) exit;
// Wait until all active plugins have loaded; standalone modules must not register twice.
add_action('plugins_loaded', static function (): void {
    foreach (['TourfectoPageEditor'=>'tourfecto-page-editor.php', 'TourfectoMeasure'=>'tourfecto-measure.php'] as $class=>$file) {
        if (!class_exists($class, false)) require_once __DIR__.'/'.$file;
        else add_action('admin_notices', static function (): void {
            if (current_user_can('manage_options')) echo '<div class="notice notice-warning"><p>Tourfecto Connect: deactivate standalone Tourfecto Page Editor and Tourfecto Measure to use the bundled versions. Saved settings and history are preserved.</p></div>';
        });
    }
}, 20);

require_once __DIR__.'/tourfecto-international.php';
