<?php
/** Builds a site-bound connector with locally shipped, reviewed modules. */
final class TourfectoPluginBundle
{
    public static function files(int $websiteId, string $endpoint, string $host): array
    {
        $base = EdgeRules::pluginFiles($websiteId, $endpoint, $host);
        $main = str_replace(['Plugin Name: Tourfecto SEO', 'Version: 2.0.0', 'Requires PHP: 7.2'], ['Plugin Name: Tourfecto Connect', 'Version: 3.7.1', 'Requires PHP: 8.1'], $base['php']);
        // Reviewed connector v4 bridge. Fail closed if the underlying runtime shape changed.
        $bridges=[
            "define( 'TF_EDGE_RUNTIME_V4', true );"=>"define( 'TF_EDGE_RUNTIME_V4', true );\ndefine( 'TF_NATIVE_HEAD_BRIDGE', true );",
            '        return $page;'=>"        return apply_filters( 'tourfecto_page_rules', \$page );",
            'if ( ! tf_edge_has_page_rules() || empty( $rules ) ) { return $head; }'=>"if ( ! tf_edge_has_page_rules() || empty( \$rules ) ) { return apply_filters( 'tourfecto_normalized_head', \$head ); }",
            'return strtr( $head, $protected ) . $extra;'=>"return apply_filters( 'tourfecto_normalized_head', strtr( \$head, \$protected ) . \$extra );"
        ];
        $bridges['function tf_edge_has_page_rules() { return tf_edge_is_home() || count(tf_edge_page_rules())>0; }']="function tf_edge_has_page_rules() { return apply_filters( 'tourfecto_has_page_rules', tf_edge_is_home() || count(tf_edge_page_rules())>0 ); }";
        foreach($bridges as $old=>$new){if(substr_count($main,$old)!==1)throw new RuntimeException('Native SEO bridge requires the reviewed connector v4 runtime.');$main=str_replace($old,$new,$main);}
        $bootstrap = "\nrequire_once __DIR__ . '/modules/bootstrap.php';\n";
        $marker = "if ( ! defined( 'ABSPATH' ) ) { exit; }";
        if (substr_count($main, $marker) !== 1) throw new RuntimeException('Connector bootstrap unavailable.');
        $main = str_replace($marker, $marker . $bootstrap, $main);
        $files = ['tourfecto-seo/tourfecto-seo.php'=>$main,
            'tourfecto-seo/readme.txt'=>"=== Tourfecto Connect ===\nStable tag: 3.7.1\nRequires PHP: 8.1\nLicense: GPLv2 or later\n\nIncludes the site-bound SEO connector, guarded original-page editor, Traveler 3.4 text adapter, and optional WooCommerce measurement. WordPress publishing/editor authentication and measurement configuration remain explicit separate permissions. No content is changed and no orders are sent merely by installation. Replace the existing Tourfecto SEO plugin using this archive; the plugin path and approved-rule cache remain compatible. Deactivate standalone Tourfecto Page Editor and Tourfecto Measure before activation; their saved settings and history are retained. Disable legacy connector snippets. Google measurement connections remain in the platform.\n"];
        foreach (['tourfecto-international.php','tourfecto-content-seo.php','bootstrap.php','tourfecto-page-editor.php','tourfecto-block-text.php','tourfecto-traveler.php','tourfecto-measure.php','tourfecto-tour-draft.php','tourfecto-tour-settings.php','tourfecto-native-seo.php','tourfecto-tour-calendar.php'] as $name) {
            $path=__DIR__.'/WordPressBundle/'.$name;
            $bytes=@file_get_contents($path);
            if ($bytes===false || $bytes==='') throw new RuntimeException('Unified plugin module unavailable.');
            $files['tourfecto-seo/modules/'.$name]=$bytes;
        }
        return $files;
    }
}
