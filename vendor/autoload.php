<?php
$baseDir = __DIR__;
$files = [
    $baseDir . '/twig/twig/src/Resources/core.php',
    $baseDir . '/twig/twig/src/Resources/debug.php',
    $baseDir . '/twig/twig/src/Resources/escaper.php',
    $baseDir . '/twig/twig/src/Resources/string_loader.php',
    $baseDir . '/symfony/deprecation-contracts/function.php',
    $baseDir . '/symfony/polyfill-mbstring/bootstrap.php',
    $baseDir . '/symfony/polyfill-ctype/bootstrap.php',
    $baseDir . '/symfony/polyfill-php80/bootstrap.php',
];
foreach ($files as $file) { if (is_file($file)) { require_once $file; } }
$classmap = [
    'Attribute' => $baseDir . '/symfony/polyfill-php80/Resources/stubs/Attribute.php',
    'Nette\\ArgumentOutOfRangeException' => $baseDir . '/nette/utils/src/exceptions.php',
    'Nette\\HtmlStringable' => $baseDir . '/nette/utils/src/HtmlStringable.php',
    'Nette\\Iterators\\CachingIterator' => $baseDir . '/nette/utils/src/Iterators/CachingIterator.php',
    'Nette\\Iterators\\Mapper' => $baseDir . '/nette/utils/src/Iterators/Mapper.php',
    'Nette\\Localization\\Translator' => $baseDir . '/nette/utils/src/Translator.php',
    'Nette\\Schema\\Context' => $baseDir . '/nette/schema/src/Schema/Context.php',
    'Nette\\Schema\\DynamicParameter' => $baseDir . '/nette/schema/src/Schema/DynamicParameter.php',
    'Nette\\Schema\\Elements\\AnyOf' => $baseDir . '/nette/schema/src/Schema/Elements/AnyOf.php',
    'Nette\\Schema\\Elements\\Base' => $baseDir . '/nette/schema/src/Schema/Elements/Base.php',
    'Nette\\Schema\\Elements\\Structure' => $baseDir . '/nette/schema/src/Schema/Elements/Structure.php',
    'Nette\\Schema\\Elements\\Type' => $baseDir . '/nette/schema/src/Schema/Elements/Type.php',
    'Nette\\Schema\\Expect' => $baseDir . '/nette/schema/src/Schema/Expect.php',
    'Nette\\Schema\\Helpers' => $baseDir . '/nette/schema/src/Schema/Helpers.php',
    'Nette\\Schema\\Message' => $baseDir . '/nette/schema/src/Schema/Message.php',
    'Nette\\Schema\\Processor' => $baseDir . '/nette/schema/src/Schema/Processor.php',
    'Nette\\Schema\\Schema' => $baseDir . '/nette/schema/src/Schema/Schema.php',
    'Nette\\Schema\\ValidationException' => $baseDir . '/nette/schema/src/Schema/ValidationException.php',
    'Nette\\SmartObject' => $baseDir . '/nette/utils/src/SmartObject.php',
    'Nette\\StaticClass' => $baseDir . '/nette/utils/src/StaticClass.php',
    'Nette\\Utils\\ArrayHash' => $baseDir . '/nette/utils/src/Utils/ArrayHash.php',
    'Nette\\Utils\\ArrayList' => $baseDir . '/nette/utils/src/Utils/ArrayList.php',
    'Nette\\Utils\\Arrays' => $baseDir . '/nette/utils/src/Utils/Arrays.php',
    'Nette\\Utils\\Callback' => $baseDir . '/nette/utils/src/Utils/Callback.php',
    'Nette\\Utils\\DateTime' => $baseDir . '/nette/utils/src/Utils/DateTime.php',
    'Nette\\Utils\\FileInfo' => $baseDir . '/nette/utils/src/Utils/FileInfo.php',
    'Nette\\Utils\\FileSystem' => $baseDir . '/nette/utils/src/Utils/FileSystem.php',
    'Nette\\Utils\\Finder' => $baseDir . '/nette/utils/src/Utils/Finder.php',
    'Nette\\Utils\\Floats' => $baseDir . '/nette/utils/src/Utils/Floats.php',
    'Nette\\Utils\\Helpers' => $baseDir . '/nette/utils/src/Utils/Helpers.php',
    'Nette\\Utils\\Html' => $baseDir . '/nette/utils/src/Utils/Html.php',
    'Nette\\Utils\\IHtmlString' => $baseDir . '/nette/utils/src/compatibility.php',
    'Nette\\Utils\\Image' => $baseDir . '/nette/utils/src/Utils/Image.php',
    'Nette\\Utils\\ImageColor' => $baseDir . '/nette/utils/src/Utils/ImageColor.php',
    'Nette\\Utils\\ImageException' => $baseDir . '/nette/utils/src/Utils/exceptions.php',
    'Nette\\Utils\\Iterables' => $baseDir . '/nette/utils/src/Utils/Iterables.php',
    'Nette\\Utils\\Json' => $baseDir . '/nette/utils/src/Utils/Json.php',
    'Nette\\Utils\\ObjectHelpers' => $baseDir . '/nette/utils/src/Utils/ObjectHelpers.php',
    'Nette\\Utils\\Paginator' => $baseDir . '/nette/utils/src/Utils/Paginator.php',
    'Nette\\Utils\\Random' => $baseDir . '/nette/utils/src/Utils/Random.php',
    'Nette\\Utils\\Reflection' => $baseDir . '/nette/utils/src/Utils/Reflection.php',
    'Nette\\Utils\\ReflectionMethod' => $baseDir . '/nette/utils/src/Utils/ReflectionMethod.php',
    'Nette\\Utils\\Strings' => $baseDir . '/nette/utils/src/Utils/Strings.php',
    'Nette\\Utils\\Validators' => $baseDir . '/nette/utils/src/Utils/Validators.php',
    'PhpToken' => $baseDir . '/symfony/polyfill-php80/Resources/stubs/PhpToken.php',
    'Stringable' => $baseDir . '/symfony/polyfill-php80/Resources/stubs/Stringable.php',
    'UnhandledMatchError' => $baseDir . '/symfony/polyfill-php80/Resources/stubs/UnhandledMatchError.php',
    'ValueError' => $baseDir . '/symfony/polyfill-php80/Resources/stubs/ValueError.php',
];
$prefixes = [
    'Twig\\' => [
        $baseDir . '/twig/twig/src/',
    ],
    'League\\CommonMark\\' => [
        $baseDir . '/league/commonmark/src/',
    ],
    'Symfony\\Component\\Yaml\\' => [
        $baseDir . '/symfony/yaml/',
    ],
    'Nette\\' => [
        $baseDir . '/nette/utils/src/',
    ],
    'Symfony\\Polyfill\\Mbstring\\' => [
        $baseDir . '/symfony/polyfill-mbstring/',
    ],
    'Symfony\\Polyfill\\Ctype\\' => [
        $baseDir . '/symfony/polyfill-ctype/',
    ],
    'League\\Config\\' => [
        $baseDir . '/league/config/src/',
    ],
    'Psr\\EventDispatcher\\' => [
        $baseDir . '/psr/event-dispatcher/src/',
    ],
    'Symfony\\Polyfill\\Php80\\' => [
        $baseDir . '/symfony/polyfill-php80/',
    ],
    'Dflydev\\DotAccessData\\' => [
        $baseDir . '/dflydev/dot-access-data/src/',
    ],
];
spl_autoload_register(function ($class) use ($classmap, $prefixes) {
    if (isset($classmap[$class])) { $file = $classmap[$class]; if (is_file($file)) { require $file; return true; } }
    foreach ($prefixes as $prefix => $dirs) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $relativePath = str_replace('\\', '/', $relative) . '.php';
        foreach ($dirs as $dir) {
            $file = $dir . $relativePath;
            if (is_file($file)) { require $file; return true; }
        }
    }
    return false;
});

require_once $baseDir . '/../src/autoload.php';
