<?php

/**
 * Tourfecto - Router Class
 * معالج مسارات متقدم مع دعم RESTful API
 * @version 1.0.0
 * @author Tourfecto Team
 * @copyright 2026 Tourfecto
 */

class Router
{
    /**
     * @var array $routes - قائمة المسارات
     */
    private $routes = [];

    /**
     * @var array $middleware - قائمة الوسائط
     */
    private $middleware = [];

    /**
     * @var array $groups - مجموعات المسارات
     */
    private $groups = [];

    /**
     * @var string $currentGroup - المجموعة الحالية
     */
    private $currentGroup = '';

    /**
     * @var array $routeParams - معاملات المسار المستخرجة
     */
    private $routeParams = [];

    /**
     * إضافة مسار جديد
     * @param string $method - طريقة HTTP (GET, POST, PUT, DELETE, OPTIONS)
     * @param string $path - مسار الطلب
     * @param string $controller - اسم المتحكم
     * @param string $action - اسم الدالة
     * @param array $middleware - وسائط للمسار
     * @return Router
     */
    public function add(
        string $method,
        string $path,
        string $controller,
        string $action,
        array $middleware = []
    ): self {
        $path = $this->currentGroup . $path;

        // تحويل مسار مثل /users/{id} إلى نمط regex
        $pattern = $this->compileRoutePattern($path);

        $this->routes[$method][] = [
            'path' => $path,
            'pattern' => $pattern,
            'controller' => $controller,
            'action' => $action,
            'middleware' => $middleware,
            'group' => $this->currentGroup
        ];

        return $this;
    }

    /**
     * إضافة مسار GET
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function get(string $path, string $controller, string $action, array $middleware = []): self
    {
        return $this->add('GET', $path, $controller, $action, $middleware);
    }

    /**
     * إضافة مسار POST
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function post(string $path, string $controller, string $action, array $middleware = []): self
    {
        return $this->add('POST', $path, $controller, $action, $middleware);
    }

    /**
     * إضافة مسار يستجيب لأي HTTP method
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function any(string $path, string $controller, string $action, array $middleware = []): self
    {
        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $method) {
            $this->add($method, $path, $controller, $action, $middleware);
        }
        return $this;
    }

    /**
     * إضافة مسار PUT
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function put(string $path, string $controller, string $action, array $middleware = []): self
    {
        return $this->add('PUT', $path, $controller, $action, $middleware);
    }

    /**
     * إضافة مسار DELETE
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function delete(string $path, string $controller, string $action, array $middleware = []): self
    {
        return $this->add('DELETE', $path, $controller, $action, $middleware);
    }

    /**
     * إضافة مسار PATCH
     * @param string $path
     * @param string $controller
     * @param string $action
     * @param array $middleware
     * @return Router
     */
    public function patch(string $path, string $controller, string $action, array $middleware = []): self
    {
        return $this->add('PATCH', $path, $controller, $action, $middleware);
    }

    /**
     * إضافة مسار OPTIONS
     * @param string $path
     * @return Router
     */
    public function options(string $path): self
    {
        return $this->add('OPTIONS', $path, '', '');
    }

    /**
     * إضافة مجموعة مسارات
     * @param string $prefix
     * @param callable $callback
     * @param array $middleware
     * @return Router
     */
    public function group(string $prefix, callable $callback, array $middleware = []): self
    {
        $oldGroup = $this->currentGroup;
        $this->currentGroup = $prefix;

        // تسجيل المجموعة
        $this->groups[$prefix] = [
            'middleware' => $middleware,
            'routes' => []
        ];

        // تنفيذ callback
        $callback($this);

        $this->currentGroup = $oldGroup;
        return $this;
    }

    /**
     * توجيه الطلب
     * @param string $method
     * @param string $path
     * @return array
     */
    public function dispatch(string $method, string $path): array
    {
        // معالجة طلبات OPTIONS
        if ($method === 'OPTIONS') {
            return ['success' => true, 'message' => 'OK'];
        }

        // البحث عن المسار
        if (!isset($this->routes[$method])) {
            throw new Exception('Method not allowed', 405);
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // استخراج المعاملات
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_numeric($key)) {
                        $params[$key] = $value;
                    }
                }

                // تنفيذ الوسائط
                $middlewareResult = $this->executeMiddleware($route['middleware']);
                if ($middlewareResult !== null) {
                    return $middlewareResult;
                }

                // إنشاء كائن المتحكم
                $controllerClass = $route['controller'];
                if (!class_exists($controllerClass)) {
                    throw new Exception("Controller {$controllerClass} not found", 500);
                }

                $controller = new $controllerClass();
                $action = $route['action'];

                if (!method_exists($controller, $action)) {
                    throw new Exception("Action {$action} not found in {$controllerClass}", 500);
                }

                // تنفيذ الدالة مع المعاملات
                $result = $controller->$action($params);
                // كنترولرات صفحات كتير بتعمل echo للـ HTML بنفسها ومابترجعش حاجة
                // (void) - كان ده بيرمي TypeError بعد ما الصفحة اتعرضت. استجابة
                // فاضية هنا بتسيب الصفحة زي ما هي.
                return is_array($result) ? $result : ['_raw_text' => ''];
            }
        }

        throw new Exception('Route not found', 404);
    }

    /**
     * تحويل مسار إلى نمط Regex
     * @param string $path
     * @return string
     */
    private function compileRoutePattern(string $path): string
    {
        // تحويل {parameter} إلى (?P<parameter>[^/]+)
        $pattern = preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            '(?P<$1>[^/]+)',
            $path
        );

        // تحويل {parameter:type} إلى نمط محدد
        $pattern = preg_replace(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*):([a-zA-Z]+)\}/',
            '(?P<$1>' . $this->getTypePattern('$2') . ')',
            $pattern
        );

        return '#^' . $pattern . '$#';
    }

    /**
     * الحصول على نمط النوع
     * @param string $type
     * @return string
     */
    private function getTypePattern(string $type): string
    {
        switch ($type) {
            case 'int':
                return '[0-9]+';
            case 'float':
                return '[0-9.]+';
            case 'string':
                return '[^/]+';
            case 'uuid':
                return '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
            case 'slug':
                return '[a-z0-9-]+';
            default:
                return '[^/]+';
        }
    }

    /**
     * تنفيذ الوسائط
     * @param array $middleware
     * @return array|null
     */
    private function executeMiddleware(array $middleware): ?array
    {
        foreach ($middleware as $middlewareEntry) {
            // دعم صيغة 'ClassName:modifier' زي 'SubscriptionMiddleware:require_ai_credits'
            $middlewareClass = $middlewareEntry;
            $modifier = null;
            if (strpos($middlewareEntry, ':') !== false) {
                [$middlewareClass, $modifier] = explode(':', $middlewareEntry, 2);
            }

            if (!class_exists($middlewareClass)) {
                continue;
            }

            $middlewareObj = new $middlewareClass();

            if ($modifier !== null && method_exists($middlewareObj, 'applyModifier')) {
                $middlewareObj->applyModifier($modifier);
            }

            $result = $middlewareObj->handle();

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * الحصول على جميع المسارات
     * @return array
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * توليد URL من اسم المسار
     * @param string $name
     * @param array $params
     * @return string
     */
    public function url(string $name, array $params = []): string
    {
        // البحث عن المسار بالاسم
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if (isset($route['name']) && $route['name'] === $name) {
                    $path = $route['path'];

                    // استبدال المعاملات
                    foreach ($params as $key => $value) {
                        $path = str_replace('{' . $key . '}', $value, $path);
                    }

                    return $path;
                }
            }
        }

        throw new Exception("Route '{$name}' not found", 404);
    }
}
