<?php
/** Front-controller transport for API paths intercepted by a hosting rewrite rule.
 * Only the URL is normalized: method, body, authentication and middleware are unchanged.
 */
final class ApiRouteBridge
{
    public static function allowed(string $path): bool {
        return (bool)preg_match('~^/api/[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*$~D',$path);
    }
    public static function normalize(array &$server,array &$query): bool {
        $path=parse_url($server['REQUEST_URI']??'/',PHP_URL_PATH);
        if($path!=='/index.php'||!isset($query['__tf_api'])||!is_string($query['__tf_api'])||!self::allowed($query['__tf_api']))return false;
        $route=$query['__tf_api'];unset($query['__tf_api']);
        $server['QUERY_STRING']=http_build_query($query);
        $server['REQUEST_URI']=$route.($server['QUERY_STRING']!==''?'?'.$server['QUERY_STRING']:'');
        return true;
    }
}
