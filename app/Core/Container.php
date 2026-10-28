<?php

/**
 * Tourfecto - DI Container
 * حاوية حقن الاعتماديات (Dependency Injection Container)
 * @version 1.0.0
 *
 * ملاحظة معمارية مهمة: المشروع ده مبني بالكامل بأسماء كلاسات عامة (global)
 * من غير namespaces، وبيعتمد على classmap يدوي (مش composer dump-autoload
 * حقيقي وقت النشر على استضافة مشتركة). عشان كده الحاوية دي:
 *  - بتستخدم نفس أسلوب التسمية العام (بدون namespace) عشان تفضل متوافقة
 *    100% مع كل كود المشروع الحالي.
 *  - "اختيارية تمامًا" — أي كلاس/كنترولر موجود دلوقتي شغال بالظبط زي ما هو
 *    من غير ما يعرف بوجودها أصلاً. محدش مجبر يستخدمها.
 *  - بتتعمل lazy (getInstance()) فمفيش أي تغيير في نقطة الدخول index.php
 *    مطلوب عشان تشتغل.
 *
 * الاستخدام:
 *   Container::getInstance()->bind('cache', fn() => new CacheAdapter());
 *   $cache = Container::getInstance()->make('cache');
 *
 *   // أو auto-wiring لكلاس عادي (بيحل الاعتماديات من الـ constructor تلقائيًا):
 *   $service = Container::getInstance()->make(WebsiteService::class);
 */
class Container
{
    /** @var Container|null */
    private static $instance = null;

    /** @var array<string, callable> $bindings - factory لكل اسم/كلاس */
    private $bindings = [];

    /** @var array<string, bool> $sharedFlags - هل ده singleton؟ */
    private $sharedFlags = [];

    /** @var array<string, mixed> $instances - نسخ الـ singletons المحفوظة */
    private $instances = [];

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->registerCoreBindings();
        }
        return self::$instance;
    }

    /**
     * تسجيل ربط عادي (نسخة جديدة كل مرة).
     * @param string $abstract اسم منطقي أو اسم كلاس
     * @param callable|string $concrete factory function أو اسم كلاس concrete
     */
    public function bind(string $abstract, $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        $this->sharedFlags[$abstract] = false;
    }

    /**
     * تسجيل ربط Singleton (نفس النسخة في كل الطلب الحالي).
     * @param string $abstract
     * @param callable|string $concrete
     */
    public function singleton(string $abstract, $concrete): void
    {
        $this->bindings[$abstract] = $concrete;
        $this->sharedFlags[$abstract] = true;
    }

    /**
     * تسجيل نسخة جاهزة مباشرة كـ singleton (مفيد لو الكائن اتعمل قبل كده).
     */
    public function instance(string $abstract, $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->sharedFlags[$abstract] = true;
    }

    public function has(string $abstract): bool
    {
        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]) || class_exists($abstract);
    }

    /**
     * حل (resolve) الاعتمادية. لو مفيش binding مسجّل صراحة، بيحاول
     * auto-wiring عن طريق قراءة الـ constructor بالـ Reflection وحل كل
     * parameter بيه (لو type-hint بتاعه كلاس/interface معروف).
     *
     * @param string $abstract
     * @return mixed
     */
    public function make(string $abstract)
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $concrete = $this->bindings[$abstract];
            $object = is_callable($concrete) ? $concrete($this) : $this->build($concrete);
        } elseif (class_exists($abstract)) {
            $object = $this->build($abstract);
        } else {
            throw new Exception("Container: لا يوجد binding ولا كلاس باسم '{$abstract}'");
        }

        if (!empty($this->sharedFlags[$abstract])) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * بناء كائن كلاس عادي مع auto-wiring لمعاملات الـ constructor.
     * @param string $class
     * @return object
     */
    private function build(string $class)
    {
        if (!class_exists($class)) {
            throw new Exception("Container: الكلاس '{$class}' غير موجود");
        }

        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new Exception("Container: الكلاس '{$class}' غير قابل للإنشاء (interface/abstract)");
        }

        $constructor = $reflector->getConstructor();
        if (!$constructor || $constructor->getNumberOfParameters() === 0) {
            return new $class();
        }

        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if ($type && !$type->isBuiltin()) {
                $typeName = $type->getName();
                $params[] = $this->make($typeName);
            } elseif ($param->isDefaultValueAvailable()) {
                $params[] = $param->getDefaultValue();
            } else {
                // معرفناش نحل الـ parameter ده تلقائيًا (نوع بدائي زي string/int
                // من غير قيمة افتراضية) - نديله null ونسيب الكلاس نفسه يتعامل
                $params[] = null;
            }
        }

        return $reflector->newInstanceArgs($params);
    }

    /**
     * ربط الخدمات الأساسية الجاهزة أصلاً في المشروع (Cache/Logger/Database)
     * خلف الـ interfaces الجديدة، من غير ما نغيّر حرف واحد في الكلاسات
     * القديمة نفسها (Adapter Pattern).
     */
    private function registerCoreBindings(): void
    {
        $this->singleton('db', function () {
            return Database::getInstance();
        });

        if (class_exists('CacheAdapter')) {
            $this->singleton(CacheInterface::class, function () {
                return new CacheAdapter();
            });
        }

        if (class_exists('LoggerAdapter')) {
            $this->singleton(LoggerInterface::class, function () {
                return new LoggerAdapter();
            });
        }

        if (class_exists('EventDispatcher')) {
            $this->singleton(EventDispatcher::class, function () {
                return new EventDispatcher();
            });
        }

        if (class_exists('QueueManager')) {
            $this->singleton(QueueManager::class, function () {
                return new QueueManager();
            });
        }
    }

    /**
     * إعادة الضبط الكامل (مفيد في الاختبارات فقط - PHPUnit).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
